<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class NodeSubReportService
{
    public const ALLOWED_SUB_TABLES = [
        'performance',
        'probe',
        'traffic',
        'server_detail',
        'main_aggregated',
    ];

    public const ALLOWED_GROUP_BY = [
        'date',
        'hour',
        'minute',
        'scope',
        'node_id',
        'server_id',
        'node_ip',
        'client_country',
        'platform',
        'client_isp',
        'app_id',
        'app_version',
        'status',
        'probe_stage',
        'error_code',
        'server_type',
        'year',
        'month',
        'day',
    ];

    public function query(array $payload): array
    {
        $subTable = (string) $payload['subTable'];
        $date = (string) $payload['date'];
        $hour = array_key_exists('hour', $payload) ? (int) $payload['hour'] : null;
        $minute = array_key_exists('minute', $payload) ? (int) $payload['minute'] : null;
        $groupBy = array_values(array_unique((array) ($payload['groupBy'] ?? [])));
        $filters = (array) ($payload['filters'] ?? []);
        $page = (int) ($payload['page'] ?? 1);
        $pageSize = (int) ($payload['pageSize'] ?? 50);
        $orderBy = is_string($payload['orderBy'] ?? null) ? $payload['orderBy'] : null;
        $orderDirection = $this->normalizeOrderDirection($payload['orderDirection'] ?? null);
        $includeExternal = (bool) ($filters['includeExternal'] ?? false);

        [$table, $alias, $timeMode, $recordAtMode, $metricMap] = $this->resolveMeta($subTable);
        $query = DB::table("{$table} as {$alias}");

        $this->applyTimeFilters($query, $alias, $date, $hour, $minute, $timeMode, $recordAtMode);

        if (!$includeExternal) {
            if (in_array($subTable, ['performance', 'probe', 'traffic', 'main_aggregated'], true)) {
                $query->where("{$alias}.node_id", '>', 0);
            }
            if ($subTable === 'server_detail') {
                $query->where("{$alias}.server_id", '>', 0);
            }
        }

        $this->applyCommonFilters($query, $alias, $subTable, $filters);

        $effectiveGroupBy = [];
        $selects = [];
        foreach ($groupBy as $dimension) {
            if ($this->isDimensionSupported($subTable, $dimension)) {
                $effectiveGroupBy[] = $dimension;
                $selects[] = "{$alias}.{$dimension} as {$dimension}";
            }
        }

        foreach ($metricMap as $metricAlias => $expr) {
            $selects[] = "{$expr} as {$metricAlias}";
        }

        if (empty($effectiveGroupBy)) {
            $query->selectRaw(implode(', ', $selects));
            $row = (array) ($query->first() ?? []);

            return [
                'data' => [$row],
                'total' => 1,
                'page' => 1,
                'pageSize' => 1,
                'subTable' => $subTable,
                'groupBy' => $effectiveGroupBy,
                'metricMap' => array_keys($metricMap),
                'date' => $date,
                'hour' => $hour,
                'minute' => $minute,
            ];
        }

        $query->selectRaw(implode(', ', $selects))->groupBy($effectiveGroupBy);

        $sortable = array_merge($effectiveGroupBy, array_keys($metricMap));
        if (is_string($orderBy) && in_array($orderBy, $sortable, true)) {
            $query->orderBy($orderBy, $orderDirection);
        } else {
            if (in_array('date', $effectiveGroupBy, true)) {
                $query->orderByDesc('date');
            }
            if (in_array('hour', $effectiveGroupBy, true)) {
                $query->orderByDesc('hour');
            }
            if (in_array('minute', $effectiveGroupBy, true)) {
                $query->orderByDesc('minute');
            }
        }

        $data = $query->paginate($pageSize, ['*'], 'page', $page);

        return [
            'data' => array_map(static fn($item) => (array) $item, $data->items()),
            'total' => $data->total(),
            'page' => $data->currentPage(),
            'pageSize' => $data->perPage(),
            'subTable' => $subTable,
            'groupBy' => $effectiveGroupBy,
            'metricMap' => array_keys($metricMap),
            'date' => $date,
            'hour' => $hour,
            'minute' => $minute,
        ];
    }

    private function resolveMeta(string $subTable): array
    {
        return match ($subTable) {
            'performance' => [
                'v2_node_performance_aggregated',
                't',
                'date_hour_minute',
                false,
                [
                    'row_count' => 'COUNT(*)',
                    'total_count' => 'SUM(t.total_count)',
                    'avg_delay_weighted' => 'ROUND(SUM(t.avg_delay * t.total_count) / NULLIF(SUM(t.total_count), 0), 2)',
                ],
            ],
            'probe' => [
                'v2_node_probe_aggregated',
                't',
                'date_hour_minute',
                false,
                [
                    'row_count' => 'COUNT(*)',
                    'total_count' => 'SUM(t.total_count)',
                    'success_count' => "SUM(CASE WHEN t.status = 'success' THEN t.total_count ELSE 0 END)",
                    'failed_like_count' => "SUM(CASE WHEN t.status IN ('failed','timeout','cancelled') THEN t.total_count ELSE 0 END)",
                ],
            ],
            'traffic' => [
                'v2_node_traffic_aggregated',
                't',
                'date_hour_minute',
                false,
                [
                    'row_count' => 'COUNT(*)',
                    'report_count' => 'SUM(t.report_count)',
                    'total_usage_mb' => 'ROUND(SUM(t.total_usage_mb), 3)',
                    'total_usage_seconds' => 'SUM(t.total_usage_seconds)',
                ],
            ],
            'server_detail' => [
                'v2_stat_server_detail',
                't',
                'year_month_day_hour_minute',
                true,
                [
                    'row_count' => 'COUNT(*)',
                    'u_bytes' => 'SUM(t.u)',
                    'd_bytes' => 'SUM(t.d)',
                    'total_bytes' => 'SUM(t.u + t.d)',
                ],
            ],
            'main_aggregated' => [
                'v2_node_main_report_aggregated',
                't',
                'date_hour_minute',
                false,
                [
                    'row_count' => 'COUNT(*)',
                    'delay_weight' => 'SUM(t.delay_weight)',
                    'success_count' => 'SUM(t.success_count)',
                    'failed_count' => 'SUM(t.failed_count)',
                    'client_report_traffic_usage_mb' => 'ROUND(SUM(t.client_report_traffic_usage_mb), 3)',
                    'node_push_traffic_total_bytes' => 'SUM(COALESCE(t.node_push_traffic_total_bytes, 0))',
                ],
            ],
        };
    }

    private function applyTimeFilters($query, string $alias, string $date, ?int $hour, ?int $minute, string $timeMode, bool $recordAtMode): void
    {
        if ($recordAtMode) {
            $parts = explode('-', $date);
            $year = (int) ($parts[0] ?? 0);
            $month = (int) ($parts[1] ?? 0);
            $day = (int) ($parts[2] ?? 0);

            $query->where("{$alias}.year", $year)
                ->where("{$alias}.month", $month)
                ->where("{$alias}.day", $day);
        } else {
            $query->where("{$alias}.date", $date);
        }

        if ($hour !== null) {
            $query->where("{$alias}.hour", $hour);
        }
        if ($minute !== null) {
            $minute = (int) (floor($minute / 5) * 5);
            $query->where("{$alias}.minute", $minute);
        }

        if ($timeMode === 'date_hour_minute') {
            return;
        }
    }

    private function applyCommonFilters($query, string $alias, string $subTable, array $filters): void
    {
        $nodeColumn = $subTable === 'server_detail' ? 'server_id' : 'node_id';
        if (!empty($filters['nodeIds'])) {
            $query->whereIn("{$alias}.{$nodeColumn}", (array) $filters['nodeIds']);
        }

        if ($subTable !== 'server_detail') {
            if (!empty($filters['appIds']) && $this->hasColumn($subTable, 'app_id')) {
                $query->whereIn("{$alias}.app_id", (array) $filters['appIds']);
            }
            if (!empty($filters['appVersions']) && $this->hasColumn($subTable, 'app_version')) {
                $query->whereIn("{$alias}.app_version", (array) $filters['appVersions']);
            }
            if (!empty($filters['platforms']) && $this->hasColumn($subTable, 'platform')) {
                $query->whereIn("{$alias}.platform", (array) $filters['platforms']);
            }
            if (!empty($filters['clientCountries']) && $this->hasColumn($subTable, 'client_country')) {
                $query->whereIn("{$alias}.client_country", (array) $filters['clientCountries']);
            }
            if (!empty($filters['clientIsps']) && $this->hasColumn($subTable, 'client_isp')) {
                $query->whereIn("{$alias}.client_isp", (array) $filters['clientIsps']);
            }
        }

        if ($subTable === 'probe') {
            if (!empty($filters['statuses'])) {
                $query->whereIn("{$alias}.status", (array) $filters['statuses']);
            }
            if (!empty($filters['probeStages'])) {
                $query->whereIn("{$alias}.probe_stage", (array) $filters['probeStages']);
            }
            if (!empty($filters['errorCodes'])) {
                $query->whereIn("{$alias}.error_code", (array) $filters['errorCodes']);
            }
        }
    }

    private function hasColumn(string $subTable, string $column): bool
    {
        $columnsByTable = [
            'performance' => ['app_id', 'app_version', 'platform', 'client_country', 'client_isp'],
            'probe' => ['app_id', 'app_version', 'platform', 'client_country', 'client_isp'],
            'traffic' => ['app_id', 'app_version', 'platform', 'client_country', 'client_isp'],
            'server_detail' => [],
            'main_aggregated' => ['app_id', 'app_version', 'platform', 'client_country', 'client_isp'],
        ];

        return in_array($column, $columnsByTable[$subTable] ?? [], true);
    }

    private function isDimensionSupported(string $subTable, string $dimension): bool
    {
        $supported = [
            'performance' => ['date', 'hour', 'minute', 'node_id', 'client_country', 'platform', 'client_isp', 'app_id', 'app_version'],
            'probe' => ['date', 'hour', 'minute', 'node_id', 'node_ip', 'client_country', 'platform', 'client_isp', 'app_id', 'app_version', 'status', 'probe_stage', 'error_code'],
            'traffic' => ['date', 'hour', 'minute', 'node_id', 'node_ip', 'client_country', 'platform', 'client_isp', 'app_id', 'app_version'],
            'server_detail' => ['year', 'month', 'day', 'hour', 'minute', 'server_id', 'server_type'],
            'main_aggregated' => ['date', 'hour', 'minute', 'scope', 'node_id', 'client_country', 'platform', 'client_isp', 'app_id', 'app_version'],
        ];

        return in_array($dimension, $supported[$subTable] ?? [], true);
    }

    private function normalizeOrderDirection($value): string
    {
        return is_string($value) && strtolower($value) === 'asc' ? 'asc' : 'desc';
    }
}
