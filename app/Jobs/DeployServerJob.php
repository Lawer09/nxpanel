<?php

namespace App\Jobs;

use App\Models\Machine;
use App\Models\NodeDeployTask;
use App\Models\Server;
use App\Services\MachineSSHService;
use App\Services\NodeDeployService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use phpseclib3\Net\SFTP;

/**
 * 通过节点 ID 部署安装脚本
 *
 * 与 DeployNodeJob 的区别：
 *   - DeployNodeJob : 先注册 Server 记录，再 SSH 部署（机器维度）
 *   - DeployServerJob: Server 已存在，仅向其宿主机执行 node-install.sh（节点维度）
 *
 * 环境变量：
 *   API_HOST  = https://pupu.apptilaus.com
 *   API_KEY   = admin_setting('server_token')
 *   NODE_ID   = server.id
 *   CORE_TYPE = sing
 *   NODE_TYPE = <协议字符串，如 vless/vmess/trojan 等>
 */
class DeployServerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries   = 1;

    public function __construct(
        public readonly int $taskId,
        public readonly int $serverId
    ) {}

    public function handle(): void
    {
        $task   = NodeDeployTask::find($this->taskId);
        $server = Server::find($this->serverId);

        if (!$task || !$server) {
            Log::warning('DeployServerJob: task or server not found', [
                'task_id'   => $this->taskId,
                'server_id' => $this->serverId,
            ]);
            return;
        }

        $task->update(['status' => NodeDeployTask::STATUS_RUNNING, 'started_at' => now()]);

        try {
            // ── 直接用 task.machine_id 取机器（Controller 已在创建任务时解析好）──
            $machine = $task->machine_id ? Machine::find($task->machine_id) : null;

            // fallback：按 server.host 匹配（兼容旧任务）
            if (!$machine) {
                $machine = Machine::where('ip_address', $server->host)
                    ->orWhere('hostname', $server->host)
                    ->first();
            }

            if (!$machine) {
                throw new \Exception("未找到与节点 host ({$server->host}) 匹配的机器记录，无法建立 SSH 连接");
            }

            // ── 构造环境变量 ────────────────────────────────────────────────
            $envVars = NodeDeployService::buildServerEnvVars($server);

            // ── 执行安装脚本 ────────────────────────────────────────────────
            $output = self::runScript($machine, $envVars);

            $task->update([
                'status'      => NodeDeployTask::STATUS_SUCCESS,
                'server_id'   => $server->id,
                'output'      => $output,
                'finished_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('DeployServerJob failed', [
                'task_id'   => $this->taskId,
                'server_id' => $this->serverId,
                'error'     => $e->getMessage(),
            ]);
            $task->update([
                'status'      => NodeDeployTask::STATUS_FAILED,
                'server_id'   => $server->id,
                'output'      => $e->getMessage(),
                'finished_at' => now(),
            ]);
        }
    }

    /**
     * 将 node-install.sh 连同 export 前缀一起传送到远端并执行
     */
    private static function runScript(Machine $machine, array $envVars): string
    {
        $scriptPath = resource_path('scripts/node-install.sh');
        if (!file_exists($scriptPath)) {
            throw new \Exception('安装脚本不存在: resources/scripts/node-install.sh');
        }

        $scriptContent = file_get_contents($scriptPath);

        // ── 构造 export 前缀 ────────────────────────────────────────────────
        $exports = "#!/usr/bin/env bash\n";
        foreach ($envVars as $k => $v) {
            if ($v === '' || $v === null) continue;
            $escaped  = str_replace("'", "'\\''", (string) $v);
            $exports .= "export {$k}='{$escaped}'\n";
        }
        $fullScript = $exports . "\n" . $scriptContent;

        // ── 通过 SFTP 上传脚本，避免 heredoc 在单次 exec channel 中失效 ──────
        $sshService = new MachineSSHService();
        $ssh        = $sshService->connect($machine);
        $ssh->setTimeout(300);

        $tmpFile = '/tmp/nxpanel_server_deploy_' . uniqid() . '.sh';

        // 复用同一底层连接创建 SFTP 子系统
        $sftp = new SFTP($machine->ip_address, $machine->port ?? 22);
        // 通过已认证的 SSH 会话传递认证状态（phpseclib 支持直接 login 复用）
        try {
            $password   = $machine->password;
            $privateKey = $machine->private_key;
        } catch (\Throwable $e) {
            throw new \Exception('密码或私钥解密失败');
        }

        $sftpAuthed = false;
        if (!empty($privateKey)) {
            try {
                $key = \phpseclib3\Crypt\PublicKeyLoader::load($privateKey);
                $sftpAuthed = $sftp->login($machine->username, $key);
            } catch (\Throwable $e) {
                $sftpAuthed = false;
            }
        }
        if (!$sftpAuthed && !empty($password)) {
            $sftpAuthed = $sftp->login($machine->username, $password);
        }
        if (!$sftpAuthed) {
            throw new \Exception('SFTP 认证失败');
        }

        // 直接将完整脚本内容写入远端文件（无 heredoc，无截断）
        if (!$sftp->put($tmpFile, $fullScript)) {
            throw new \Exception('SFTP 上传脚本失败');
        }

        // ── SSH 执行 ─────────────────────────────────────────────────────────
        $output = $ssh->exec("bash {$tmpFile} 2>&1; echo \"EXIT_CODE:\$?\"");
        $ssh->exec("rm -f {$tmpFile}");
        $ssh->disconnect();

        if (preg_match('/EXIT_CODE:(\d+)[\s]*$/', trim($output), $m)) {
            $code   = (int) $m[1];
            $output = rtrim(preg_replace('/EXIT_CODE:\d+[\s]*$/', '', trim($output)));
            if ($code !== 0) {
                throw new \Exception("安装脚本退出码 {$code}，输出:\n{$output}");
            }
        }

        return $output;
    }
}
