<?php

namespace App\Console\Commands;

use App\Jobs\SendWebhookJob;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class SendProjectYesterdayTrafficReport extends Command
{
    protected $signature = 'project:send-yesterday-traffic-report
        {--date= : Report date, defaults to yesterday in app timezone}';

    protected $description = 'Send yesterday project traffic usage report to Feishu webhook';

    /**
     * Build and send the project traffic report for the selected report date.
     */
    public function handle(): int
    {
        try {
            $webhookUrl = trim((string) config('services.feishu.project_traffic_report_webhook_url', ''));
            if ($webhookUrl === '') {
                $this->error('Missing FEISHU_PROJECT_TRAFFIC_REPORT_WEBHOOK_URL.');
                return self::FAILURE;
            }

            $reportDate = $this->resolveReportDate();
            $rows = $this->queryActiveProjectTrafficRows($reportDate);
            $message = $this->buildReportMessage($reportDate, $rows);

            $this->dispatchWebhookMessage($webhookUrl, $message);
            $this->info(sprintf(
                'Queued project traffic report. date=%s projects=%d',
                $reportDate,
                count($rows)
            ));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            Log::error('project:send-yesterday-traffic-report failed', [
                'date' => $this->option('date'),
                'error' => $e->getMessage(),
            ]);
            $this->error('Failed: ' . $e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Resolve the requested report date using the application timezone.
     */
    private function resolveReportDate(): string
    {
        $date = $this->option('date');
        if ($date === null || $date === '') {
            return now(config('app.timezone'))->subDay()->toDateString();
        }

        return Carbon::parse((string) $date, config('app.timezone'))->toDateString();
    }

    /**
     * Query active projects and left join yesterday traffic aggregate data.
     */
    private function queryActiveProjectTrafficRows(string $reportDate): array
    {
        $trafficSubquery = DB::table('project_daily_aggregates')
            ->where('report_date', '=', $reportDate)
            ->selectRaw('project_code')
            ->selectRaw('SUM(traffic_usage_mb) as traffic_usage_mb')
            ->groupBy('project_code');

        return DB::table('project_projects as p')
            ->leftJoinSub($trafficSubquery, 'traffic', function ($join) {
                $join->on('traffic.project_code', '=', 'p.project_code');
            })
            ->where('p.status', '=', 'active')
            ->select([
                'p.project_code',
                'p.project_name',
                'p.owner_name',
                'p.department',
            ])
            ->selectRaw('COALESCE(traffic.traffic_usage_mb, 0) as traffic_usage_mb')
            ->orderBy('p.project_code')
            ->get()
            ->map(fn ($row) => [
                'project_code' => (string) $row->project_code,
                'project_name' => (string) $row->project_name,
                'owner_name' => $this->normalizeDisplayValue($row->owner_name ?? null, '-'),
                'department' => $this->normalizeDisplayValue($row->department ?? null, '未分组'),
                'traffic_usage_mb' => (float) $row->traffic_usage_mb,
                'traffic_usage_gb' => $this->mbToGb((float) $row->traffic_usage_mb),
            ])
            ->values()
            ->all();
    }

    /**
     * Build the Feishu text message body.
     */
    private function buildReportMessage(string $reportDate, array $rows): string
    {
        $totalMb = array_sum(array_map(static fn (array $row): float => (float) $row['traffic_usage_mb'], $rows));
        $totalGb = $this->mbToGb($totalMb);
        $lines = [
            '项目昨日流量日报',
            '统计日期：' . $reportDate,
            '项目数量：' . count($rows),
            '总流量：' . $this->formatGb($totalGb) . ' GB',
            '',
            '项目明细：',
        ];

        if (empty($rows)) {
            $lines[] = '- 无 active 项目';
        } else {
            $lines = array_merge($lines, $this->buildDepartmentProjectLines($rows));
        }

        return implode("\n", $lines);
    }

    /**
     * Group project lines by department and append owner as a plain value.
     */
    private function buildDepartmentProjectLines(array $rows): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $department = (string) ($row['department'] ?? '未分组');
            $grouped[$department][] = $row;
        }

        ksort($grouped, SORT_NATURAL);

        $lines = [];
        foreach ($grouped as $department => $departmentRows) {
            if (!empty($lines)) {
                $lines[] = '';
            }

            $lines[] = $department;
            foreach ($departmentRows as $row) {
                $lines[] = sprintf(
                    '- %s（%s） %s %s GB',
                    $row['project_name'],
                    $row['project_code'],
                    $row['owner_name'],
                    $this->formatGb((float) $row['traffic_usage_gb'])
                );
            }
        }

        return $lines;
    }

    /**
     * Queue a single webhook payload through the existing SendWebhookJob.
     */
    private function dispatchWebhookMessage(string $webhookUrl, string $message): void
    {
        $bufferKey = 'project:traffic_report:webhook:' . (string) Str::uuid();
        $timeoutSeconds = max(1, (int) config('services.feishu.project_traffic_report_timeout_seconds', 10));
        $payload = [
            'event' => 'project_yesterday_traffic_report',
            'module' => 'project_traffic_report',
            'message' => $message,
            'executedAt' => now()->toDateTimeString(),
        ];

        Redis::rpush($bufferKey, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        Redis::expire($bufferKey, 300);

        SendWebhookJob::dispatch($webhookUrl, $bufferKey, [
            'timeoutSeconds' => $timeoutSeconds,
        ]);
    }

    /**
     * Convert traffic usage from MB to GB for report display.
     */
    private function mbToGb(float $mb): float
    {
        return round($mb / 1024, 2);
    }

    /**
     * Normalize optional project metadata for compact message display.
     */
    private function normalizeDisplayValue($value, string $fallback): string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? $fallback : $value;
    }

    /**
     * Format GB values with exactly two decimal places.
     */
    private function formatGb(float $gb): string
    {
        return number_format($gb, 2, '.', '');
    }
}
