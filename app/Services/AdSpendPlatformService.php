<?php

namespace App\Services;

use App\Models\AdSpendPlatformAccount;
use Illuminate\Support\Facades\Http;

class AdSpendPlatformService
{
    private const TOKEN_CACHE_TTL_SECONDS = 3600;

    public function queryDaily(array $payload): array
    {
        $dateFrom = $payload['dateFrom'] ?? now()->subDays(1)->toDateString();
        $dateTo = $payload['dateTo'] ?? now()->toDateString();
        $groupBy = is_array($payload['groupBy'] ?? null) ? $payload['groupBy'] : [];
        $filters = is_array($payload['filters'] ?? null) ? $payload['filters'] : [];
        $page = (int) ($payload['page'] ?? 1);
        $pageSize = (int) ($payload['pageSize'] ?? 50);

        $query = \App\Models\AdSpendDailyReport::query()
            ->leftJoin('ad_spend_platform_accounts as a', 'a.id', '=', 'ad_spend_platform_daily_reports.platform_account_id')
            ->whereBetween('ad_spend_platform_daily_reports.report_date', [$dateFrom, $dateTo]);

        $this->applyWhereIn($query, 'ad_spend_platform_daily_reports.platform_code', $filters['platformCodes'] ?? null);
        $this->applyWhereIn($query, 'ad_spend_platform_daily_reports.platform_account_id', $filters['accountIds'] ?? null);
        $this->applyWhereIn($query, 'ad_spend_platform_daily_reports.project_code', $filters['projectCodes'] ?? null);
        $this->applyWhereIn($query, 'ad_spend_platform_daily_reports.country', $filters['countries'] ?? null);

        if (!empty($groupBy)) {
            $groupColumns = $this->normalizeDailyGroupBy($groupBy);
            if (empty($groupColumns)) {
                $groupColumns = ['ad_spend_platform_daily_reports.report_date'];
            }

            $selects = [];
            foreach ($groupColumns as $column) {
                if ($column === 'ad_spend_platform_daily_reports.report_date') {
                    $selects[] = 'ad_spend_platform_daily_reports.report_date as date';
                } else {
                    $selects[] = $column;
                }
            }

            if (in_array('ad_spend_platform_daily_reports.platform_account_id', $groupColumns, true)) {
                $selects[] = 'a.account_name';
                $groupColumns[] = 'a.account_name';
            }

            $query->selectRaw(
                implode(', ', $selects)
                . ', SUM(ad_spend_platform_daily_reports.impressions) as impressions'
                . ', SUM(ad_spend_platform_daily_reports.clicks) as clicks'
                . ', SUM(ad_spend_platform_daily_reports.spend) as spend'
                . ', ROUND(SUM(ad_spend_platform_daily_reports.clicks) / NULLIF(SUM(ad_spend_platform_daily_reports.impressions), 0) * 100, 6) as ctr'
                . ', ROUND(SUM(ad_spend_platform_daily_reports.spend) / NULLIF(SUM(ad_spend_platform_daily_reports.impressions), 0) * 1000, 6) as cpm'
                . ', ROUND(SUM(ad_spend_platform_daily_reports.spend) / NULLIF(SUM(ad_spend_platform_daily_reports.clicks), 0), 6) as cpc'
            );
            $query->groupBy($groupColumns);
            $query->orderByDesc('spend');
        } else {
            $query->select([
                'ad_spend_platform_daily_reports.id',
                'ad_spend_platform_daily_reports.report_date as date',
                'ad_spend_platform_daily_reports.platform_account_id',
                'ad_spend_platform_daily_reports.platform_code',
                'a.account_name',
                'ad_spend_platform_daily_reports.project_code',
                'ad_spend_platform_daily_reports.country',
                'ad_spend_platform_daily_reports.impressions',
                'ad_spend_platform_daily_reports.clicks',
                'ad_spend_platform_daily_reports.spend',
                'ad_spend_platform_daily_reports.ctr',
                'ad_spend_platform_daily_reports.cpm',
                'ad_spend_platform_daily_reports.cpc',
                'ad_spend_platform_daily_reports.updated_at',
            ]);
            $query->orderByDesc('ad_spend_platform_daily_reports.report_date')
                ->orderByDesc('ad_spend_platform_daily_reports.id');
        }

        $result = $query->paginate($pageSize, ['*'], 'page', $page);

        return [
            'data' => $result->items(),
            'total' => $result->total(),
            'page' => $result->currentPage(),
            'pageSize' => $result->perPage(),
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'groupBy' => $groupBy,
        ];
    }

    public function login(AdSpendPlatformAccount $account, bool $forceRefresh = false): string
    {
        if (!$forceRefresh) {
            if (!empty($account->access_token) && $this->tokenAvailable($account)) {
                return (string) $account->access_token;
            }
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
        if (!$expiredAt) {
            $expiredAt = now()->addSeconds(self::TOKEN_CACHE_TTL_SECONDS)->format('Y-m-d H:i:s');
        }

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
            'dims=group_name',
            'dims=group_id',
            'dims=country',
            'startDate=' . urlencode($startDate),
            'endDate=' . urlencode($endDate),
            'current=' . $current,
            'size=' . $size,
        ]);
        $url = rtrim((string) $account->base_url, '/') . '/api/fb/report/day/overall?' . $queryString;

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

    private function normalizeDailyGroupBy(array $groupBy): array
    {
        $allowed = ['date', 'platform_code', 'platform_account_id', 'project_code', 'country'];
        $columnMap = [
            'date' => 'ad_spend_platform_daily_reports.report_date',
            'platform_code' => 'ad_spend_platform_daily_reports.platform_code',
            'platform_account_id' => 'ad_spend_platform_daily_reports.platform_account_id',
            'project_code' => 'ad_spend_platform_daily_reports.project_code',
            'country' => 'ad_spend_platform_daily_reports.country',
        ];
        $normalized = [];

        foreach ($groupBy as $field) {
            if (!is_string($field)) {
                continue;
            }

            $trimmed = trim($field);
            if ($trimmed !== '' && in_array($trimmed, $allowed, true)) {
                $column = $columnMap[$trimmed] ?? null;
                if ($column !== null && !in_array($column, $normalized, true)) {
                    $normalized[] = $column;
                }
            }
        }

        return $normalized;
    }

    private function applyWhereIn($query, string $column, ?array $values): void
    {
        if (empty($values)) {
            return;
        }

        $safeValues = array_values(array_filter($values, function ($value) {
            return $value !== null && $value !== '';
        }));

        if (!empty($safeValues)) {
            $query->whereIn($column, $safeValues);
        }
    }
}
