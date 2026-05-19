<?php

namespace App\Jobs;

use App\Services\Automation\TrafficPlatformAutomationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class RunTrafficPlatformAutomationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;
    public int $timeout = 300;
    public array $backoff = [30, 120];

    public function __construct(
        public ?int $ruleId = null,
        public array $accountIds = [],
        public bool $dryRun = false,
        public ?string $triggerId = null
    ) {
        $this->onQueue('automation');
        $this->triggerId = $this->triggerId ?: (string) Str::uuid();
    }

    /**
     * 执行代理流量自动化任务。
     */
    public function handle(TrafficPlatformAutomationService $service): void
    {
        $lock = Cache::lock('traffic_platform_automation:run', 600);
        if (!$lock->get()) {
            Log::warning('traffic platform automation skipped due to lock', [
                'triggerId' => $this->triggerId,
                'ruleId' => $this->ruleId,
            ]);
            return;
        }

        try {
            Log::info('traffic platform automation start', [
                'triggerId' => $this->triggerId,
                'ruleId' => $this->ruleId,
                'accountIds' => $this->accountIds,
                'dryRun' => $this->dryRun,
            ]);

            $summary = $service->run([
                'ruleId' => $this->ruleId,
                'accountIds' => $this->accountIds,
                'dryRun' => $this->dryRun,
            ]);

            Log::info('traffic platform automation finish', [
                'triggerId' => $this->triggerId,
                'summary' => $summary,
            ]);
        } finally {
            $lock->release();
        }
    }
}
