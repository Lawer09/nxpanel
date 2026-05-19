<?php

namespace App\Console\Commands;

use App\Jobs\RunTrafficPlatformAutomationJob;
use App\Services\Automation\TrafficPlatformAutomationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class RunTrafficPlatformAutomation extends Command
{
    protected $signature = 'traffic-platform:automation-run
        {--rule-id= : 只执行指定规则 ID}
        {--account-id=* : 只执行指定账号 ID，可传多个}
        {--dry-run : 只评估不执行动作}
        {--sync : 同步执行（默认仅投递队列，由 Horizon 执行）}';

    protected $description = '执行代理流量平台自动化规则（检测、告警、动作分发）';

    /**
     * 执行命令。
     */
    public function handle(TrafficPlatformAutomationService $service): int
    {
        try {
            $params = [
                'ruleId' => $this->option('rule-id') ? (int) $this->option('rule-id') : null,
                'accountIds' => (array) $this->option('account-id'),
                'dryRun' => (bool) $this->option('dry-run'),
            ];

            if (!(bool) $this->option('sync')) {
                $triggerId = (string) Str::uuid();
                RunTrafficPlatformAutomationJob::dispatch(
                    $params['ruleId'],
                    $params['accountIds'],
                    $params['dryRun'],
                    $triggerId
                );
                $this->info(sprintf(
                    'Dispatched to queue=automation. triggerId=%s ruleId=%s accountIds=%s dryRun=%s',
                    $triggerId,
                    $params['ruleId'] ?? 'all',
                    empty($params['accountIds']) ? 'all' : implode(',', $params['accountIds']),
                    $params['dryRun'] ? 'true' : 'false'
                ));
                return self::SUCCESS;
            }

            $summary = $service->run($params);
            $this->info(sprintf(
                'Sync done. rules=%d targets=%d triggered=%d recovered=%d skipped=%d failed=%d dryRun=%s',
                $summary['ruleCount'],
                $summary['targetCount'],
                $summary['triggeredCount'],
                $summary['recoveredCount'],
                $summary['skippedCount'],
                $summary['failedCount'],
                $summary['dryRun'] ? 'true' : 'false'
            ));

            return $summary['failedCount'] > 0 ? self::FAILURE : self::SUCCESS;
        } catch (\Throwable $e) {
            Log::error('traffic-platform:automation-run failed', [
                'error' => $e->getMessage(),
            ]);
            $this->error('traffic-platform:automation-run failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
