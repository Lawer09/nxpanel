<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SendWebhookTaskIndexRequest;
use App\Models\AdminAuditLog;
use App\Utils\CacheKey;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\MetricsRepository;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Laravel\Horizon\Contracts\WorkloadRepository;
use Laravel\Horizon\WaitTimeCalculator;
use App\Helpers\ResponseEnum;

class SystemController extends Controller
{
    private const SEND_WEBHOOK_QUEUE = 'send_webhook';
    private const DEFAULT_SAMPLE_LIMIT = 10;

    /**
     * 查询系统调度与 Horizon 运行状态。
     */
    public function getSystemStatus()
    {
        $data = [
            'schedule' => $this->getScheduleStatus(),
            'horizon' => $this->getHorizonStatus(),
            'schedule_last_runtime' => Cache::get(CacheKey::get('SCHEDULE_LAST_CHECK_AT', null)),
        ];
        return $this->ok($data);
    }

    /**
     * 查询 Horizon 队列负载。
     */
    public function getQueueWorkload(WorkloadRepository $workload)
    {
        return $this->ok(collect($workload->get())->sortBy('name')->values()->toArray());
    }

    protected function getScheduleStatus(): bool
    {
        return (time() - 120) < Cache::get(CacheKey::get('SCHEDULE_LAST_CHECK_AT', null));
    }

    protected function getHorizonStatus(): bool
    {
        if (!$masters = app(MasterSupervisorRepository::class)->all()) {
            return false;
        }

        return collect($masters)->contains(function ($master) {
            return $master->status === 'paused';
        }) ? false : true;
    }

    /**
     * 查询 Horizon 队列统计摘要。
     */
    public function getQueueStats()
    {
        $data = [
            'failedJobs' => app(JobRepository::class)->countRecentlyFailed(),
            'jobsPerMinute' => app(MetricsRepository::class)->jobsProcessedPerMinute(),
            'pausedMasters' => $this->totalPausedMasters(),
            'periods' => [
                'failedJobs' => config('horizon.trim.recent_failed', config('horizon.trim.failed')),
                'recentJobs' => config('horizon.trim.recent'),
            ],
            'processes' => $this->totalProcessCount(),
            'queueWithMaxRuntime' => app(MetricsRepository::class)->queueWithMaximumRuntime(),
            'queueWithMaxThroughput' => app(MetricsRepository::class)->queueWithMaximumThroughput(),
            'recentJobs' => app(JobRepository::class)->countRecent(),
            'status' => $this->getHorizonStatus(),
            'wait' => collect(app(WaitTimeCalculator::class)->calculate())->take(1),
        ];
        return $this->ok($data);
    }

    /**
     * 查询 send_webhook 专用诊断信息。
     *
     * 返回 Redis 队列中 pending / delayed / reserved 的数量与样本，
     * 同时返回 failed_jobs 中 send_webhook 的失败记录，便于排查 webhook 未送达问题。
     */
    public function getSendWebhookTasks(
        SendWebhookTaskIndexRequest $request,
        WorkloadRepository $workload
    ): JsonResponse {
        $params = $request->validated();
        $sampleLimit = (int) ($params['sampleLimit'] ?? self::DEFAULT_SAMPLE_LIMIT);
        $failedPage = (int) ($params['failedPage'] ?? 1);
        $failedPageSize = (int) ($params['failedPageSize'] ?? self::DEFAULT_SAMPLE_LIMIT);

        $queueKeys = $this->buildRedisQueueKeys(self::SEND_WEBHOOK_QUEUE);
        $pendingJobs = $this->loadPendingJobs($queueKeys['logical']['pending'], $sampleLimit);
        $delayedJobs = $this->loadSortedQueueJobs($queueKeys['logical']['delayed'], $sampleLimit, 'available_at');
        $reservedJobs = $this->loadSortedQueueJobs($queueKeys['logical']['reserved'], $sampleLimit, 'reserved_at');
        $failedJobs = $this->loadFailedSendWebhookJobs($failedPage, $failedPageSize);

        return $this->ok([
            'queue' => self::SEND_WEBHOOK_QUEUE,
            'summary' => [
                'horizon' => $this->getHorizonStatus(),
                'redisPrefix' => $this->getRedisPrefix(),
                'keys' => $queueKeys['physical'],
                'pendingCount' => (int) Redis::llen($queueKeys['logical']['pending']),
                'delayedCount' => (int) Redis::zcard($queueKeys['logical']['delayed']),
                'reservedCount' => (int) Redis::zcard($queueKeys['logical']['reserved']),
                'failedCount' => $failedJobs['total'],
                'workload' => $this->filterSendWebhookWorkload($workload),
            ],
            'pendingJobs' => $pendingJobs,
            'delayedJobs' => $delayedJobs,
            'reservedJobs' => $reservedJobs,
            'failedJobs' => $failedJobs,
        ]);
    }

