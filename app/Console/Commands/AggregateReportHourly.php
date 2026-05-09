<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AggregateReportHourly extends Command
{
    protected $signature = 'report_hourly:aggregate
        {--date= : YYYY-MM-DD}
        {--hour= : 0-23}
        {--rebuild : Delete target hour rows before aggregate}';

    protected $description = 'Aggregate user/node hourly report tables';

    public function handle(): int
    {
        try {
            $targets = $this->resolveTargets();
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        if (empty($targets)) {
            $this->error('No target hour resolved.');
            return self::FAILURE;
        }

        $rebuild = (bool) $this->option('rebuild');

        foreach ($targets as $target) {
            $date = $target['date'];
            $hour = $target['hour'];

            DB::transaction(function () use ($date, $hour, $rebuild) {
                if ($rebuild) {
                    DB::table('v3_report_user_hourly')->where('date', $date)->where('hour', $hour)->delete();
                    DB::table('v3_report_node_hourly')->where('date', $date)->where('hour', $hour)->delete();
                }

                $this->aggregateUserHourly($date, $hour);
                $this->aggregateNodeHourly($date, $hour);
            });

            $this->info(sprintf('aggregated report hourly: %s %02d', $date, $hour));
        }

        return self::SUCCESS;
    }

    private function resolveTargets(): array
    {
        $dateOpt = $this->option('date');
        $hourOpt = $this->option('hour');

        if ($dateOpt !== null || $hourOpt !== null) {
            if (!is_string($dateOpt) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateOpt) !== 1) {
                throw new \InvalidArgumentException('--date format must be YYYY-MM-DD');
            }

            if ($hourOpt === null || !is_numeric($hourOpt)) {
                throw new \InvalidArgumentException('--hour is required and must be 0-23 when --date is set');
            }

            $hour = (int) $hourOpt;
            if ($hour < 0 || $hour > 23) {
                throw new \InvalidArgumentException('--hour must be 0-23');
            }

            return [['date' => $dateOpt, 'hour' => $hour]];
        }

        $now = Carbon::now('Asia/Shanghai');
        $curr = $now->copy();
        $prev = $now->copy()->subHour();

        return [
            ['date' => $prev->toDateString(), 'hour' => (int) $prev->hour],
            ['date' => $curr->toDateString(), 'hour' => (int) $curr->hour],
        ];
    }

    private function aggregateUserHourly(string $date, int $hour): void
    {
        $userRows = DB::table('v3_user_report_user')
            ->where('date', $date)
            ->where('hour', $hour)
            ->selectRaw('date, hour, user_id, app_id, app_version, country, SUM(traffic_usage) as traffic_usage_mb, SUM(traffic_use_time) as traffic_use_time, SUM(compute_count) as report_count_user')
            ->groupBy(['date', 'hour', 'user_id', 'app_id', 'app_version', 'country'])
            ->get();

        $nodeRows = DB::table('v3_node_server_report_user')
            ->where('date', $date)
            ->where('hour', $hour)
            ->selectRaw('date, hour, user_id, app_id, app_version, country, SUM(traffic_upload) as traffic_upload_b, SUM(traffic_download) as traffic_download_b, SUM(compute_count) as report_count_node')
            ->groupBy(['date', 'hour', 'user_id', 'app_id', 'app_version', 'country'])
            ->get();

        $merged = [];

        foreach ($userRows as $row) {
            $key = $this->userKey($row->date, (int) $row->hour, (int) $row->user_id, (string) $row->app_id, (string) $row->app_version, (string) $row->country);
            $merged[$key] = [
                'date' => $row->date,
                'hour' => (int) $row->hour,
                'user_id' => (int) $row->user_id,
                'app_id' => (string) $row->app_id,
                'app_version' => (string) $row->app_version,
                'country' => (string) $row->country,
                'traffic_usage' => round(((float) $row->traffic_usage_mb) * 1024, 3),
                'traffic_use_time' => (int) $row->traffic_use_time,
                'traffic_upload' => 0.0,
                'traffic_download' => 0.0,
                'report_count_user' => (int) $row->report_count_user,
                'report_count_node' => 0,
                'updated_at' => now(),
            ];
        }

        foreach ($nodeRows as $row) {
            $key = $this->userKey($row->date, (int) $row->hour, (int) $row->user_id, (string) $row->app_id, (string) $row->app_version, (string) $row->country);
            if (!isset($merged[$key])) {
                $merged[$key] = [
                    'date' => $row->date,
                    'hour' => (int) $row->hour,
                    'user_id' => (int) $row->user_id,
                    'app_id' => (string) $row->app_id,
                    'app_version' => (string) $row->app_version,
                    'country' => (string) $row->country,
                    'traffic_usage' => 0.0,
                    'traffic_use_time' => 0,
                    'traffic_upload' => 0.0,
                    'traffic_download' => 0.0,
                    'report_count_user' => 0,
                    'report_count_node' => 0,
                    'updated_at' => now(),
                ];
            }

            $merged[$key]['traffic_upload'] = round(((float) $row->traffic_upload_b) / 1024, 3);
            $merged[$key]['traffic_download'] = round(((float) $row->traffic_download_b) / 1024, 3);
            $merged[$key]['report_count_node'] = (int) $row->report_count_node;
        }

        foreach ($merged as $row) {
            DB::table('v3_report_user_hourly')->updateOrInsert(
                [
                    'date' => $row['date'],
                    'hour' => $row['hour'],
                    'user_id' => $row['user_id'],
                    'app_id' => $row['app_id'],
                    'app_version' => $row['app_version'],
                    'country' => $row['country'],
                ],
                [
                    'traffic_usage' => $row['traffic_usage'],
                    'traffic_use_time' => $row['traffic_use_time'],
                    'traffic_upload' => $row['traffic_upload'],
                    'traffic_download' => $row['traffic_download'],
                    'report_count_user' => $row['report_count_user'],
                    'report_count_node' => $row['report_count_node'],
                    'updated_at' => $row['updated_at'],
                ]
            );
        }
    }

    private function aggregateNodeHourly(string $date, int $hour): void
    {
        $nodeSourceRows = DB::table('v3_node_server_report_node')
            ->where('date', $date)
            ->where('hour', $hour)
            ->select([
                'date', 'hour', 'node_id', 'node_type', 'node_host', 'node_public_ip',
                'traffic_upload', 'traffic_download',
                'avg_cpu_usage', 'avg_mem_usage', 'max_cpu_usage', 'max_mem_usage', 'avg_disk_usage',
                'avg_inbound_speed', 'avg_outbound_speed', 'max_inbound_speed', 'max_outbound_speed',
                'avg_tcp_connections', 'max_tcp_connections', 'avg_alive_users', 'max_alive_users',
                'compute_count',
            ])
            ->get();

        $userSourceRows = DB::table('v3_user_report_node')
            ->where('date', $date)
            ->where('hour', $hour)
            ->selectRaw('date, hour, node_id, node_type, node_host, probe_stage, ROUND(SUM(avg_delay * compute_count) / NULLIF(SUM(compute_count), 0), 2) as avg_delay, SUM(traffic_usage) as traffic_usage_mb, SUM(traffic_use_time) as traffic_use_time, SUM(success_count) as success_count, SUM(fail_count) as fail_count, SUM(compute_count) as report_count_user')
            ->groupBy(['date', 'hour', 'node_id', 'node_type', 'node_host', 'probe_stage'])
            ->get();

        $nodeMetaByNode = [];
        $merged = [];

        foreach ($nodeSourceRows as $row) {
            $nodeId = (int) $row->node_id;
            $nodeMetaByNode[$nodeId] = [
                'node_type' => (string) $row->node_type,
                'node_host' => (string) $row->node_host,
                'node_public_ip' => (string) $row->node_public_ip,
            ];

            $key = $this->nodeKey($row->date, (int) $row->hour, $nodeId, (string) $row->node_type, (string) $row->node_host, (string) $row->node_public_ip, 'post_connect_probe');
            $merged[$key] = [
                'date' => $row->date,
                'hour' => (int) $row->hour,
                'node_id' => $nodeId,
                'node_type' => (string) $row->node_type,
                'node_host' => (string) $row->node_host,
                'node_public_ip' => (string) $row->node_public_ip,
                'probe_stage' => 'post_connect_probe',
                'traffic_upload' => round(((float) $row->traffic_upload) / 1024, 3),
                'traffic_download' => round(((float) $row->traffic_download) / 1024, 3),
                'avg_cpu_usage' => (float) $row->avg_cpu_usage,
                'avg_mem_usage' => (float) $row->avg_mem_usage,
                'max_cpu_usage' => (float) $row->max_cpu_usage,
                'max_mem_usage' => (float) $row->max_mem_usage,
                'avg_disk_usage' => (float) $row->avg_disk_usage,
                'avg_inbound_speed' => (float) $row->avg_inbound_speed,
                'avg_outbound_speed' => (float) $row->avg_outbound_speed,
                'max_inbound_speed' => (float) $row->max_inbound_speed,
                'max_outbound_speed' => (float) $row->max_outbound_speed,
                'avg_tcp_connections' => (float) $row->avg_tcp_connections,
                'max_tcp_connections' => (float) $row->max_tcp_connections,
                'avg_alive_users' => (float) $row->avg_alive_users,
                'max_alive_users' => (float) $row->max_alive_users,
                'avg_delay' => 0.0,
                'traffic_usage' => 0.0,
                'traffic_use_time' => 0,
                'success_count' => 0,
                'fail_count' => 0,
                'success_rate' => 0.0,
                'report_count_node' => (int) $row->compute_count,
                'report_count_user' => 0,
                'updated_at' => now(),
            ];
        }

        foreach ($userSourceRows as $row) {
            $nodeId = (int) $row->node_id;
            $nodeMeta = $nodeMetaByNode[$nodeId] ?? null;
            $nodeType = (string) ($row->node_type ?: ($nodeMeta['node_type'] ?? 'unknown'));
            $nodeHost = (string) ($row->node_host ?: ($nodeMeta['node_host'] ?? 'n.n.n.n'));
            $nodePublicIp = (string) ($nodeMeta['node_public_ip'] ?? '0.0.0.0');
            $probeStage = (string) ($row->probe_stage ?: 'post_connect_probe');

            $key = $this->nodeKey($row->date, (int) $row->hour, $nodeId, $nodeType, $nodeHost, $nodePublicIp, $probeStage);
            if (!isset($merged[$key])) {
                $merged[$key] = [
                    'date' => $row->date,
                    'hour' => (int) $row->hour,
                    'node_id' => $nodeId,
                    'node_type' => $nodeType,
                    'node_host' => $nodeHost,
                    'node_public_ip' => $nodePublicIp,
                    'probe_stage' => $probeStage,
                    'traffic_upload' => 0.0,
                    'traffic_download' => 0.0,
                    'avg_cpu_usage' => 0.0,
                    'avg_mem_usage' => 0.0,
                    'max_cpu_usage' => 0.0,
                    'max_mem_usage' => 0.0,
                    'avg_disk_usage' => 0.0,
                    'avg_inbound_speed' => 0.0,
                    'avg_outbound_speed' => 0.0,
                    'max_inbound_speed' => 0.0,
                    'max_outbound_speed' => 0.0,
                    'avg_tcp_connections' => 0.0,
                    'max_tcp_connections' => 0.0,
                    'avg_alive_users' => 0.0,
                    'max_alive_users' => 0.0,
                    'avg_delay' => 0.0,
                    'traffic_usage' => 0.0,
                    'traffic_use_time' => 0,
                    'success_count' => 0,
                    'fail_count' => 0,
                    'success_rate' => 0.0,
                    'report_count_node' => 0,
                    'report_count_user' => 0,
                    'updated_at' => now(),
                ];
            }

            $merged[$key]['avg_delay'] = (float) $row->avg_delay;
            $merged[$key]['traffic_usage'] = round(((float) $row->traffic_usage_mb) * 1024, 3);
            $merged[$key]['traffic_use_time'] = (int) $row->traffic_use_time;
            $merged[$key]['success_count'] = (int) $row->success_count;
            $merged[$key]['fail_count'] = (int) $row->fail_count;
            $merged[$key]['report_count_user'] = (int) $row->report_count_user;
        }

        foreach ($merged as $row) {
            $total = (int) $row['success_count'] + (int) $row['fail_count'];
            $row['success_rate'] = $total > 0 ? round(100 * ((int) $row['success_count']) / $total, 2) : 0.0;

            DB::table('v3_report_node_hourly')->updateOrInsert(
                [
                    'date' => $row['date'],
                    'hour' => $row['hour'],
                    'node_id' => $row['node_id'],
                    'node_type' => $row['node_type'],
                    'node_host' => $row['node_host'],
                    'node_public_ip' => $row['node_public_ip'],
                    'probe_stage' => $row['probe_stage'],
                ],
                [
                    'traffic_upload' => $row['traffic_upload'],
                    'traffic_download' => $row['traffic_download'],
                    'avg_cpu_usage' => $row['avg_cpu_usage'],
                    'avg_mem_usage' => $row['avg_mem_usage'],
                    'max_cpu_usage' => $row['max_cpu_usage'],
                    'max_mem_usage' => $row['max_mem_usage'],
                    'avg_disk_usage' => $row['avg_disk_usage'],
                    'avg_inbound_speed' => $row['avg_inbound_speed'],
                    'avg_outbound_speed' => $row['avg_outbound_speed'],
                    'max_inbound_speed' => $row['max_inbound_speed'],
                    'max_outbound_speed' => $row['max_outbound_speed'],
                    'avg_tcp_connections' => $row['avg_tcp_connections'],
                    'max_tcp_connections' => $row['max_tcp_connections'],
                    'avg_alive_users' => $row['avg_alive_users'],
                    'max_alive_users' => $row['max_alive_users'],
                    'avg_delay' => $row['avg_delay'],
                    'traffic_usage' => $row['traffic_usage'],
                    'traffic_use_time' => $row['traffic_use_time'],
                    'success_count' => $row['success_count'],
                    'fail_count' => $row['fail_count'],
                    'success_rate' => $row['success_rate'],
                    'report_count_node' => $row['report_count_node'],
                    'report_count_user' => $row['report_count_user'],
                    'updated_at' => $row['updated_at'],
                ]
            );
        }
    }

    private function userKey(string $date, int $hour, int $userId, string $appId, string $appVersion, string $country): string
    {
        return implode('|', [$date, $hour, $userId, $appId, $appVersion, $country]);
    }

    private function nodeKey(string $date, int $hour, int $nodeId, string $nodeType, string $nodeHost, string $nodePublicIp, string $probeStage): string
    {
        return implode('|', [$date, $hour, $nodeId, $nodeType, $nodeHost, $nodePublicIp, $probeStage]);
    }
}
