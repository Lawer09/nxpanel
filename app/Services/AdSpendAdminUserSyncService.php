<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class AdSpendAdminUserSyncService
{
    private const LOGIN_CACHE_TTL_SECONDS = 3600;
    private const ADMIN_TOKEN_CACHE_TTL_SECONDS = 3600;

    /**
     * Whether remote admin user synchronization is enabled.
     */
    public function isEnabled(): bool
    {
        return filter_var(
            config('services.ad_spend_admin_user_sync.enabled', false),
            FILTER_VALIDATE_BOOL
        );
    }

    /**
     * Ensure a remote ad-spend platform user exists for the local admin account.
     */
    public function ensureAdminUser(string $username, string $password): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $config = $this->resolveProvisionConfig();

        if ($this->findUserByUsername($config, $username) !== null) {
            return;
        }

        $this->createUser($config, $username, $password);
    }

    /**
     * Login to the ad-spend platform as the local admin user and return remote data.
     */
    public function loginUser(string $username, string $password): ?array
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $response = Http::timeout($this->timeoutSeconds())
            ->acceptJson()
            ->post($this->baseUrl() . '/api/auth/login', [
                'username' => $username,
                'password' => $password,
            ]);

        $body = $this->assertSuccessfulRemoteResponse($response, 'login user');
        $data = $body['data'] ?? null;

        return is_array($data) ? $data : null;
    }

    /**
     * Cache remote login data so token refresh can return it without a password.
     */
    public function rememberUserLoginData(int $userId, ?array $loginData): ?array
    {
        if (!$this->isEnabled() || empty($loginData)) {
            return $loginData;
        }

        Cache::put(
            $this->loginCacheKey($userId),
            $loginData,
            now()->addSeconds(self::LOGIN_CACHE_TTL_SECONDS)
        );

        return $loginData;
    }

    /**
     * Return cached remote login data for a local admin user.
     */
    public function cachedUserLoginData(int $userId): ?array
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $loginData = Cache::get($this->loginCacheKey($userId));

        return is_array($loginData) ? $loginData : null;
    }

    /**
     * Cache a client-held remote token as a minimal remote login payload.
     */
    public function rememberTokenLoginData(int $userId, string $token): array
    {
        $loginData = ['token' => $token];
        $this->rememberUserLoginData($userId, $loginData);

        return $loginData;
    }

    private function findUserByUsername(array $config, string $username): ?array
    {
        $response = $this->sendWithAdminToken($config, function (string $token) use ($config, $username) {
            return Http::timeout($this->timeoutSeconds())
                ->acceptJson()
                ->withToken($token)
                ->withHeaders(['Cookie' => 'Authorization=' . $token])
                ->get($config['base_url'] . '/api/sys/user/page', [
                    'current' => 1,
                    'size' => 20,
                    'username' => $username,
                ]);
        });

        $body = $this->assertSuccessfulRemoteResponse($response, 'query user');
        foreach ($this->extractRecords($body) as $record) {
            if (is_array($record) && (string) ($record['username'] ?? '') === $username) {
                return $record;
            }
        }

        return null;
    }

    private function createUser(array $config, string $username, string $password): void
    {
        $payload = [
            'username' => $username,
            'password' => $password,
            'nickname' => $username,
            'teamId' => $config['team_id'],
            'status' => 1,
            'roleIds' => $config['role_ids'],
        ];

        $response = $this->sendWithAdminToken($config, function (string $token) use ($config, $payload) {
            return Http::timeout($this->timeoutSeconds())
                ->acceptJson()
                ->withToken($token)
                ->withHeaders(['Cookie' => 'Authorization=' . $token])
                ->post($config['base_url'] . '/api/sys/user', $payload);
        });

        $this->assertSuccessfulRemoteResponse($response, 'create user');
    }

    private function sendWithAdminToken(array $config, callable $send): Response
    {
        $token = $this->adminToken($config);
        $response = $send($token);

        if ($response->status() !== 401) {
            return $response;
        }

        $token = $this->adminToken($config, true);

        return $send($token);
    }

    private function adminToken(array $config, bool $forceRefresh = false): string
    {
        $cacheKey = $this->adminTokenCacheKey($config);

        if (!$forceRefresh) {
            $token = Cache::get($cacheKey);
            if (is_string($token) && trim($token) !== '') {
                return $token;
            }
        }

        Cache::forget($cacheKey);

        $response = Http::timeout($this->timeoutSeconds())
            ->acceptJson()
            ->post($config['base_url'] . '/api/auth/login', [
                'username' => $config['admin_username'],
                'password' => $config['admin_password'],
            ]);

        $body = $this->assertSuccessfulRemoteResponse($response, 'admin login');
        $token = $body['data']['token'] ?? null;

        if (!is_string($token) || trim($token) === '') {
            throw new \RuntimeException('ad spend platform admin login missing token');
        }

        Cache::put($cacheKey, $token, now()->addSeconds(self::ADMIN_TOKEN_CACHE_TTL_SECONDS));

        return $token;
    }

    private function resolveProvisionConfig(): array
    {
        $config = [
            'base_url' => $this->baseUrl(),
            'admin_username' => trim((string) config('services.ad_spend_admin_user_sync.admin_username', '')),
            'admin_password' => (string) config('services.ad_spend_admin_user_sync.admin_password', ''),
            'team_id' => trim((string) config('services.ad_spend_admin_user_sync.team_id', '')),
            'role_ids' => $this->roleIds(),
        ];

        if (
            $config['admin_username'] === ''
            || $config['admin_password'] === ''
            || $config['team_id'] === ''
            || empty($config['role_ids'])
        ) {
            throw new \InvalidArgumentException('ad spend admin user sync config is incomplete');
        }

        return $config;
    }

    private function assertSuccessfulRemoteResponse(Response $response, string $action): array
    {
        if (!$response->successful()) {
            throw new \RuntimeException('ad spend platform ' . $action . ' failed');
        }

        $body = $response->json();
        $body = is_array($body) ? $body : [];

        if (($body['success'] ?? true) === false) {
            throw new \RuntimeException('ad spend platform ' . $action . ' returned error');
        }

        return $body;
    }

    private function extractRecords(array $body): array
    {
        $records = $body['data']['records'] ?? $body['records'] ?? [];

        return is_array($records) ? $records : [];
    }

    private function roleIds(): array
    {
        $configured = config('services.ad_spend_admin_user_sync.role_ids', []);
        if (is_string($configured)) {
            $configured = explode(',', $configured);
        }

        if (!is_array($configured)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn($roleId) => trim((string) $roleId),
            $configured
        )));
    }

    private function timeoutSeconds(): int
    {
        return max(1, (int) config('services.ad_spend_admin_user_sync.timeout_seconds', 20));
    }

    private function baseUrl(): string
    {
        $baseUrl = rtrim((string) config('services.ad_spend_admin_user_sync.base_url', ''), '/');
        if ($baseUrl === '') {
            throw new \InvalidArgumentException('ad spend platform base_url is not configured');
        }

        return $baseUrl;
    }

    private function loginCacheKey(int $userId): string
    {
        return 'ad_spend_platform_login:' . $userId;
    }

    private function adminTokenCacheKey(array $config): string
    {
        return 'ad_spend_platform_admin_token:' . sha1($config['base_url'] . '|' . $config['admin_username']);
    }
}