    /**
     * Get the total process count across all supervisors.
     *
     * @return int
     */
    protected function totalProcessCount()
    {
        $supervisors = app(SupervisorRepository::class)->all();

        return collect($supervisors)->reduce(function ($carry, $supervisor) {
            return $carry + collect($supervisor->processes)->sum();
        }, 0);
    }

    /**
     * Get the number of master supervisors that are currently paused.
     *
     * @return int
     */
    protected function totalPausedMasters()
    {
        if (!$masters = app(MasterSupervisorRepository::class)->all()) {
            return 0;
        }

        return collect($masters)->filter(function ($master) {
            return $master->status === 'paused';
        })->count();
    }

    /**
     * 查询管理后台审计日志。
     */
    public function getAuditLog(Request $request)
    {
        $current = max(1, (int) $request->input('current', 1));
        $pageSize = max(10, (int) $request->input('page_size', 10));

        $builder = AdminAuditLog::with('admin:id,email')
            ->orderBy('id', 'DESC')
            ->when($request->input('action'), fn($q, $v) => $q->where('action', $v))
            ->when($request->input('admin_id'), fn($q, $v) => $q->where('admin_id', $v))
            ->when($request->input('keyword'), function ($q, $keyword) {
                $q->where(function ($q) use ($keyword) {
                    $q->where('uri', 'like', '%' . $keyword . '%')
                      ->orWhere('request_data', 'like', '%' . $keyword . '%');
                });
            });

        $total = $builder->count();
        $res = $builder->forPage($current, $pageSize)->get();
        
        return $this->ok([
            'data' => $res,
            'total' => $total,
            'pageSize' => $pageSize,
            'page' => $current
        ]);
    }

    /**
     * 查询 Horizon 失败任务列表。
     */
    public function getHorizonFailedJobs(Request $request, JobRepository $jobRepository)
    {
        $current = max(1, (int) $request->input('page', 1));
        $pageSize = max(10, (int) $request->input('pageSize', 20));
        $offset = ($current - 1) * $pageSize;

        $failedJobs = collect($jobRepository->getFailed())
            ->sortByDesc('failed_at')
            ->slice($offset, $pageSize)
            ->values();

        $total = $jobRepository->countFailed();

        
        return $this->ok([
            'data' => $failedJobs,
            'total' => $total,
            'pageSize' => $pageSize,
            'page' => $current
        ]);
    }

    /**
     * 构建 Redis 中 send_webhook 队列相关 Key。
     */
    private function buildRedisQueueKeys(string $queue): array
    {
        $baseKey = 'queues:' . $queue;
        $prefix = $this->getRedisPrefix();

        return [
            'logical' => [
                'pending' => $baseKey,
                'delayed' => $baseKey . ':delayed',
                'reserved' => $baseKey . ':reserved',
            ],
            'physical' => [
                'pending' => $prefix . $baseKey,
                'delayed' => $prefix . $baseKey . ':delayed',
                'reserved' => $prefix . $baseKey . ':reserved',
            ],
        ];
    }

    /**
     * 读取 Redis 列表队列中的待执行任务样本。
     */
    private function loadPendingJobs(string $queueKey, int $sampleLimit): array
    {
        $items = Redis::lrange($queueKey, 0, max(0, $sampleLimit - 1));

        return array_values(array_map(function ($item, int $index) {
            return $this->decodeQueuedJob((string) $item, [
                'position' => $index + 1,
            ]);
        }, $items, array_keys($items)));
    }

