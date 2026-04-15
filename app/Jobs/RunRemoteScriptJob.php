<?php  
// app/Jobs/RunRemoteScriptJob.php  
  
namespace App\Jobs;  
  
use App\Models\Machine;  
use App\Services\MachineSSHService;  
use App\Services\RemoteScriptService;  
use Illuminate\Bus\Queueable;  
use Illuminate\Contracts\Queue\ShouldQueue;  
use Illuminate\Foundation\Bus\Dispatchable;  
use Illuminate\Queue\InteractsWithQueue;  
use Illuminate\Queue\SerializesModels;  
use Illuminate\Support\Facades\Log;  
use phpseclib3\Net\SFTP;  
  
class RunRemoteScriptJob implements ShouldQueue  
{  
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;  
  
    public int $timeout = 300;  
    public int $tries   = 1;  
  
    public function __construct(  
        public readonly string $taskId,  
        public readonly int    $machineId,  
        public readonly string $script,  
        public readonly array  $envVars,  
        public readonly bool   $isPath,  
        public readonly int    $sshTimeout  
    ) {  
        $this->timeout = $sshTimeout + 30; // Job 超时 > SSH 超时  
    }  
  
    public function handle(): void  
    {  
        RemoteScriptService::updateProgress($this->taskId, [  
            'status'     => 'running',  
            'started_at' => now()->toDateTimeString(),  
        ]);  
  
        try {  
            $machine = Machine::findOrFail($this->machineId);  
  
            // 构造完整脚本内容  
            $scriptContent = $this->resolveScriptContent();  
            $fullScript    = $this->prependEnvVars($scriptContent);  
  
            // SSH 连接 + SFTP 上传 + 执行  
            $sshService = new MachineSSHService();  
            $ssh = $sshService->connect($machine);  
            $ssh->setTimeout($this->sshTimeout);  
  
            $tmpFile = '/tmp/nxpanel_task_' . $this->taskId . '.sh';  
  
            // SFTP 上传脚本  
            $this->uploadScript($machine, $tmpFile, $fullScript);  
  
            // 执行脚本，利用 phpseclib 的回调实时追加输出到 Redis  
            $output = '';  
            $ssh->exec("bash {$tmpFile} 2>&1; echo \"NXPANEL_EXIT_CODE:\$?\"", function ($chunk) use (&$output) {  
                $output .= $chunk;  
                // 每收到一段输出就追加到 Redis，前端轮询可实时看到  
                RemoteScriptService::appendOutput($this->taskId, $chunk);  
            });  
  
            // 清理远端临时文件  
            $ssh->exec("rm -f {$tmpFile}");  
            $ssh->disconnect();  
  
            // 解析退出码  
            $exitCode = $this->parseExitCode($output);  
            $cleanOutput = $this->cleanOutput($output);  
  
            if ($exitCode !== 0) {  
                RemoteScriptService::updateProgress($this->taskId, [  
                    'status'      => 'failed',  
                    'output'      => $cleanOutput,  
                    'exit_code'   => $exitCode,  
                    'error'       => "脚本退出码: {$exitCode}",  
                    'finished_at' => now()->toDateTimeString(),  
                ]);  
                return;  
            }  
  
            RemoteScriptService::updateProgress($this->taskId, [  
                'status'      => 'success',  
                'output'      => $cleanOutput,  
                'exit_code'   => 0,  
                'finished_at' => now()->toDateTimeString(),  
            ]);  
  
        } catch (\Throwable $e) {  
            Log::error('RunRemoteScriptJob failed', [  
                'task_id' => $this->taskId,  
                'error'   => $e->getMessage(),  
            ]);  
            RemoteScriptService::updateProgress($this->taskId, [  
                'status'      => 'failed',  
                'exit_code'   => -1,  
                'error'       => $e->getMessage(),  
                'finished_at' => now()->toDateTimeString(),  
            ]);  
        }  
    }  
  
    private function resolveScriptContent(): string  
    {  
        if ($this->isPath) {  
            $fullPath = resource_path('scripts/' . $this->script);  
            if (!file_exists($fullPath)) {  
                throw new \Exception("脚本文件不存在: {$this->script}");  
            }  
            return file_get_contents($fullPath);  
        }  
        return $this->script; // 直接传入的脚本内容  
    }  
  
    private function prependEnvVars(string $scriptContent): string  
    {  
        $exports = "#!/usr/bin/env bash\n";  
        foreach ($this->envVars as $k => $v) {  
            if ($v === '' || $v === null) continue;  
            $escaped = str_replace("'", "'\\''", (string) $v);  
            $exports .= "export {$k}='{$escaped}'\n";  
        }  
        return $exports . "\n" . $scriptContent;  
    }  
  
    private function uploadScript(Machine $machine, string $remotePath, string $content): void  
    {  
        $sftp = new SFTP($machine->ip_address, $machine->port ?? 22);  
  
        $password   = $machine->password;  
        $privateKey = $machine->private_key;  
  
        $authed = false;  
        if (!empty($privateKey)) {  
            try {  
                $key = \phpseclib3\Crypt\PublicKeyLoader::load($privateKey);  
                $authed = $sftp->login($machine->username, $key);  
            } catch (\Throwable $e) { $authed = false; }  
        }  
        if (!$authed && !empty($password)) {  
            $authed = $sftp->login($machine->username, $password);  
        }  
        if (!$authed) {  
            throw new \Exception('SFTP 认证失败');  
        }  
        if (!$sftp->put($remotePath, $content)) {  
            throw new \Exception('SFTP 上传脚本失败');  
        }  
    }  
  
    private function parseExitCode(string $output): int  
    {  
        if (preg_match('/NXPANEL_EXIT_CODE:(\d+)[\s]*$/', trim($output), $m)) {  
            return (int) $m[1];  
        }  
        return 1;  
    }  
  
    private function cleanOutput(string $output): string  
    {  
        return rtrim(preg_replace('/NXPANEL_EXIT_CODE:\d+[\s]*$/', '', trim($output)));  
    }  
}