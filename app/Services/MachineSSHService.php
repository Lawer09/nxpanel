<?php  
  
namespace App\Services;  
  
use App\Models\Machine;  
use phpseclib3\Net\SSH2;  
use phpseclib3\Crypt\PublicKeyLoader;  
use Illuminate\Contracts\Encryption\DecryptException;  
  
class MachineSSHService  
{  
    /**  
     * 在指定机器上执行脚本文件  
     * 此方法不暴露为API接口，仅供内部调用  
     *  
     * @param Machine $machine 目标机器  
     * @param string $scriptPath 本地脚本文件路径（相对于 resources/scripts/）  
     * @return array ['output' => string, 'exit_code' => int]  
     * @throws \Exception  
     */  
    public function executeScript(Machine $machine, string $scriptPath): array  
    {  
        // 构建完整的脚本文件路径  
        $fullPath = resource_path('scripts/' . $scriptPath);  
  
        if (!file_exists($fullPath)) {  
            throw new \Exception("脚本文件不存在: {$scriptPath}");  
        }  
  
        $scriptContent = file_get_contents($fullPath);  
  
        if (empty($scriptContent)) {  
            throw new \Exception("脚本文件内容为空: {$scriptPath}");  
        }  
  
        // 解密凭证  
        try {  
            $password = $machine->password;  
            $privateKey = $machine->private_key;  
        } catch (DecryptException $e) {  
            throw new \Exception('密码或私钥解密失败，请重新编辑该机器并保存密码/私钥');  
        }  
  
        // 建立SSH连接  
        $ssh = new SSH2($machine->ip_address, $machine->port);  
        $ssh->setTimeout(120); // 脚本执行可能较长，设置120秒超时  
  
        $authenticated = false;  
  
        // 优先使用私钥认证  
        if (!empty($privateKey)) {  
            try {  
                $key = PublicKeyLoader::load($privateKey);  
                $authenticated = $ssh->login($machine->username, $key);  
            } catch (\Exception $e) {  
                $authenticated = false;  
            }  
        }  
  
        // 回退到密码认证  
        if (!$authenticated && !empty($password)) {  
            $authenticated = $ssh->login($machine->username, $password);  
        }  
  
        if (!$authenticated) {  
            throw new \Exception('SSH认证失败，请检查用户名、密码或私钥是否正确');  
        }  
  
        // 将脚本内容写入远程临时文件并执行  
        $remoteTmpFile = '/tmp/nxpanel_script_' . uniqid() . '.sh';  
  
        // 使用 heredoc 方式写入脚本内容，避免特殊字符问题  
        $escapedContent = str_replace("'", "'\\''", $scriptContent);  
        $ssh->exec("cat > {$remoteTmpFile} << 'NXPANEL_SCRIPT_EOF'\n{$scriptContent}\nNXPANEL_SCRIPT_EOF");  
        $ssh->exec("chmod +x {$remoteTmpFile}");  
  
        // 执行脚本并获取输出  
        $output = $ssh->exec("bash {$remoteTmpFile} 2>&1; echo \"NXPANEL_EXIT_CODE:$?\"");  
  
        // 清理临时文件  
        $ssh->exec("rm -f {$remoteTmpFile}");  
  
        $ssh->disconnect();  
  
        // 解析退出码  
        $exitCode = 1;  
        if (preg_match('/NXPANEL_EXIT_CODE:(\d+)$/', trim($output), $matches)) {  
            $exitCode = (int) $matches[1];  
            $output = preg_replace('/NXPANEL_EXIT_CODE:\d+$/', '', trim($output));  
        }  
  
        return [  
            'output' => trim($output),  
            'exit_code' => $exitCode,  
        ];  
    }  
  
    /**  
     * 建立SSH连接（可复用于其他场景）  
     *  
     * @param Machine $machine  
     * @return SSH2  
     * @throws \Exception  
     */  
    public function connect(Machine $machine): SSH2  
    {  
        try {  
            $password = $machine->password;  
            $privateKey = $machine->private_key;  
        } catch (DecryptException $e) {  
            throw new \Exception('密码或私钥解密失败，请重新编辑该机器并保存密码/私钥');  
        }  
  
        $ssh = new SSH2($machine->ip_address, $machine->port);  
        $ssh->setTimeout(30);  
  
        $authenticated = false;  
  
        if (!empty($privateKey)) {  
            try {  
                $key = PublicKeyLoader::load($privateKey);  
                $authenticated = $ssh->login($machine->username, $key);  
            } catch (\Exception $e) {  
                $authenticated = false;  
            }  
        }  
  
        if (!$authenticated && !empty($password)) {  
            $authenticated = $ssh->login($machine->username, $password);  
        }  
  
        if (!$authenticated) {  
            throw new \Exception('SSH认证失败，请检查用户名、密码或私钥是否正确');  
        }  
  
        return $ssh;  
    }  
}