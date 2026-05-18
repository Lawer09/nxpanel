<?php

namespace App\Services\TrafficPlatform;

use App\Models\TrafficPlatformAccount;
use Illuminate\Support\Facades\DB;

class TrafficPlatformUsageService
{
    private const TABLE = 'traffic_platform_usage_stat';

    /**
     * 小时流量明细查询。
     */
    public function hourly(array $params): array
    {
        $query = DB::table(self::TABLE);
        $this->applyCommonFilters($query, $params);

        if (!empty($params['startTime'])) {
            $query->where('stat_time', '>=', $params['startTime']);
        }
        if (!empty($params['endTime'])) {
            $query->where('stat_time', '<=', $params['endTime']);
        }

        $query->orderByDesc('stat_time');

        $page = (int) ($params['page'] ?? 1);
        $pageSize = (int) ($params['pageSize'] ?? 50);
        $total = $query->count();

        $items = $query->offset(($page - 1) * $pageSize)
            ->limit($pageSize)
            ->get();

        $items = $this->normalizeDimensionFields($items);
        $items = $this->attachAccountName($items);

        return [
            'page' => $page,
            'pageSize' => $pageSize,
            'total' => $total,
            'data' => $items,
        ];
    }

    /**
     * 日流量汇总查询。
     */
    public function daily(array $params): array
    {
        $query = DB::table(self::TABLE)
            ->selectRaw('
                stat_date,
                platform_account_id,
                platform_code,
                COALESCE(external_uid, "") AS external_uid,
                external_username,
                COALESCE(geo, "") AS geo,
                COALESCE(region, "") AS region,
                SUM(traffic_bytes) AS traffic_bytes,
                SUM(traffic_mb) AS traffic_mb,
                ROUND(SUM(traffic_mb) / 1024, 6) AS traffic_gb
            ')
            ->groupByRaw('stat_date, platform_account_id, platform_code, COALESCE(external_uid, ""), external_username, COALESCE(geo, ""), COALESCE(region, "")')
            ->orderByDesc('stat_date');

        $this->applyCommonFilters($query, $params);

        if (!empty($params['startDate'])) {
            $query->where('stat_date', '>=', $params['startDate']);
        }
        if (!empty($params['endDate'])) {
            $query->where('stat_date', '<=', $params['endDate']);
        }

        $page = (int) ($params['page'] ?? 1);
        $pageSize = (int) ($params['pageSize'] ?? 50);

        $countQuery = DB::table(DB::raw("({$query->toSql()}) as sub"))->mergeBindings($query);
        $total = $countQuery->count();

        $items = $query->offset(($page - 1) * $pageSize)
            ->limit($pageSize)
            ->get();

        $items = $this->normalizeDimensionFields($items);
        $items = $this->attachAccountName($items);

        return [
            'page' => $page,
            'pageSize' => $pageSize,
            'total' => $total,
            'data' => $items,
        ];
    }

    /**
     * 月流量汇总查询。
     */
    public function monthly(array $params): array
    {
        $query = DB::table(self::TABLE)
            ->selectRaw("DATE_FORMAT(stat_date, '%Y-%m') AS stat_month, platform_account_id, platform_code, COALESCE(external_uid, '') AS external_uid, external_username, SUM(traffic_bytes) AS traffic_bytes, SUM(traffic_mb) AS traffic_mb, ROUND(SUM(traffic_mb) / 1024, 6) AS traffic_gb")
            ->groupByRaw("DATE_FORMAT(stat_date, '%Y-%m'), platform_account_id, platform_code, COALESCE(external_uid, ''), external_username")
            ->orderByDesc('stat_month');

        $this->applyCommonFilters($query, $params);

        if (!empty($params['startMonth'])) {
            $query->where('stat_date', '>=', $params['startMonth'] . '-01');
        }
        if (!empty($params['endMonth'])) {
            $endDate = date('Y-m-t', strtotime($params['endMonth'] . '-01'));
            $query->where('stat_date', '<=', $endDate);
        }

        $page = (int) ($params['page'] ?? 1);
        $pageSize = (int) ($params['pageSize'] ?? 50);

        $countQuery = DB::table(DB::raw("({$query->toSql()}) as sub"))->mergeBindings($query);
        $total = $countQuery->count();

        $items = $query->offset(($page - 1) * $pageSize)
            ->limit($pageSize)
            ->get();

        $items = $this->normalizeDimensionFields($items);
        $items = $this->attachAccountName($items);

        return [
            'page' => $page,
            'pageSize' => $pageSize,
            'total' => $total,
            'data' => $items,
        ];
    }

    /**
     * 流量趋势查询。
     */
    public function trend(array $params): array
    {
        $dimension = $params['dimension'] ?? 'day';

        $query = DB::table(self::TABLE);
        $this->applyCommonFilters($query, $params);

        if (!empty($params['startDate'])) {
            $query->where('stat_date', '>=', $params['startDate']);
        }
        if (!empty($params['endDate'])) {
            $query->where('stat_date', '<=', $params['endDate']);
        }

        switch ($dimension) {
            case 'hour':
                $query->selectRaw("DATE_FORMAT(stat_time, '%Y-%m-%d %H:00:00') AS time, SUM(traffic_mb) AS traffic_mb, ROUND(SUM(traffic_mb) / 1024, 6) AS traffic_gb")
                    ->groupByRaw("DATE_FORMAT(stat_time, '%Y-%m-%d %H:00:00')")
                    ->orderBy('time');
                break;
            case 'month':
                $query->selectRaw("DATE_FORMAT(stat_date, '%Y-%m') AS time, SUM(traffic_mb) AS traffic_mb, ROUND(SUM(traffic_mb) / 1024, 6) AS traffic_gb")
                    ->groupByRaw("DATE_FORMAT(stat_date, '%Y-%m')")
                    ->orderBy('time');
                break;
            default:
                $query->selectRaw("stat_date AS time, SUM(traffic_mb) AS traffic_mb, ROUND(SUM(traffic_mb) / 1024, 6) AS traffic_gb")
                    ->groupBy('stat_date')
                    ->orderBy('stat_date');
                break;
        }

        return [
            'data' => $query->get(),
        ];
    }

    /**
     * 流量排行查询。
     */
    public function ranking(array $params): array
    {
        $rankBy = $params['rankBy'] ?? 'account';
        $limit = (int) ($params['limit'] ?? 20);

        $query = DB::table(self::TABLE);

        if (!empty($params['platformCode'])) {
            $query->where('platform_code', $params['platformCode']);
        }
        if (!empty($params['startDate'])) {
            $query->where('stat_date', '>=', $params['startDate']);
        }
        if (!empty($params['endDate'])) {
            $query->where('stat_date', '<=', $params['endDate']);
        }

        switch ($rankBy) {
            case 'external_uid':
                $query->selectRaw('platform_account_id, platform_code, COALESCE(external_uid, "") AS external_uid, external_username, SUM(traffic_mb) AS traffic_mb, ROUND(SUM(traffic_mb) / 1024, 6) AS traffic_gb')
                    ->groupByRaw('platform_account_id, platform_code, COALESCE(external_uid, ""), external_username');
                break;
            case 'geo':
                $query->selectRaw('COALESCE(geo, "") AS geo, COALESCE(region, "") AS region, SUM(traffic_mb) AS traffic_mb, ROUND(SUM(traffic_mb) / 1024, 6) AS traffic_gb')
                    ->groupByRaw('COALESCE(geo, ""), COALESCE(region, "")');
                break;
            default:
                $query->selectRaw('platform_account_id, platform_code, SUM(traffic_mb) AS traffic_mb, ROUND(SUM(traffic_mb) / 1024, 6) AS traffic_gb')
                    ->groupBy('platform_account_id', 'platform_code');
                break;
        }

        $data = $query->orderByDesc('traffic_mb')
            ->limit($limit)
            ->get();

        $data = $this->normalizeDimensionFields($data);
        if (in_array($rankBy, ['account', 'external_uid'], true)) {
            $data = $this->attachAccountName($data);
        }

        return [
            'data' => $data,
        ];
    }

    /**
     * 应用公共筛选条件。
     */
    private function applyCommonFilters($query, array $params): void
    {
        if (!empty($params['platformCode'])) {
            $query->where('platform_code', $params['platformCode']);
        }
        if (!empty($params['accountId'])) {
            $query->where('platform_account_id', $params['accountId']);
        }
        if (array_key_exists('externalUid', $params)) {
            $query->whereRaw('COALESCE(external_uid, "") = ?', [$this->normalizeDimensionValue($params['externalUid'])]);
        }
        if (array_key_exists('geo', $params)) {
            $query->whereRaw('COALESCE(geo, "") = ?', [$this->normalizeDimensionValue($params['geo'])]);
        }
    }

    private function normalizeDimensionValue($value): string
    {
        if ($value === null) {
            return '';
        }

        return trim((string) $value);
    }

    private function normalizeDimensionFields($items)
    {
        return collect($items)->map(function ($row) {
            $arr = (array) $row;
            if (array_key_exists('external_uid', $arr)) {
                $arr['external_uid'] = $this->normalizeDimensionValue($arr['external_uid']);
            }
            if (array_key_exists('geo', $arr)) {
                $arr['geo'] = $this->normalizeDimensionValue($arr['geo']);
            }
            if (array_key_exists('region', $arr)) {
                $arr['region'] = $this->normalizeDimensionValue($arr['region']);
            }
            return (object) $arr;
        });
    }

    /**
     * 为结果集补充 account_name。
     */
    private function attachAccountName($items)
    {
        $accountIds = collect($items)->pluck('platform_account_id')->unique()->filter()->values();
        if ($accountIds->isEmpty()) {
            return $items;
        }

        $accountMap = TrafficPlatformAccount::whereIn('id', $accountIds)
            ->pluck('account_name', 'id');

        return collect($items)->map(function ($row) use ($accountMap) {
            $row = (array) $row;
            $row['account_name'] = $accountMap[$row['platform_account_id'] ?? 0] ?? '';
            return (object) $row;
        });
    }
}
