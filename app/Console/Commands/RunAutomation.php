<?php

namespace App\Console\Commands;

use App\Jobs\RunAutomationJob;
use App\Services\Automation\AutomationRunnerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class RunAutomation extends Command
{
    protected $signature = 'automation:run
        {module : 自动化模块，例如 traffic_platform}
        {--rule-id= : 只执行指定规则 ID}
        {--target-id=* : 只执行指定目标 ID，可传多个}
        {--dry-run : 只评估不执行动作}
        {--sync : 同步执行（默认仅投递队列，由 Horizon 执行）}';

    protected $description = '执行指定模块自动化规则（检测、告警、动作分发）';

    /**
     * 执行命令。
     */
    public function handle(AutomationRunnerService $runner): int
    {
        try {
            $module = (string) $this->argument('module');
            $params = [
                'ruleId' => $this->option('rule-id') ? (int) $this->option('rule-id') : null,
                'targetIds' => array_values(array_filter((array) $this->option('target-id'), fn ($v) => $v !== null && $v !== '')),
                'dryRun' => (bool) $this->option('dry-run'),
            ];

            if (!(bool) $this->option('sync')) {
                $triggerId = (string) Str::uuid();
                RunAutomationJob::dispatch(
                    $module,
                    $params['ruleId'],
                    $params['targetIds'],
                    $params['dryRun'],
                    $triggerId
                );
                $this->info(sprintf(
                    'Dispatched to queue=automation. module=%s triggerId=%s ruleId=%s targetIds=%s dryRun=%s',
                    $module,
                    $triggerId,
                    $params['ruleId'] ?? 'all',
                    empty($params['targetIds']) ? 'all' : implode(',', $params['targetIds']),
                    $params['dryRun'] ? 'true' : 'false'
                ));
                return self::SUCCESS;
            }

            $summary = $runner->runByModule($module, $params);
            $this->info(sprintf(
                'Sync done. module=%s rules=%d targets=%d triggered=%d recovered=%d skipped=%d failed=%d dryRun=%s',
                $module,
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
            Log::error('automation:run failed', [
                'module' => $this->argument('module'),
                'error' => $e->getMessage(),
            ]);
            $this->error('automation:run failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
