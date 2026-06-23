<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class AggregateProjectDailyJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $startDate,
        public string $endDate,
        public string $triggerId,
        public ?int $projectId = null
    ) {}

    /**
     * Execute the project daily aggregate command from the queued async entrypoint.
     */
    public function handle(): void
    {
        Log::info('project aggregate async start', [
            'triggerId' => $this->triggerId,
            'startDate' => $this->startDate,
            'endDate' => $this->endDate,
            'projectId' => $this->projectId,
        ]);

        $arguments = [
            '--start-date' => $this->startDate,
            '--end-date' => $this->endDate,
        ];
        if ($this->projectId !== null) {
            $arguments['--project-id'] = $this->projectId;
        }

        $exitCode = Artisan::call('project:aggregate-daily', $arguments);

        Log::info('project aggregate async finish', [
            'triggerId' => $this->triggerId,
            'startDate' => $this->startDate,
            'endDate' => $this->endDate,
            'projectId' => $this->projectId,
            'exitCode' => $exitCode,
            'output' => trim(Artisan::output()),
        ]);
    }
}
