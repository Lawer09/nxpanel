<?php

namespace App\Jobs;

use App\Services\Automation\AutomationRunnerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class RunAutomationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;
    public int $timeout = 300;
    public array $backoff = [30, 120];

    public function __construct(
        public string $module,
        public ?int $ruleId = null,
        public array $targetIds = [],
        public bool $dryRun = false,
        public ?string $triggerId = null
    ) {
        $this->module = $this->normalizeModule($this->module);
        $this->onQueue('automation');
        $this->triggerId = $this->triggerId ?: (string) Str::uuid();
    }

    /**
     * 执行指定模块自动化任务。
     */
    public function handle(AutomationRunnerService $runner): void
    {
        $lockKey = 'automation:run:' . $this->module;
        $lock = Cache::lock($lockKey, 600);
        if (!$lock->get()) {
            Log::warning('automation job skipped due to lock', [
                'module' => $this->module,
                'triggerId' => $this->triggerId,
                'ruleId' => $this->ruleId,
            ]);
            return;
        }

        try {
            Log::info('automation job start', [
                'module' => $this->module,
                'triggerId' => $this->triggerId,
                'ruleId' => $this->ruleId,
                'targetIds' => $this->targetIds,
                'dryRun' => $this->dryRun,
            ]);

            $summary = $runner->runByModule($this->module, [
                'ruleId' => $this->ruleId,
                'targetIds' => $this->targetIds,
                'dryRun' => $this->dryRun,
            ]);

            Log::info('automation job finish', [
                'module' => $this->module,
                'triggerId' => $this->triggerId,
                'summary' => $summary,
            ]);
        } finally {
            $lock->release();
        }
    }

    /**
     * 统一模块名格式，兼容 traffic-platform / traffic_platform。
     */
    private function normalizeModule(string $module): string
    {
        return str_replace('-', '_', strtolower(trim($module)));
    }
}
