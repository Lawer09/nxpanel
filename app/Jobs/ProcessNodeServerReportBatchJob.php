<?php

namespace App\Jobs;

use App\Services\NodeServerReportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessNodeServerReportBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly array $payloads)
    {
        $this->onQueue('stat');
    }

    public function handle(NodeServerReportService $service): void
    {
        $result = $service->processBatch($this->payloads);

        Log::info('node_server_report batch processed', $result);
    }
}
