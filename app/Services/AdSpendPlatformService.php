<?php

namespace App\Services;

use App\Models\AdSpendPlatformAccount;
use Illuminate\Support\Facades\Http;

class AdSpendPlatformService
{
    public function login(AdSpendPlatformAccount $account, bool $forceRefresh = false): string
    {
        if (!$forceRefresh && !empty($account->access_token) && $this->tokenAvailable($account)) {
            return $account->access_token;
        }

        $baseUrl = rtrim((string) $account->base_url, '/');
        $response = Http::timeout(20)
            ->acceptJson()
            ->post($baseUrl . '/api/auth/login', [
                'username' => $account->username,
                'password' => $account->password,
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException('登录失败: ' . $response->body());
        }

        $body = $response->json();
        $token = $this->extractToken($body);
        if ($token === '') {
            throw new \RuntimeException('登录失败: 未返回 token');
        }

        $expiredAt = $this->extractTokenExpiredAt($body);
        $account->update([
            'access_token' => $token,
            'token_expired_at' => $expiredAt,
        ]);

        return $token;
    }

    public function fetchDailyRecords(AdSpendPlatformAccount $account, string $startDate, string $endDate, int $size = 200): array
    {
        $size = max(1, min(500, $size));
        $records = [];
        $current = 1;
        $pages = 1;

        while ($current <= $pages) {
            $body = $this->requestReportPage($account, $startDate, $endDate, $current, $size);

            $pageRecords = $this->extractRecords($body);
            if (!empty($pageRecords)) {
                $records = array_merge($records, $pageRecords);
            }

            $pages = $this->extractPages($body);
            $current++;
        }

        return $records;
    }

    private function requestReportPage(AdSpendPlatformAccount $account, string $startDate, string $endDate, int $current, int $size): array
    {
        $queryString = implode('&', [
            'objectName=account',
            'dims=date',
            'dims=group_id',
            'dims=country',
            'startDate=' . urlencode($startDate),
            'endDate=' . urlencode($endDate),
            'current=' . $current,
            'size=' . $size,
        ]);
        $url = rtrim((string) $account->base_url, '/') . '/api/ads/report/day/overall?' . $queryString;

        $token = $this->login($account, false);
        $response = Http::timeout(30)
            ->acceptJson()
            ->withToken($token)
            ->get($url);

        if ($response->status() === 401) {
            $token = $this->login($account, true);
            $response = Http::timeout(30)
                ->acceptJson()
                ->withToken($token)
                ->get($url);
        }

        if (!$response->successful()) {
            throw new \RuntimeException('拉取报表失败: ' . $response->body());
        }

        $body = $response->json();
        return is_array($body) ? $body : [];
    }

    private function tokenAvailable(AdSpendPlatformAccount $account): bool
    {
        if (!$account->token_expired_at) {
            return true;
        }

        return $account->token_expired_at->isFuture();
    }

    private function extractToken(array $body): string
    {
        $candidates = [
            $body['token'] ?? null,
            $body['accessToken'] ?? null,
            $body['access_token'] ?? null,
            $body['data']['token'] ?? null,
            $body['data']['accessToken'] ?? null,
            $body['data']['access_token'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return '';
    }

    private function extractTokenExpiredAt(array $body): ?string
    {
        $expiresIn = $body['expiresIn'] ?? $body['expires_in'] ?? $body['data']['expiresIn'] ?? $body['data']['expires_in'] ?? null;
        if (is_numeric($expiresIn) && (int) $expiresIn > 0) {
            return now()->addSeconds((int) $expiresIn)->format('Y-m-d H:i:s');
        }

        $expiredAt = $body['expiredAt'] ?? $body['expired_at'] ?? $body['data']['expiredAt'] ?? $body['data']['expired_at'] ?? null;
        if (is_string($expiredAt) && $expiredAt !== '') {
            return $expiredAt;
        }

        return null;
    }

    private function extractRecords(array $body): array
    {
        $candidates = [
            $body['records'] ?? null,
            $body['data']['records'] ?? null,
            $body['data']['list'] ?? null,
            $body['list'] ?? null,
            $body['result']['records'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_array($candidate)) {
                return $candidate;
            }
        }

        return [];
    }

    private function extractPages(array $body): int
    {
        $candidates = [
            $body['pages'] ?? null,
            $body['data']['pages'] ?? null,
            $body['result']['pages'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_numeric($candidate)) {
                return max(1, (int) $candidate);
            }
            if (is_string($candidate) && $candidate !== '' && ctype_digit($candidate)) {
                return max(1, (int) $candidate);
            }
        }

        return 1;
    }
}
