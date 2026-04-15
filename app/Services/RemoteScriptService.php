<?php  
// app/Services/RemoteScriptService.php  
  
namespace App\Services;  
  
use App\Models\Machine;  
use App\Jobs\RunRemoteScriptJob;  
use Illuminate\Support\Str;  
use Illuminate\Support\Facades\Cache;  
  
class RemoteScriptService  
{  
    /** Redis key 前缀 */  
    private const CACHE_PREFIX = 'remote_task:';  
  
    /** 任务结果默认保留时间（秒） */  
    private const RESULT_TTL = 3600; // 1 小时  
  
    /**  
     * 提交一个远程脚本执行任务（异步）  
     *  
     * @param int    $machineId   目标机器 ID  
     * @param string $script      脚本内容或 resources/scripts/ 下的相对路径  
     * @param array  $envVars     传给脚本的环境变量 ['KEY' => 'value']  
     * @param bool   $isPath      true=script 是文件路径, false=script 是脚本内容  
     * @param int    $timeout     SSH 执行超时（秒）  
     * @return string taskId  
     */  
    public static function dispatch(  
        int    $machineId,  
        string $script,  
        array  $envVars = [],  
        bool   $isPath = true,  
        int    $timeout = 300  
    ): string {  
        $taskId = Str::uuid()->toString();  
  
        // 初始状态写入 Redis  
        Cache::put(self::CACHE_PREFIX . $taskId, [  
            'status'     => 'pending',  
            'machine_id' => $machineId,  
            'script'     => $isPath ? $script : '[inline]',  
            'output'     => '',  
            'exit_code'  => null,  
            'error'      => null,  
            'created_at' => now()->toDateTimeString(),  
            'started_at' => null,  
            'finished_at'=> null,  
        ], self::RESULT_TTL);  
  
        // 派发到 Horizon 的 deploy 队列  
        RunRemoteScriptJob::dispatch($taskId, $machineId, $script, $envVars, $isPath, $timeout)  
            ->onQueue('deploy');  
  
        return $taskId;  
    }  
  
    /**  
     * 查询任务进度（纯 Redis，不查 MySQL）  
     */  
    public static function getProgress(string $taskId): ?array  
    {  
        return Cache::get(self::CACHE_PREFIX . $taskId);  
    }  
  
    /**  
     * 更新任务状态（供 Job 内部调用）  
     */  
    public static function updateProgress(string $taskId, array $data): void  
    {  
        $current = Cache::get(self::CACHE_PREFIX . $taskId, []);  
        Cache::put(  
            self::CACHE_PREFIX . $taskId,  
            array_merge($current, $data),  
            self::RESULT_TTL  
        );  
    }  
  
    /**  
     * 追加输出（供 Job 内部实时追加脚本输出）  
     */  
    public static function appendOutput(string $taskId, string $chunk): void  
    {  
        $current = Cache::get(self::CACHE_PREFIX . $taskId, []);  
        $current['output'] = ($current['output'] ?? '') . $chunk;  
        Cache::put(self::CACHE_PREFIX . $taskId, $current, self::RESULT_TTL);  
    }  
}