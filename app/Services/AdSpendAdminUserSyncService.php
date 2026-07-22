<?php

namespace App\Services;

use App\Models\AdSpendPlatformAccount;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class AdSpendAdminUserSyncService
{
    private const LOGIN_CACHE_TTL_SECONDS = 3600;

    public function __construct(private readonly AdSpendPlatformService $platformService)
    {
    }

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

        $account = $this->resolveAccount();
        $defaults = $this->resolveCreateDefaults();

        if ($this->findUserByUsername($account, $username) !== null) {
            return;
        }

        $this->createUser($account, $username, $password, $defaults);
    }

    /**
     * Login to the ad-spend platform as the local admin user and return remote data.
     */
    public function loginUser(string $username, string $password): ?array
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $account = $this->resolveAccount();
        $response = Http::timeout($this->timeoutSeconds())
            ->acceptJson()
            ->post($this->baseUrl($account) . '/api/auth/login', [
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

    private function findUserByUsername(AdSpendPlatformAccount $account, string $username): ?array
    {
        $response = $this->sendWithAdminToken($account, function (string $token) use ($account, $username) {
            return Http::timeout($this->timeoutSeconds())
                ->acceptJson()
                ->withToken($token)
                ->withHeaders(['Cookie' => 'Authorization=' . $token])
                ->get($this->baseUrl($account) . '/api/sys/user/page', [
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

    private function createUser(AdSpendPlatformAccount $account, string $username, string $password, array $defaults): void
    {
        $payload = [
            'username' => $username,
            'password' => $password,
            'nickname' => $username,
            'teamId' => $defaults['team_id'],
            'status' => 1,
            'roleIds' => $defaults['role_ids'],
        ];

        $response = $this->sendWithAdminToken($account, function (string $token) use ($account, $payload) {
            return Http::timeout($this->timeoutSeconds())
                ->acceptJson()
                ->withToken($token)
                ->withHeaders(['Cookie' => 'Authorization=' . $token])
                ->post($this->baseUrl($account) . '/api/sys/user', $payload);
        });

        $this->assertSuccessfulRemoteResponse($response, 'create user');
    }

    private function sendWithAdminToken(AdSpendPlatformAccount $account, callable $send): Response
    {
        $token = $this->platformService->login($account, false);
        $response = $send($token);

        if ($response->status() !== 401) {
            return $response;
        }

        $token = $this->platformService->login($account, true);

        return $send($token);
    }

    private function resolveAccount(): AdSpendPlatformAccount
    {
        $accountId = config('services.ad_spend_admin_user_sync.account_id');
        $query = AdSpendPlatformAccount::query()->where('enabled', 1);

        if ($accountId !== null && (string) $accountId !== '') {
            $query->whereKey((int) $accountId);
        } else {
            $platformCode = (string) config('services.ad_spend_admin_user_sync.platform_code', 'adsmakeup');
            $query->where('platform_code', $platformCode);
        }

        $account = $query->orderBy('id')->first();
        if (!$account || !$account->base_url || !$account->username || !$account->password) {
            throw new \InvalidArgumentException('ad spend admin user sync account is not configured');
        }

        return $account;
    }

    private function resolveCreateDefaults(): array
    {
        $teamId = trim((string) config('services.ad_spend_admin_user_sync.team_id', ''));
        $roleIds = $this->roleIds();

        if ($teamId === '' || empty($roleIds)) {
            throw new \InvalidArgumentException('ad spend admin user sync defaults are not configured');
        }

        return [
            'team_id' => $teamId,
            'role_ids' => $roleIds,
        ];
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

    private function baseUrl(AdSpendPlatformAccount $account): string
    {
        return rtrim((string) $account->base_url, '/');
    }

    private function loginCacheKey(int $userId): string
    {
        return 'ad_spend_platform_login:' . $userId;
    }
}