    /**
     * 读取 Redis ZSET 队列中的 delayed / reserved 任务样本。
     */
    private function loadSortedQueueJobs(string $queueKey, int $sampleLimit, string $timeField): array
    {
        $items = Redis::zrange($queueKey, 0, max(0, $sampleLimit - 1), ['withscores' => true]);
        $jobs = [];

        foreach ($items as $payload => $score) {
            if (is_int($payload)) {
                $jobs[] = $this->decodeQueuedJob((string) $score);
                continue;
            }

            $meta = [];
            if (is_numeric($score)) {
                $meta[$timeField] = date('Y-m-d H:i:s', (int) $score);
                $meta[$timeField . '_timestamp'] = (int) $score;
            }

            $jobs[] = $this->decodeQueuedJob((string) $payload, $meta);
        }

        return $jobs;
    }

    /**
     * 读取 failed_jobs 中 send_webhook 队列的失败记录。
     */
    private function loadFailedSendWebhookJobs(int $page, int $pageSize): array
    {
        if (!Schema::hasTable('failed_jobs')) {
            return [
                'page' => $page,
                'pageSize' => $pageSize,
                'total' => 0,
                'data' => [],
            ];
        }

        $query = DB::table('failed_jobs')
            ->where('queue', self::SEND_WEBHOOK_QUEUE)
            ->orderByDesc('id');

        $total = (int) $query->count();
        $rows = $query->offset(max(0, ($page - 1) * $pageSize))
            ->limit($pageSize)
            ->get();

        return [
            'page' => $page,
            'pageSize' => $pageSize,
            'total' => $total,
            'data' => $rows->map(function ($row) {
                $payload = $this->decodeQueuedJob((string) $row->payload);

                return [
                    'id' => (int) $row->id,
                    'connection' => (string) $row->connection,
                    'queue' => (string) $row->queue,
                    'failedAt' => (string) $row->failed_at,
                    'displayName' => $payload['displayName'] ?? '',
                    'job' => $payload['job'] ?? '',
                    'attempts' => $payload['attempts'] ?? 0,
                    'exceptionSummary' => $this->summarizeException((string) $row->exception),
                    'payload' => $payload,
                ];
            })->values()->all(),
        ];
    }

    /**
     * 解析 Laravel Redis 队列 payload，提取排查所需摘要字段。
     */
    private function decodeQueuedJob(string $payload, array $extra = []): array
    {
        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            return array_merge([
                'rawPayload' => mb_substr($payload, 0, 1000),
            ], $extra);
        }

        return array_merge([
            'uuid' => (string) ($decoded['uuid'] ?? ''),
            'displayName' => (string) ($decoded['displayName'] ?? ''),
            'job' => (string) ($decoded['job'] ?? ''),
            'attempts' => (int) ($decoded['attempts'] ?? 0),
            'maxTries' => isset($decoded['maxTries']) ? (int) $decoded['maxTries'] : null,
            'timeout' => isset($decoded['timeout']) ? (int) $decoded['timeout'] : null,
            'backoff' => $decoded['backoff'] ?? null,
            'commandName' => (string) ($decoded['data']['commandName'] ?? ''),
            'rawPayload' => mb_substr($payload, 0, 1000),
        ], $extra);
    }

    /**
     * 过滤 Horizon workload 中 send_webhook 相关队列项。
     */
    private function filterSendWebhookWorkload(WorkloadRepository $workload): array
    {
        return collect($workload->get())
            ->map(fn ($item) => (array) $item)
            ->filter(function (array $item) {
                $name = (string) ($item['name'] ?? '');
                $queue = (string) ($item['queue'] ?? '');

                return $name === self::SEND_WEBHOOK_QUEUE || $queue === self::SEND_WEBHOOK_QUEUE;
            })
            ->values()
            ->all();
    }

    /**
     * 获取 Redis Key 前缀。
     */
    private function getRedisPrefix(): string
    {
        return (string) config('database.redis.options.prefix', '');
    }

    /**
     * 提取异常首行，避免接口返回过长堆栈。
     */
    private function summarizeException(string $exception): string
    {
        $line = trim((string) strtok($exception, "\n"));

        return mb_substr($line, 0, 500);
    }

}
