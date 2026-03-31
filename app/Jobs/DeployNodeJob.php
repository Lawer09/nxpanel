<?php

namespace App\Jobs;

use App\Models\Machine;
use App\Models\NodeDeployTask;
use App\Services\NodeDeployService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DeployNodeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries   = 1;

    public function __construct(
        public readonly int   $taskId,
        public readonly int   $machineId,
        public readonly array $deployConfig
    ) {}

    public function handle(): void
    {
        $task    = NodeDeployTask::find($this->taskId);
        $machine = Machine::find($this->machineId);

        if (!$task || !$machine) {
            Log::warning('DeployNodeJob: task or machine not found', [
                'task_id'    => $this->taskId,
                'machine_id' => $this->machineId,
            ]);
            return;
        }

        $task->update(['status' => NodeDeployTask::STATUS_RUNNING, 'started_at' => now()]);

        try {
            $service = new NodeDeployService();
            $result  = $service->deploy($machine, $this->deployConfig);

            $task->update([
                'status'      => NodeDeployTask::STATUS_SUCCESS,
                'output'      => $result['output'],
                'server_id'   => $result['server_id'] ?? null,
                'finished_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('DeployNodeJob failed', [
                'task_id'    => $this->taskId,
                'machine_id' => $this->machineId,
                'error'      => $e->getMessage(),
            ]);

            $task->update([
                'status'      => NodeDeployTask::STATUS_FAILED,
                'output'      => $e->getMessage(),
                'finished_at' => now(),
            ]);
        }
    }
}
