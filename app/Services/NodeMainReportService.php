<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class NodeMainReportService
{
    public const ALLOWED_GROUP_BY = [
        'date',
        'hour',
        'node_id',
        'node_name',
        'app_id',
        'app_version',
        'platform',
        'client_country',
        'client_isp',
        'node_host',
        'machine_ip',
        'machine_ip_isp',
        'node_protocol',
    ];

    public function query(array $payload): array
    {
        $groupBy = array_values(array_unique($payload['groupBy'] ?? []));
        $dateFrom = $payload['dateFrom'] ?? now()->subDays(7)->toDateString();
        $dateTo = $payload['dateTo'] ?? now()->toDateString();
        $filters = (array) ($payload['filters'] ?? []);
        $fillUnknown = (bool) ($payload['fillUnknown'] ?? true);
        $page = (int) ($payload['page'] ?? 1);
        $pageSize = (int) ($payload['pageSize'] ?? 50);
        $orderBy = is_string($payload['orderBy'] ?? null) ? $payload['orderBy'] : null;
        $orderDirection = $this->normalizeOrderDirection($payload['orderDirection'] ?? null);
        $includeExternal = (bool) ($filters['includeExternal'] ?? false);

        $metricAvailability = $this->buildNodeMetricAvailability($groupBy);
        $query = DB::table('v2_node_main_report_aggregated as r');

        $query->where('r.date', '>=', $dateFrom)
            ->where('r.date', '<=', $dateTo);

        if (!$includeExternal) {
            $query->where('r.node_id', '>', 0);
        }

        if (!empty($filters['nodeIds'])) {
            $query->whereIn('r.node_id', (array) $filters['nodeIds']);
        }
        if (!empty($filters['appIds'])) {
            $query->whereIn('r.app_id', (array) $filters['appIds']);
        }
        if (!empty($filters['appVersions'])) {
            $query->whereIn('r.app_version', (array) $filters['appVersions']);
        }
        if (!empty($filters['platforms'])) {
            $query->whereIn('r.platform', (array) $filters['platforms']);
        }
        if (!empty($filters['clientCountries'])) {
            $query->whereIn('r.client_country', (array) $filters['clientCountries']);
        }
        if (!empty($filters['clientIsps'])) {
            $query->whereIn('r.client_isp', (array) $filters['clientIsps']);
        }
        if (!empty($filters['nodeProtocols'])) {
            $query->whereIn('r.node_protocol', (array) $filters['nodeProtocols']);
        }

        $selects = [];
        foreach ($groupBy as $dim) {
            $selects[] = $fillUnknown
                ? "COALESCE(NULLIF(r.{$dim}, ''), '未知') as {$dim}"
                : "r.{$dim} as {$dim}";
        }

        $selects[] = 'ROUND(SUM(r.delay_weighted_sum) / NULLIF(SUM(r.delay_weight), 0), 2) as avg_delay';
        $selects[] = 'SUM(r.success_count) as success_count';
        $selects[] = 'SUM(r.failed_count) as failed_count';
        $selects[] = 'SUM(r.node_connect_error_count) as node_connect_error_count';
        $selects[] = 'SUM(r.post_connect_probe_error_count) as post_connect_probe_error_count';
        $selects[] = 'ROUND(SUM(r.client_report_traffic_usage_mb), 3) as client_report_traffic_usage_mb';
        $selects[] = 'SUM(r.client_report_usage_seconds) as client_report_usage_seconds';
        $selects[] = 'SUM(r.client_report_count) as client_report_count';
        $selects[] = 'SUM(CASE WHEN r.scope = "node" THEN COALESCE(r.node_push_traffic_u_bytes, 0) ELSE 0 END) as node_push_traffic_u_bytes';
        $selects[] = 'SUM(CASE WHEN r.scope = "node" THEN COALESCE(r.node_push_traffic_d_bytes, 0) ELSE 0 END) as node_push_traffic_d_bytes';
        $selects[] = 'SUM(CASE WHEN r.scope = "node" THEN COALESCE(r.node_push_traffic_total_bytes, 0) ELSE 0 END) as node_push_traffic_total_bytes';
        $selects[] = 'MAX(r.bandwidth) as bandwidth';
        $selects[] = 'MAX(r.up_bandwidth) as up_bandwidth';
        $selects[] = 'MAX(r.down_bandwidth) as down_bandwidth';

        $query->selectRaw(implode(', ', $selects))->groupBy($groupBy);

        $sortable = array_merge($groupBy, [
            'avg_delay',
            'success_count',
            'failed_count',
            'node_connect_error_count',
            'post_connect_probe_error_count',
            'client_report_traffic_usage_mb',
            'client_report_usage_seconds',
            'client_report_count',
            'node_push_traffic_u_bytes',
            'node_push_traffic_d_bytes',
            'node_push_traffic_total_bytes',
            'bandwidth',
            'up_bandwidth',
            'down_bandwidth',
        ]);

        if (is_string($orderBy) && in_array($orderBy, $sortable, true)) {
            $query->orderBy($orderBy, $orderDirection);
        } elseif (in_array('date', $groupBy, true)) {
            $query->orderByDesc('date');
            if (in_array('hour', $groupBy, true)) {
                $query->orderByDesc('hour');
            }
        } else {
            $query->orderByDesc('success_count');
        }

        $data = $query->paginate($pageSize, ['*'], 'page', $page);
        $items = collect($data->items())->map(function ($item) use ($metricAvailability) {
            $row = (array) $item;
            if ($metricAvailability['node_push_traffic'] === 'unavailable_by_group') {
                $row['node_push_traffic_u_bytes'] = null;
                $row['node_push_traffic_d_bytes'] = null;
                $row['node_push_traffic_total_bytes'] = null;
            }
            return $row;
        })->all();

        return [
            'data' => $items,
            'total' => $data->total(),
            'page' => $data->currentPage(),
            'pageSize' => $data->perPage(),
            'groupBy' => $groupBy,
            'metric_availability' => $metricAvailability,
            'bandwidth_source' => 'machine_config|ip_pool_config',
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
        ];
    }

    private function normalizeOrderDirection($value): string
    {
        return is_string($value) && strtolower($value) === 'asc' ? 'asc' : 'desc';
    }

    public function aggregateByBucket(string $date, int $hour, int $minute): void
    {
        $minute = (int) (floor($minute / 5) * 5);

        $machineSub = DB::table('ip_machine as im')
            ->leftJoin('v2_ip_pool as ip', 'ip.id', '=', 'im.ip_id')
            ->selectRaw('im.machine_id')
            ->selectRaw("MAX(CASE WHEN im.bind_status = 'active' THEN ip.org ELSE NULL END) as machine_ip_isp")
            ->selectRaw("MAX(CASE WHEN im.bind_status = 'active' THEN ip.bandwidth ELSE NULL END) as ip_bandwidth")
            ->groupBy('im.machine_id');

        $failedStatuses = ['failed', 'timeout', 'cancelled'];
        $failedStatusSql = "'" . implode("','", $failedStatuses) . "'";

        $clientRows = DB::table('v2_node_performance_aggregated as p')
            ->leftJoin('v2_server as s', 's.id', '=', 'p.node_id')
            ->leftJoin('machines as m', 'm.id', '=', 's.machine_id')
            ->leftJoinSub($machineSub, 'mi', function ($join) {
                $join->on('mi.machine_id', '=', 'm.id');
            })
            ->leftJoin('v2_node_probe_aggregated as pr', function ($join) use ($date, $hour, $minute) {
                $join->on('pr.date', '=', 'p.date')
                    ->on('pr.hour', '=', 'p.hour')
                    ->on('pr.minute', '=', 'p.minute')
                    ->on('pr.node_id', '=', 'p.node_id')
                    ->on('pr.client_country', '=', 'p.client_country')
                    ->on('pr.platform', '=', 'p.platform')
                    ->on('pr.client_isp', '=', 'p.client_isp')
                    ->on('pr.app_id', '=', 'p.app_id')
                    ->on('pr.app_version', '=', 'p.app_version')
                    ->where('pr.date', $date)
                    ->where('pr.hour', $hour)
                    ->where('pr.minute', $minute);
            })
            ->leftJoin('v2_node_traffic_aggregated as t', function ($join) use ($date, $hour, $minute) {
                $join->on('t.date', '=', 'p.date')
                    ->on('t.hour', '=', 'p.hour')
                    ->on('t.minute', '=', 'p.minute')
                    ->on('t.node_id', '=', 'p.node_id')
                    ->on('t.client_country', '=', 'p.client_country')
                    ->on('t.platform', '=', 'p.platform')
                    ->on('t.client_isp', '=', 'p.client_isp')
                    ->on('t.app_id', '=', 'p.app_id')
                    ->on('t.app_version', '=', 'p.app_version')
                    ->where('t.date', $date)
                    ->where('t.hour', $hour)
                    ->where('t.minute', $minute);
            })
            ->where('p.date', $date)
            ->where('p.hour', $hour)
            ->where('p.minute', $minute)
            ->groupBy([
                'p.date',
                'p.hour',
                'p.minute',
                'p.node_id',
                's.name',
                's.host',
                'm.ip_address',
                'mi.machine_ip_isp',
                's.type',
                'p.app_id',
                'p.app_version',
                'p.platform',
                'p.client_country',
                'p.client_isp',
            ])
            ->selectRaw('p.date, p.hour, p.minute')
            ->selectRaw("'client' as scope")
            ->selectRaw('p.node_id')
            ->selectRaw('s.name as node_name')
            ->selectRaw('s.host as node_host')
            ->selectRaw('m.ip_address as machine_ip')
            ->selectRaw('mi.machine_ip_isp as machine_ip_isp')
            ->selectRaw('s.type as node_protocol')
            ->selectRaw('p.app_id, p.app_version, p.platform, p.client_country, p.client_isp')
            ->selectRaw('SUM(p.avg_delay * p.total_count) as delay_weighted_sum')
            ->selectRaw('SUM(p.total_count) as delay_weight')
            ->selectRaw("SUM(CASE WHEN pr.status = 'success' THEN pr.total_count ELSE 0 END) as success_count")
            ->selectRaw("SUM(CASE WHEN pr.status IN ({$failedStatusSql}) THEN pr.total_count ELSE 0 END) as failed_count")
            ->selectRaw("SUM(CASE WHEN pr.probe_stage = 'node_connect' AND pr.status IN ({$failedStatusSql}) THEN pr.total_count ELSE 0 END) as node_connect_error_count")
            ->selectRaw("SUM(CASE WHEN pr.probe_stage = 'post_connect_probe' AND pr.status IN ({$failedStatusSql}) THEN pr.total_count ELSE 0 END) as post_connect_probe_error_count")
            ->selectRaw('SUM(COALESCE(t.total_usage_mb, 0)) as client_report_traffic_usage_mb')
            ->selectRaw('SUM(COALESCE(t.total_usage_seconds, 0)) as client_report_usage_seconds')
            ->selectRaw('SUM(COALESCE(t.report_count, 0)) as client_report_count')
            ->selectRaw('NULL as node_push_traffic_u_bytes')
            ->selectRaw('NULL as node_push_traffic_d_bytes')
            ->selectRaw('NULL as node_push_traffic_total_bytes')
            ->selectRaw('COALESCE(mi.ip_bandwidth, m.bandwidth) as bandwidth')
            ->selectRaw('NULL as up_bandwidth')
            ->selectRaw('NULL as down_bandwidth')
            ->get();

        $recordAt = Carbon::parse("{$date} {$hour}:{$minute}:00")->timestamp;
        $nodeRows = DB::table('v2_stat_server_detail as sd')
            ->leftJoin('v2_server as s', 's.id', '=', 'sd.server_id')
            ->leftJoin('machines as m', 'm.id', '=', 's.machine_id')
            ->leftJoinSub($machineSub, 'mi', function ($join) {
                $join->on('mi.machine_id', '=', 'm.id');
            })
            ->where('sd.record_at', $recordAt)
            ->groupBy([
                'sd.server_id',
                's.name',
                's.host',
                'm.ip_address',
                'mi.machine_ip_isp',
                'sd.server_type',
                'mi.ip_bandwidth',
                'm.bandwidth',
            ])
            ->selectRaw('? as date, ? as hour, ? as minute', [$date, $hour, $minute])
            ->selectRaw("'node' as scope")
            ->selectRaw('sd.server_id as node_id')
            ->selectRaw('s.name as node_name')
            ->selectRaw('s.host as node_host')
            ->selectRaw('m.ip_address as machine_ip')
            ->selectRaw('mi.machine_ip_isp as machine_ip_isp')
            ->selectRaw('COALESCE(s.type, sd.server_type) as node_protocol')
            ->selectRaw('NULL as app_id')
            ->selectRaw('NULL as app_version')
            ->selectRaw('NULL as platform')
            ->selectRaw('NULL as client_country')
            ->selectRaw('NULL as client_isp')
            ->selectRaw('0 as delay_weighted_sum')
            ->selectRaw('0 as delay_weight')
            ->selectRaw('0 as success_count')
            ->selectRaw('0 as failed_count')
            ->selectRaw('0 as node_connect_error_count')
            ->selectRaw('0 as post_connect_probe_error_count')
            ->selectRaw('0 as client_report_traffic_usage_mb')
            ->selectRaw('0 as client_report_usage_seconds')
            ->selectRaw('0 as client_report_count')
            ->selectRaw('SUM(sd.u) as node_push_traffic_u_bytes')
            ->selectRaw('SUM(sd.d) as node_push_traffic_d_bytes')
            ->selectRaw('SUM(sd.u + sd.d) as node_push_traffic_total_bytes')
            ->selectRaw('COALESCE(mi.ip_bandwidth, m.bandwidth) as bandwidth')
            ->selectRaw('NULL as up_bandwidth')
            ->selectRaw('NULL as down_bandwidth')
            ->get();

        $allRows = $clientRows->concat($nodeRows);

        foreach ($allRows as $row) {
            $item = (array) $row;
            $dimensionHash = md5(implode('|', [
                $item['date'] ?? '',
                $item['hour'] ?? '',
                $item['minute'] ?? '',
                $item['scope'] ?? '',
                $item['node_id'] ?? 0,
                $item['node_name'] ?? '',
                $item['node_host'] ?? '',
                $item['machine_ip'] ?? '',
                $item['machine_ip_isp'] ?? '',
                $item['node_protocol'] ?? '',
                $item['app_id'] ?? '',
                $item['app_version'] ?? '',
                $item['platform'] ?? '',
                $item['client_country'] ?? '',
                $item['client_isp'] ?? '',
            ]));

            DB::table('v2_node_main_report_aggregated')->updateOrInsert(
                ['dimension_hash' => $dimensionHash],
                [
                    'date' => $item['date'],
                    'hour' => (int) $item['hour'],
                    'minute' => (int) $item['minute'],
                    'scope' => (string) $item['scope'],
                    'node_id' => (int) ($item['node_id'] ?? 0),
                    'node_name' => $item['node_name'] ?? null,
                    'node_host' => $item['node_host'] ?? null,
                    'machine_ip' => $item['machine_ip'] ?? null,
                    'machine_ip_isp' => $item['machine_ip_isp'] ?? null,
                    'node_protocol' => $item['node_protocol'] ?? null,
                    'app_id' => $item['app_id'] ?? null,
                    'app_version' => $item['app_version'] ?? null,
                    'platform' => $item['platform'] ?? null,
                    'client_country' => $item['client_country'] ?? null,
                    'client_isp' => $item['client_isp'] ?? null,
                    'delay_weighted_sum' => (float) ($item['delay_weighted_sum'] ?? 0),
                    'delay_weight' => (int) ($item['delay_weight'] ?? 0),
                    'success_count' => (int) ($item['success_count'] ?? 0),
                    'failed_count' => (int) ($item['failed_count'] ?? 0),
                    'node_connect_error_count' => (int) ($item['node_connect_error_count'] ?? 0),
                    'post_connect_probe_error_count' => (int) ($item['post_connect_probe_error_count'] ?? 0),
                    'client_report_traffic_usage_mb' => (float) ($item['client_report_traffic_usage_mb'] ?? 0),
                    'client_report_usage_seconds' => (int) ($item['client_report_usage_seconds'] ?? 0),
                    'client_report_count' => (int) ($item['client_report_count'] ?? 0),
                    'node_push_traffic_u_bytes' => $item['node_push_traffic_u_bytes'],
                    'node_push_traffic_d_bytes' => $item['node_push_traffic_d_bytes'],
                    'node_push_traffic_total_bytes' => $item['node_push_traffic_total_bytes'],
                    'bandwidth' => $item['bandwidth'],
                    'up_bandwidth' => $item['up_bandwidth'],
                    'down_bandwidth' => $item['down_bandwidth'],
                    'updated_at' => now(),
                ]
            );
        }
    }

    private function buildNodeMetricAvailability(array $groupBy): array
    {
        $nodePushUnavailable = $this->isNodePushUnavailableByGroup($groupBy);

        return [
            'avg_delay' => 'full',
            'success_count' => 'full',
            'failed_count' => 'full',
            'node_connect_error_count' => 'full',
            'post_connect_probe_error_count' => 'full',
            'client_report_traffic' => 'full',
            'node_push_traffic' => $nodePushUnavailable ? 'unavailable_by_group' : 'full',
            'bandwidth' => 'partial',
        ];
    }

    private function isNodePushUnavailableByGroup(array $groupBy): bool
    {
        $clientSideDims = [
            'app_id',
            'app_version',
            'platform',
            'client_country',
            'client_isp',
        ];

        return count(array_intersect($groupBy, $clientSideDims)) > 0;
    }
}
