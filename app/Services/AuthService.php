<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

class AuthService
{
    private const AID_TOKEN_CACHE_PREFIX = 'aid_login_auth_token:';
    private const PASSWORD_TOKEN_TTL_SECONDS = 31536000;
    private const AID_TOKEN_TTL_SECONDS = 604800;

    private User $user;

    public function __construct(User $user)
    {
        $this->user = $user;
    }

    public function generateAuthData(): array
    {
        return $this->buildAuthData($this->createBearerToken(self::PASSWORD_TOKEN_TTL_SECONDS));
    }

    /**
     * Generate AID login auth data, reusing the cached bearer token when it is still valid.
     */
    public function generateAidAuthData(): array
    {
        return $this->buildAuthData($this->resolveCachedAidBearerToken());
    }

    private function buildAuthData(string $bearerToken): array
    {
        $data = [
            'token' => $this->user->token,
            'auth_data' => $bearerToken,
            'is_admin' => $this->user->is_admin,
            'email' => $this->user->email,
            'nickname' => $this->user->nickname,
        ];

        if ($this->user->is_admin) {  
            $data['secure_path'] = admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key'))));  
        }  

        return $data;
    }

    private function createBearerToken(int $ttlSeconds): string
    {
        $token = $this->user->createToken(
            Str::random(20),
            ['*'],
            now()->addSeconds($ttlSeconds)
        );

        return $this->formatPlainTextToken($token->plainTextToken);
    }

    private function resolveCachedAidBearerToken(): string
    {
        $cacheKey = self::AID_TOKEN_CACHE_PREFIX . $this->user->id;

        try {
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                $plainToken = $cached['plain_token'] ?? null;
                $expiresAt = (int) ($cached['expires_at'] ?? 0);
                $accessToken = is_string($plainToken) ? $this->resolveValidCachedAidToken($plainToken, $expiresAt) : null;
                if ($accessToken) {
                    $this->refreshCachedAidToken($cacheKey, $plainToken, $accessToken);
                    return 'Bearer ' . $plainToken;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('AID auth token cache read failed', [
                'user_id' => $this->user->id,
                'error' => $e->getMessage(),
            ]);
        }

        $bearerToken = $this->createBearerToken(self::AID_TOKEN_TTL_SECONDS);
        $plainToken = str_replace('Bearer ', '', $bearerToken);
        $expiresAt = now()->addSeconds(self::AID_TOKEN_TTL_SECONDS);

        $this->putAidTokenCache($cacheKey, $plainToken, $expiresAt);

        return $bearerToken;
    }

    private function resolveValidCachedAidToken(string $plainToken, int $expiresAt): ?PersonalAccessToken
    {
        if ($expiresAt <= time()) {
            return null;
        }

        $accessToken = PersonalAccessToken::findToken($plainToken);
        if (!$accessToken) {
            return null;
        }

        if ($accessToken->tokenable_type !== User::class || (int) $accessToken->tokenable_id !== (int) $this->user->id) {
            return null;
        }

        return $accessToken->expires_at === null || $accessToken->expires_at->timestamp > time()
            ? $accessToken
            : null;
    }

    /**
     * Extend a valid cached AID token and its cache entry by the sliding seven-day window.
     */
    private function refreshCachedAidToken(string $cacheKey, string $plainToken, PersonalAccessToken $accessToken): void
    {
        $expiresAt = now()->addSeconds(self::AID_TOKEN_TTL_SECONDS);

        try {
            $accessToken->forceFill(['expires_at' => $expiresAt])->save();
        } catch (\Throwable $e) {
            Log::warning('AID auth token expiry refresh failed', [
                'user_id' => $this->user->id,
                'token_id' => $accessToken->id,
                'error' => $e->getMessage(),
            ]);
        }

        $this->putAidTokenCache($cacheKey, $plainToken, $expiresAt);
    }

    private function putAidTokenCache(string $cacheKey, string $plainToken, \DateTimeInterface $expiresAt): void
    {
        try {
            Cache::put($cacheKey, [
                'plain_token' => $plainToken,
                'expires_at' => $expiresAt->getTimestamp(),
            ], $expiresAt);
        } catch (\Throwable $e) {
            Log::warning('AID auth token cache write failed', [
                'user_id' => $this->user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function formatPlainTextToken(string $plainTextToken): string
    {
        $tokenParts = explode('|', $plainTextToken);

        return 'Bearer ' . ($tokenParts[1] ?? $tokenParts[0]);
    }

    public function getSessions(): array
    {
        return $this->user->tokens()->get()->toArray();
    }

    public function removeSession(string $sessionId): bool
    {
        $this->user->tokens()->where('id', $sessionId)->delete();
        return true;
    }

    public function removeAllSessions(): bool
    {
        $this->user->tokens()->delete();
        return true;
    }

    public static function findUserByBearerToken(string $bearerToken): ?User
    {
        $token = str_replace('Bearer ', '', $bearerToken);
        
        $accessToken = PersonalAccessToken::findToken($token);
        
        $tokenable = $accessToken?->tokenable;
        
        return $tokenable instanceof User ? $tokenable : null;
    }

    /**
     * 解密认证数据
     *
     * @param string $authorization
     * @return array|null 用户数据或null
     */
    public static function decryptAuthData(string $authorization): ?array
    {
        $user = self::findUserByBearerToken($authorization);
        
        if (!$user) {
            return null;
        }
        
        return [
            'id' => $user->id,
            'email' => $user->email,
            'is_admin' => (bool)$user->is_admin,
            'is_staff' => (bool)$user->is_staff
        ];
    }

    /**  
     * 清除除指定 token 外的所有 session  
     */  
    public function removeOtherSessions(int $currentTokenId): bool  
    {  
        $this->user->tokens()->where('id', '!=', $currentTokenId)->delete();  
        return true;  
    }
}
