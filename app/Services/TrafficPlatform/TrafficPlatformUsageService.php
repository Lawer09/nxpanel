<?php

namespace App\Services\TrafficPlatform;

use App\Models\TrafficPlatformAccount;
use Illuminate\Support\Facades\DB;

class TrafficPlatformUsageService
{
    private const HOURLY_TABLE = 'traffic_platform_usage_hourly';
    private const DAILY_TABLE = 'traffic_platform_usage_daily';

    /**
     * 查询小时流量明细。
     */
    public function hourly(array $params): array
    {
        $query = DB::table(self::HOURLY_TABLE)
            ->selectRaw('
                report_hour,
                report_date,
                platform_account_id,
                platform_code,
                COALESCE(external_uid, "") AS external_uid,
                external_username,
                COALESCE(geo, "") AS geo,
                COALESCE(region, "") AS region,
                traffic_bytes,
                traffic_mb,
                baseline_snapshot_time,
                current_snapshot_time,
                is_anomaly,
                anomaly_reason
            ');
        $this->applyCommonFilters($query, $params, false);

        if (!empty($params['startTime'])) {
            $query->where('report_hour', '>=', $params['startTime']);
        }
        if (!empty($params['endTime'])) {
            $query->where('report_hour', '<=', $params['endTime']);
        }

        $query->orderByDesc('report_hour');

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
     * 查询日累计流量明细。
     */
    public function daily(array $params): array
    {
        $query = DB::table(self::DAILY_TABLE)
            ->selectRaw('
                report_date,
                platform_account_id,
                platform_code,
                COALESCE(external_uid, "") AS external_uid,
                external_username,
                COALESCE(geo, "") AS geo,
                COALESCE(region, "") AS region,
                traffic_bytes_cum AS traffic_bytes,
                traffic_mb_cum AS traffic_mb,
                ROUND(traffic_mb_cum / 1024, 6) AS traffic_gb,
                snapshot_time
            ')
            ->orderByDesc('report_date');

        $this->applyCommonFilters($query, $params, true);

        if (!empty($params['startDate'])) {
            $query->where('report_date', '>=', $params['startDate']);
        }
        if (!empty($params['endDate'])) {
            $query->where('report_date', '<=', $params['endDate']);
        }

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
     * 查询月累计流量汇总。
     */
    public function monthly(array $params): array
    {
        $query = DB::table(self::DAILY_TABLE)
            ->selectRaw("
                DATE_FORMAT(report_date, '%Y-%m') AS report_month,
                platform_account_id,
                platform_code,
                COALESCE(external_uid, '') AS external_uid,
                external_username,
                SUM(traffic_bytes_cum) AS traffic_bytes,
                SUM(traffic_mb_cum) AS traffic_mb,
                ROUND(SUM(traffic_mb_cum) / 1024, 6) AS traffic_gb
            ")
            ->groupByRaw("DATE_FORMAT(report_date, '%Y-%m'), platform_account_id, platform_code, COALESCE(external_uid, ''), external_username")
            ->orderByDesc('report_month');

        $this->applyCommonFilters($query, $params, false);

        if (!empty($params['startMonth'])) {
            $query->where('report_date', '>=', $params['startMonth'] . '-01');
        }
        if (!empty($params['endMonth'])) {
            $endDate = date('Y-m-t', strtotime($params['endMonth'] . '-01'));
            $query->where('report_date', '<=', $endDate);
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
     * 查询流量趋势。
     */
    public function trend(array $params): array
    {
        $dimension = $params['dimension'] ?? 'day';

        switch ($dimension) {
            case 'hour':
                $query = DB::table(self::HOURLY_TABLE);
                $this->applyCommonFilters($query, $params, false);

                if (!empty($params['startDate'])) {
                    $query->where('report_date', '>=', $params['startDate']);
                }
                if (!empty($params['endDate'])) {
                    $query->where('report_date', '<=', $params['endDate']);
                }

                $query->selectRaw('report_hour AS time, SUM(traffic_mb) AS traffic_mb, ROUND(SUM(traffic_mb) / 1024, 6) AS traffic_gb')
                    ->groupBy('report_hour')
                    ->orderBy('time');
                break;
            case 'month':
                $query = DB::table(self::DAILY_TABLE);
                $this->applyCommonFilters($query, $params, false);

                if (!empty($params['startDate'])) {
                    $query->where('report_date', '>=', $params['startDate']);
                }
                if (!empty($params['endDate'])) {
                    $query->where('report_date', '<=', $params['endDate']);
                }

                $query->selectRaw("DATE_FORMAT(report_date, '%Y-%m') AS time, SUM(traffic_mb_cum) AS traffic_mb, ROUND(SUM(traffic_mb_cum) / 1024, 6) AS traffic_gb")
                    ->groupByRaw("DATE_FORMAT(report_date, '%Y-%m')")
                    ->orderBy('time');
                break;
            default:
                $query = DB::table(self::DAILY_TABLE);
                $this->applyCommonFilters($query, $params, false);

                if (!empty($params['startDate'])) {
                    $query->where('report_date', '>=', $params['startDate']);
                }
                if (!empty($params['endDate'])) {
                    $query->where('report_date', '<=', $params['endDate']);
                }

                $query->selectRaw('report_date AS time, SUM(traffic_mb_cum) AS traffic_mb, ROUND(SUM(traffic_mb_cum) / 1024, 6) AS traffic_gb')
                    ->groupBy('report_date')
                    ->orderBy('report_date');
                break;
        }

        return [
            'data' => $query->get(),
        ];
    }

    /**
     * 查询流量排行。
     */
    public function ranking(array $params): array
    {
        $rankBy = $params['rankBy'] ?? 'account';
        $limit = (int) ($params['limit'] ?? 20);

        $query = DB::table(self::DAILY_TABLE);

        if (!empty($params['platformCode'])) {
            $query->where('platform_code', $params['platformCode']);
        }
        if (!empty($params['startDate'])) {
            $query->where('report_date', '>=', $params['startDate']);
        }
        if (!empty($params['endDate'])) {
            $query->where('report_date', '<=', $params['endDate']);
        }

        switch ($rankBy) {
            case 'external_uid':
                $query->selectRaw('platform_account_id, platform_code, COALESCE(external_uid, "") AS external_uid, external_username, SUM(traffic_mb_cum) AS traffic_mb, ROUND(SUM(traffic_mb_cum) / 1024, 6) AS traffic_gb')
                    ->groupByRaw('platform_account_id, platform_code, COALESCE(external_uid, ""), external_username');
                break;
            case 'geo':
                $query->selectRaw('COALESCE(geo, "") AS geo, COALESCE(region, "") AS region, SUM(traffic_mb_cum) AS traffic_mb, ROUND(SUM(traffic_mb_cum) / 1024, 6) AS traffic_gb')
                    ->groupByRaw('COALESCE(geo, ""), COALESCE(region, "")');
                break;
            default:
                $query->selectRaw('platform_account_id, platform_code, SUM(traffic_mb_cum) AS traffic_mb, ROUND(SUM(traffic_mb_cum) / 1024, 6) AS traffic_gb')
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
    private function applyCommonFilters($query, array $params, bool $supportsGeo = true): void
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
        if ($supportsGeo && array_key_exists('geo', $params)) {
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
