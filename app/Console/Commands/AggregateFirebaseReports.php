<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AggregateFirebaseReports extends Command
{
    protected $signature = 'firebase_report:aggregate
        {--hours=72 : Rolling window hours to rebuild}
        {--date-from= : Start date (YYYY-MM-DD, UTC+8)}
        {--date-to= : End date (YYYY-MM-DD, UTC+8)}
        {--only= : Limit aggregation scope. Supported: app-connection}
        {--chunk=500 : Upsert chunk size}
        {--rebuild-first-seen : Rebuild first-seen table from all history}';

    protected $description = 'Aggregate firebase event reports into hourly summary tables';

    public function handle(): int
    {
        try {
            [$windowStart, $now] = $this->resolveRange();
            $only = $this->normalizeOnlyOption($this->option('only'));
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $windowStartMs = $windowStart->copy()->utc()->getTimestampMs();
        $windowEndMs = $now->copy()->utc()->getTimestampMs();

        $chunkSize = max(100, (int) $this->option('chunk'));

        DB::transaction(function () use ($windowStartMs, $windowEndMs, $windowStart, $now, $chunkSize, $only) {
            if ($only === null) {
                $this->refreshFirstSeen($windowStartMs, $windowEndMs, (bool) $this->option('rebuild-first-seen'));
                $this->aggregateUserSummary($windowStartMs, $windowEndMs, $windowStart, $now, $chunkSize);
                $this->aggregateNodeSummary($windowStartMs, $windowEndMs, $windowStart, $now, $chunkSize);
            }

            if ($only === null || $only === 'app-connection') {
                $this->aggregateAppConnectionDailyDeviceReport($windowStart, $now, $chunkSize);
            }
        });

        $scopeLabel = $only ?? 'all';
        $this->info(sprintf('firebase report aggregated [%s]: %s - %s', $scopeLabel, $windowStart->toDateTimeString(), $now->toDateTimeString()));
        return self::SUCCESS;
    }

    private function normalizeOnlyOption($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value === 'app-connection') {
            return $value;
        }

        throw new \InvalidArgumentException('--only only supports app-connection');
    }

    /**
     * 解析聚合范围：优先使用 date range，否则使用滚动小时窗口。
     */
    private function resolveRange(): array
    {
        $dateFrom = $this->option('date-from');
        $dateTo = $this->option('date-to');

        if (is_string($dateFrom) && $dateFrom !== '' && is_string($dateTo) && $dateTo !== '') {
            $start = Carbon::createFromFormat('Y-m-d H:i:s', $dateFrom . ' 00:00:00', 'Asia/Shanghai');
            $end = Carbon::createFromFormat('Y-m-d H:i:s', $dateTo . ' 23:59:59', 'Asia/Shanghai');
            if ($start->gt($end)) {
                throw new \InvalidArgumentException('--date-from must be less than or equal to --date-to');
            }

            return [$start->copy()->startOfHour(), $end->copy()->second(0)];
        }

        $hours = max(1, (int) $this->option('hours'));
        $end = Carbon::now('Asia/Shanghai')->second(0);
        $start = $end->copy()->subHours($hours)->startOfHour();
        return [$start, $end];
    }

    /**
     * 更新设备首见时间。
     */
    private function refreshFirstSeen(int $windowStartMs, int $windowEndMs, bool $rebuildAll): void
    {
        if ($rebuildAll) {
            DB::table('firebase_device_first_seen')->truncate();
            $windowSql = '';
        } else {
            $windowSql = sprintf('AND c.event_time_ms BETWEEN %d AND %d', $windowStartMs, $windowEndMs);
        }

        DB::statement(
            "INSERT INTO firebase_device_first_seen (device_id, first_event_time_ms, first_event_at, created_at, updated_at)
             SELECT c.device_id,
                    MIN(c.event_time_ms) AS first_event_time_ms,
                    DATE_ADD(FROM_UNIXTIME(MIN(c.event_time_ms) / 1000), INTERVAL 8 HOUR) AS first_event_at,
                    NOW(),
                    NOW()
             FROM firebase_event_common c
             WHERE c.device_id IS NOT NULL
               AND c.device_id <> ''
               AND c.event_time_ms IS NOT NULL
               {$windowSql}
             GROUP BY c.device_id
             ON DUPLICATE KEY UPDATE
               first_event_time_ms = LEAST(first_event_time_ms, VALUES(first_event_time_ms)),
               first_event_at = DATE_ADD(FROM_UNIXTIME(LEAST(first_event_time_ms, VALUES(first_event_time_ms)) / 1000), INTERVAL 8 HOUR),
               updated_at = NOW()"
        );
    }

    /**
     * 聚合用户侧小时汇总与日活。
     */
    private function aggregateUserSummary(int $windowStartMs, int $windowEndMs, Carbon $windowStart, Carbon $windowEnd, int $chunkSize): void
    {
        DB::table('firebase_report_user_summary')
            ->whereBetween('time_bucket', [$windowStart->toDateTimeString(), $windowEnd->toDateTimeString()])
            ->delete();

        $dailyRows = DB::table('firebase_event_common as c')
            ->whereBetween('c.event_time_ms', [$windowStartMs, $windowEndMs])
            ->whereNotNull('c.device_id')
            ->where('c.device_id', '<>', '')
            ->selectRaw("DATE(CONVERT_TZ(FROM_UNIXTIME(c.event_time_ms / 1000), '+00:00', '+08:00')) as date")
            ->selectRaw("COALESCE(c.app_id, '') as app_id")
            ->selectRaw("COALESCE(c.app_version, '') as app_version")
            ->selectRaw("COALESCE(c.platform, '') as platform")
            ->selectRaw("COALESCE(c.user_country, 'unknown') as country")
            ->selectRaw("COALESCE(c.network_type, 'unknown') as network_type")
            ->selectRaw('COUNT(DISTINCT c.device_id) as dau_device_count')
            ->groupBy(['date', 'app_id', 'app_version', 'platform', 'country', 'network_type'])
            ->get();

        $dailyMap = [];
        foreach ($dailyRows as $row) {
            $key = implode('|', [$row->date, $row->app_id, $row->app_version, $row->platform, $row->country, $row->network_type]);
            $dailyMap[$key] = (int) $row->dau_device_count;
        }

        $hourRows = DB::table('firebase_event_common as c')
            ->leftJoin('firebase_device_first_seen as f', 'f.device_id', '=', 'c.device_id')
            ->whereBetween('c.event_time_ms', [$windowStartMs, $windowEndMs])
            ->whereNotNull('c.event_time_ms')
            ->selectRaw("DATE(CONVERT_TZ(FROM_UNIXTIME(c.event_time_ms / 1000), '+00:00', '+08:00')) as date")
            ->selectRaw("HOUR(CONVERT_TZ(FROM_UNIXTIME(c.event_time_ms / 1000), '+00:00', '+08:00')) as hour")
            ->selectRaw("DATE_FORMAT(CONVERT_TZ(FROM_UNIXTIME(c.event_time_ms / 1000), '+00:00', '+08:00'), '%Y-%m-%d %H:00:00') as time_bucket")
            ->selectRaw("COALESCE(c.app_id, '') as app_id")
            ->selectRaw("COALESCE(c.app_version, '') as app_version")
            ->selectRaw("COALESCE(c.platform, '') as platform")
            ->selectRaw("COALESCE(c.user_country, 'unknown') as country")
            ->selectRaw("COALESCE(c.network_type, 'unknown') as network_type")
            ->selectRaw('COUNT(*) as event_count')
            ->selectRaw('COUNT(DISTINCT c.device_id) as active_device_count')
            ->selectRaw('COUNT(DISTINCT CASE WHEN f.first_event_time_ms IS NOT NULL AND FLOOR(f.first_event_time_ms / 3600000) = FLOOR(c.event_time_ms / 3600000) THEN c.device_id END) as new_user_count')
            ->groupBy(['date', 'hour', 'time_bucket', 'app_id', 'app_version', 'platform', 'country', 'network_type'])
            ->get();

        $upserts = [];
        $recomputedAt = now();
        foreach ($hourRows as $row) {
            $dailyKey = implode('|', [$row->date, $row->app_id, $row->app_version, $row->platform, $row->country, $row->network_type]);
            $upserts[] = [
                'date' => $row->date,
                'hour' => (int) $row->hour,
                'time_bucket' => $row->time_bucket,
                'app_id' => (string) $row->app_id,
                'app_version' => (string) $row->app_version,
                'platform' => (string) $row->platform,
                'country' => (string) $row->country,
                'network_type' => (string) $row->network_type,
                'new_user_count' => (int) $row->new_user_count,
                'active_device_count' => (int) $row->active_device_count,
                'dau_device_count' => (int) ($dailyMap[$dailyKey] ?? 0),
                'event_count' => (int) $row->event_count,
                'recomputed_at' => $recomputedAt,
                'updated_at' => $recomputedAt,
            ];
        }

        if (!empty($upserts)) {
            foreach (array_chunk($upserts, $chunkSize) as $chunk) {
                DB::table('firebase_report_user_summary')->upsert(
                    $chunk,
                    ['date', 'hour', 'app_id', 'app_version', 'platform', 'country', 'network_type'],
                    ['time_bucket', 'new_user_count', 'active_device_count', 'dau_device_count', 'event_count', 'recomputed_at', 'updated_at']
                );
            }
        }
    }

    /**
     * 聚合节点侧小时统计。
     */
    private function aggregateNodeSummary(int $windowStartMs, int $windowEndMs, Carbon $windowStart, Carbon $windowEnd, int $chunkSize): void
    {
        DB::table('firebase_report_node')
            ->whereBetween('time_bucket', [$windowStart->toDateTimeString(), $windowEnd->toDateTimeString()])
            ->delete();

        $rows = DB::table('firebase_event_vpn_session as s')
            ->join('firebase_event_common as c', 'c.event_id', '=', 's.event_id')
            ->whereBetween('c.event_time_ms', [$windowStartMs, $windowEndMs])
            ->whereNotNull('c.event_time_ms')
            ->selectRaw("DATE(CONVERT_TZ(FROM_UNIXTIME(c.event_time_ms / 1000), '+00:00', '+08:00')) as date")
            ->selectRaw("HOUR(CONVERT_TZ(FROM_UNIXTIME(c.event_time_ms / 1000), '+00:00', '+08:00')) as hour")
            ->selectRaw("DATE_FORMAT(CONVERT_TZ(FROM_UNIXTIME(c.event_time_ms / 1000), '+00:00', '+08:00'), '%Y-%m-%d %H:00:00') as time_bucket")
            ->selectRaw("COALESCE(c.app_id, '') as app_id")
            ->selectRaw("COALESCE(c.app_version, '') as app_version")
            ->selectRaw("COALESCE(c.user_country, 'unknown') as country")
            ->selectRaw("COALESCE(s.node_id, '') as node_id")
            ->selectRaw("COALESCE(s.node_host, '') as node_host")
            ->selectRaw("COALESCE(s.node_name, '') as node_name")
            ->selectRaw("COALESCE(s.node_country, 'unknown') as node_country")
            ->selectRaw("COALESCE(s.node_region, '') as node_region")
            ->selectRaw("COALESCE(s.protocol, '') as protocol")
            ->selectRaw('COUNT(*) as total_count')
            ->selectRaw('SUM(CASE WHEN s.success = 1 THEN 1 ELSE 0 END) as success_count')
            ->selectRaw('SUM(CASE WHEN s.success = 0 THEN 1 ELSE 0 END) as fail_count')
            ->selectRaw('ROUND(AVG(CASE WHEN s.connect_ms IS NOT NULL THEN s.connect_ms ELSE NULL END), 0) as avg_connect_ms')
            ->selectRaw('MAX(CASE WHEN s.connect_ms IS NOT NULL THEN s.connect_ms ELSE 0 END) as max_connect_ms')
            ->groupBy(['date', 'hour', 'time_bucket', 'app_id', 'app_version', 'country', 'node_id', 'node_host', 'node_name', 'node_country', 'node_region', 'protocol'])
            ->get();

        $recomputedAt = now();
        $upserts = [];
        foreach ($rows as $row) {
            $total = max(0, (int) $row->total_count);
            $success = max(0, (int) $row->success_count);
            $upserts[] = [
                'date' => $row->date,
                'hour' => (int) $row->hour,
                'time_bucket' => $row->time_bucket,
                'app_id' => (string) $row->app_id,
                'app_version' => (string) $row->app_version,
                'country' => (string) $row->country,
                'node_id' => (string) $row->node_id,
                'node_host' => (string) $row->node_host,
                'node_name' => (string) $row->node_name,
                'node_country' => (string) $row->node_country,
                'node_region' => (string) $row->node_region,
                'protocol' => (string) $row->protocol,
                'total_count' => $total,
                'success_count' => $success,
                'fail_count' => max(0, (int) $row->fail_count),
                'success_rate' => $total > 0 ? round($success / $total, 4) : 0,
                'avg_connect_ms' => max(0, (int) $row->avg_connect_ms),
                'max_connect_ms' => max(0, (int) $row->max_connect_ms),
                'recomputed_at' => $recomputedAt,
                'updated_at' => $recomputedAt,
            ];
        }

        if (!empty($upserts)) {
            foreach (array_chunk($upserts, $chunkSize) as $chunk) {
                DB::table('firebase_report_node')->upsert(
                    $chunk,
                    ['date', 'hour', 'app_id', 'app_version', 'country', 'node_id', 'node_host'],
                    ['time_bucket', 'node_name', 'node_country', 'node_region', 'protocol', 'total_count', 'success_count', 'fail_count', 'success_rate', 'avg_connect_ms', 'max_connect_ms', 'recomputed_at', 'updated_at']
                );
            }
        }
    }

    /**
     * Aggregate the Firebase app connection report into daily device rows.
     */
    private function aggregateAppConnectionDailyDeviceReport(Carbon $windowStart, Carbon $windowEnd, int $chunkSize): void
    {
        $dailyStart = $windowStart->copy()->startOfDay();
        $dailyEnd = $windowEnd->copy()->endOfDay();
        $dailyStartMs = $dailyStart->copy()->utc()->getTimestampMs();
        $dailyEndMs = $dailyEnd->copy()->utc()->getTimestampMs();
        $dateExpression = $this->dateFromEventMsExpression('c.event_time_ms');

        DB::table('firebase_report_app_connection_daily_device')
            ->whereBetween('date', [$dailyStart->toDateString(), $dailyEnd->toDateString()])
            ->delete();

        $sessionRows = DB::table('firebase_event_vpn_session as s')
            ->join('firebase_event_common as c', 'c.event_id', '=', 's.event_id')
            ->whereBetween('c.event_time_ms', [$dailyStartMs, $dailyEndMs])
            ->whereNotNull('c.event_time_ms')
            ->selectRaw("{$dateExpression} as date")
            ->selectRaw("COALESCE(c.app_id, '') as app_id")
            ->selectRaw("COALESCE(c.platform, '') as platform")
            ->selectRaw("COALESCE(c.app_version, '') as app_version")
            ->selectRaw("COALESCE(c.device_id, '') as device_id")
            ->selectRaw('COUNT(*) as client_connect_count')
            ->selectRaw('SUM(CASE WHEN s.success = 1 THEN 1 ELSE 0 END) as success_count')
            ->selectRaw('SUM(CASE WHEN s.success = 0 THEN 1 ELSE 0 END) as fail_count')
            ->selectRaw("SUM(CASE WHEN s.success = 0 AND s.error_stage = 'client_error' AND s.error_code = 'CLIENT_CANCEL' THEN 1 ELSE 0 END) as cancel_count")
            ->groupBy(['date', 'app_id', 'platform', 'app_version', 'device_id'])
            ->get();

        $recomputedAt = now();
        $sessionUpserts = [];
        foreach ($sessionRows as $row) {
            $sessionUpserts[] = [
                'date' => $row->date,
                'app_id' => (string) $row->app_id,
                'platform' => (string) $row->platform,
                'app_version' => (string) $row->app_version,
                'device_id' => (string) $row->device_id,
                'client_connect_count' => max(0, (int) $row->client_connect_count),
                'success_count' => max(0, (int) $row->success_count),
                'fail_count' => max(0, (int) $row->fail_count),
                'cancel_count' => max(0, (int) $row->cancel_count),
                'ping_sample_count' => 0,
                'ping_total_ms' => 0,
                'recomputed_at' => $recomputedAt,
                'updated_at' => $recomputedAt,
            ];
        }

        if (!empty($sessionUpserts)) {
            foreach (array_chunk($sessionUpserts, $chunkSize) as $chunk) {
                DB::table('firebase_report_app_connection_daily_device')->upsert(
                    $chunk,
                    ['date', 'app_id', 'platform', 'app_version', 'device_id'],
                    ['client_connect_count', 'success_count', 'fail_count', 'cancel_count', 'recomputed_at', 'updated_at']
                );
            }
        }

        $probeRows = DB::table('firebase_event_vpn_probe_result as r')
            ->join('firebase_event_common as c', 'c.event_id', '=', 'r.event_id')
            ->whereBetween('c.event_time_ms', [$dailyStartMs, $dailyEndMs])
            ->whereNotNull('c.event_time_ms')
            ->where('r.success', 1)
            ->whereNotNull('r.latency_ms')
            ->selectRaw("{$dateExpression} as date")
            ->selectRaw("COALESCE(c.app_id, '') as app_id")
            ->selectRaw("COALESCE(c.platform, '') as platform")
            ->selectRaw("COALESCE(c.app_version, '') as app_version")
            ->selectRaw("COALESCE(c.device_id, '') as device_id")
            ->selectRaw('COUNT(*) as ping_sample_count')
            ->selectRaw('SUM(r.latency_ms) as ping_total_ms')
            ->groupBy(['date', 'app_id', 'platform', 'app_version', 'device_id'])
            ->get();

        $probeUpserts = [];
        foreach ($probeRows as $row) {
            $probeUpserts[] = [
                'date' => $row->date,
                'app_id' => (string) $row->app_id,
                'platform' => (string) $row->platform,
                'app_version' => (string) $row->app_version,
                'device_id' => (string) $row->device_id,
                'client_connect_count' => 0,
                'success_count' => 0,
                'fail_count' => 0,
                'cancel_count' => 0,
                'ping_sample_count' => max(0, (int) $row->ping_sample_count),
                'ping_total_ms' => max(0, (int) $row->ping_total_ms),
                'recomputed_at' => $recomputedAt,
                'updated_at' => $recomputedAt,
            ];
        }

        if (!empty($probeUpserts)) {
            foreach (array_chunk($probeUpserts, $chunkSize) as $chunk) {
                DB::table('firebase_report_app_connection_daily_device')->upsert(
                    $chunk,
                    ['date', 'app_id', 'platform', 'app_version', 'device_id'],
                    ['ping_sample_count', 'ping_total_ms', 'recomputed_at', 'updated_at']
                );
            }
        }
    }

    private function dateFromEventMsExpression(string $field): string
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return "DATE(DATETIME({$field} / 1000, 'unixepoch', '+8 hours'))";
        }

        return "DATE(CONVERT_TZ(FROM_UNIXTIME({$field} / 1000), '+00:00', '+08:00'))";
    }
}
