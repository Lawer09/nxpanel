<?php

namespace App\Services;

use App\Models\Machine;
use App\Models\NodeDeployTemplate;
use App\Models\Server;
use Illuminate\Support\Str;

/**
 * 节点部署服务
 *
 * 负责：
 *   1. 通过 SSH 向目标机器执行安装脚本（node-install.sh），跳过 register.py 步骤
 *   2. 在服务端直接创建 / 更新节点记录（相当于 register.py 的功能）
 *   3. 将生成的 NODE_ID 写回目标机器的环境配置
 */
class NodeDeployService
{
    // Reality 默认伪装站点
    private const DEFAULT_SERVER_NAME = 'www.apple.com';

    /**
     * 解析最终 deployConfig：
     *   模板配置（低优先级）← 被 $overrideConfig 覆盖（高优先级）
     *
     * @param  array    $overrideConfig  请求中直接传入的配置
     * @param  int|null $templateId      模板ID，null 时尝试使用默认模板
     * @return array    合并后的完整配置
     */
    public static function resolveConfig(array $overrideConfig, ?int $templateId = null): array
    {
        // 1. 尝试加载模板
        $template = null;
        if ($templateId) {
            $template = NodeDeployTemplate::find($templateId);
        } elseif (empty($overrideConfig)) {
            // 既没有传 templateId 也没有传 deploy_config，尝试默认模板
            $template = NodeDeployTemplate::where('is_default', true)->first();
        }

        $baseConfig = $template ? $template->toDeployConfig() : [];

        // 2. override 覆盖 base（递归合并 array 类型字段）
        return array_merge($baseConfig, array_filter(
            $overrideConfig,
            fn($v) => $v !== null && $v !== ''
        ));
    }

    /**
     * 执行一次完整的部署
     *
     * @param  Machine $machine      目标机器
     * @param  array   $deployConfig 部署配置（见 buildEnv 方法注释）
     * @return array   ['output' => string, 'server_id' => int]
     * @throws \Exception
     */
    public function deploy(Machine $machine, array $deployConfig): array
    {
        $output = [];

        // ── 阶段一：服务端注册节点 ────────────────────────────────────────────
        $server   = $this->registerServer($machine, $deployConfig);
        $output[] = "✓ 节点已在服务端注册/更新，ID: {$server->id}，名称: {$server->name}";

        // ── 阶段二：SSH 安装节点程序 ──────────────────────────────────────────
        $envVars  = $this->buildEnvVars($machine, $server, $deployConfig);
        $sshOutput = $this->runInstallScript($machine, $envVars);
        $output[] = $sshOutput;

        return [
            'output'    => implode("\n", $output),
            'server_id' => $server->id,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 阶段一：服务端注册节点
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * 在数据库中创建或更新 Server 节点记录
     *
     * deployConfig 支持的键：
     *   node_name        string   节点名称（默认用机器名）
     *   group_ids        array    权限组ID列表（默认 ["2"]）
     *   port             int      客户端连接端口（默认随机 10000-60000）
     *   server_port      int      节点监听端口（默认同 port）
     *   node_type        string   协议类型：vless/vmess/trojan/shadowsocks/hysteria/tuic/anytls（默认 vless）
     *   node_type2       string   子类型：reality（仅 vless 有效）
     *   server_name      string   Reality 伪装站点（默认 www.apple.com）
     *   tls              int      TLS 模式：0=无 1=TLS 2=Reality（默认 2）
     *   flow             string   VLESS flow（默认 xtls-rprx-vision）
     *   show             bool     是否前台展示（默认 false）
     *   rate             string   倍率（默认 "1"）
     *   existing_node_id int      指定已有节点ID进行更新（不传则新建）
     */
    private function registerServer(Machine $machine, array $cfg): Server
    {
        // ── 生成 Reality 密钥对 ────────────────────────────────────────────
        [$privateKey, $publicKey] = $this->generateX25519KeyPair();
        $shortId = bin2hex(random_bytes(8));

        $nodeType   = $cfg['node_type']   ?? 'vless';
        $nodeType2  = $cfg['node_type2']  ?? 'reality';
        $port       = (int) ($cfg['port']       ?? rand(10000, 60000));
        $serverPort = (int) ($cfg['server_port'] ?? $port);
        $serverName = $cfg['server_name'] ?? self::DEFAULT_SERVER_NAME;
        $groupIds   = $cfg['group_ids']   ?? ['2'];
        $nodeName   = $cfg['node_name']   ?? ($machine->name ?? $machine->ip_address);

        $protocolSettings = $this->buildProtocolSettings(
            $nodeType, $nodeType2, $serverName, $publicKey, $privateKey, $shortId, $cfg
        );

        $serverData = [
            'name'              => $nodeName,
            'type'              => $nodeType,
            'host'              => $machine->ip_address,
            'port'              => (string) $port,
            'server_port'       => $serverPort,
            'group_ids'         => array_map('strval', (array) $groupIds),
            'route_ids'         => $cfg['route_ids'] ?? [],
            'tags'              => $cfg['tags'] ?? [],
            'show'              => (bool) ($cfg['show'] ?? false),
            'rate'              => (string) ($cfg['rate'] ?? '1'),
            'protocol_settings' => $protocolSettings,
            'parent_id'         => $cfg['parent_id'] ?? 0,
        ];

        // 更新已有节点 or 新建
        if (!empty($cfg['existing_node_id'])) {
            $server = Server::findOrFail((int) $cfg['existing_node_id']);
            $server->update($serverData);
        } else {
            $server = Server::create($serverData);
        }

        // 将生成的密钥回写到 deployConfig 中，供 SSH 阶段使用
        $this->storeKeysInConfig($server, $privateKey, $publicKey, $shortId);

        return $server;
    }

    /**
     * 构造协议 protocol_settings
     */
    private function buildProtocolSettings(
        string $nodeType,
        string $nodeType2,
        string $serverName,
        string $publicKey,
        string $privateKey,
        string $shortId,
        array  $cfg
    ): array {
        return match ($nodeType) {
            'vless' => [
                'tls'  => (int) ($cfg['tls'] ?? 2),
                'flow' => $cfg['flow'] ?? 'xtls-rprx-vision',
                'tls_settings' => [
                    'server_name'    => '',
                    'allow_insecure' => false,
                    'cert_mode'      => 'http',
                    'cert_file'      => '',
                    'key_file'       => '',
                ],
                'reality_settings' => [
                    'server_port'    => 443,
                    'server_name'    => $serverName,
                    'allow_insecure' => false,
                    'public_key'     => $publicKey,
                    'private_key'    => $privateKey,
                    'short_id'       => $shortId,
                ],
                'network'          => $cfg['network'] ?? 'tcp',
                'network_settings' => $cfg['network_settings'] ?? [],
                'multiplex'        => $cfg['multiplex'] ?? null,
            ],
            'vmess' => [
                'tls'              => (int) ($cfg['tls'] ?? 0),
                'network'          => $cfg['network'] ?? 'tcp',
                'network_settings' => $cfg['network_settings'] ?? [],
                'multiplex'        => $cfg['multiplex'] ?? null,
            ],
            'trojan' => [
                'network'          => $cfg['network'] ?? 'tcp',
                'network_settings' => $cfg['network_settings'] ?? [],
                'server_name'      => $serverName,
                'allow_insecure'   => false,
            ],
            'shadowsocks' => [
                'cipher'      => $cfg['cipher'] ?? 'aes-128-gcm',
                'plugin'      => $cfg['plugin'] ?? null,
                'plugin_opts' => $cfg['plugin_opts'] ?? null,
            ],
            default => $cfg['protocol_settings'] ?? [],
        };
    }

    /**
     * 将本次生成的密钥保存到节点 code 字段的元数据中（备查）
     * 实际密钥已存于 protocol_settings，此处无需额外操作
     */
    private function storeKeysInConfig(Server $server, string $priv, string $pub, string $shortId): void
    {
        // 密钥已写入 protocol_settings.reality_settings，无需单独存储
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 阶段二：SSH 安装
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * 构造传给安装脚本的环境变量
     * IF_REGISTER=n  —— 跳过 register.py，由服务端负责注册
     */
    private function buildEnvVars(Machine $machine, Server $server, array $cfg): array
    {
        $appUrl   = rtrim(config('app.url'), '/');
        $adminKey = config('app.key'); // 用于内部 API 认证（可换成专用 Token）

        $ps            = $server->protocol_settings ?? [];
        $realityPs     = $ps['reality_settings'] ?? [];
        $nodeTypeInt   = $this->nodeTypeToInt($server->type, $cfg['node_type2'] ?? '');
        $coreTypeInt   = (int) ($cfg['core_type'] ?? 2);

        return [
            // 必需
            'API_HOST'    => $appUrl,
            'API_KEY'     => admin_setting('server_token', ''),
            'NODE_ID'     => (string) $server->id,
            'CORE_TYPE'   => (string) $coreTypeInt,

            // 节点协议
            'NODE_TYPE'   => (string) $nodeTypeInt,
            'NODE_TYPE2'  => $cfg['node_type2'] ?? 'reality',

            // Reality 密钥（供节点核心使用）
            'UUID_AB'              => (string) \Illuminate\Support\Str::uuid(),
            'SHORT_ID'             => $realityPs['short_id']   ?? '',
            'REALITY_SERVER_NAME'  => $realityPs['server_name'] ?? self::DEFAULT_SERVER_NAME,

            // 证书
            'CERT_MODE'   => $cfg['cert_mode']   ?? 'none',
            'CERT_DOMAIN' => $cfg['cert_domain']  ?? 'www.apple.com',

            // 控制开关
            'IF_GENERATE' => 'y',
            'IF_REGISTER' => 'n',   // ← 跳过 register.py，服务端已注册

            // 可选覆盖
            'NODE_INOUT_TYPE' => $cfg['node_inout_type'] ?? 'stand',
            'GITHUB_TOKEN'    => $cfg['github_token']    ?? '',
            'RELEASE_REPO'    => $cfg['release_repo']    ?? 'Lawer09/ad2nx',
            'SCRIPT_REPO'     => $cfg['script_repo']     ?? 'Lawer09/ad2nx-s',
            'SCRIPT_BRANCH'   => $cfg['script_branch']   ?? 'master',
        ];
    }

    /**
     * SSH 执行安装脚本
     */
    private function runInstallScript(Machine $machine, array $envVars): string
    {
        $scriptPath = resource_path('scripts/node-install.sh');

        if (!file_exists($scriptPath)) {
            throw new \Exception('安装脚本不存在: resources/scripts/node-install.sh');
        }

        $scriptContent = file_get_contents($scriptPath);

        $sshService = new MachineSSHService();
        $ssh        = $sshService->connect($machine);
        $ssh->setTimeout(300);

        // 构造 export 前缀
        $exports = '';
        foreach ($envVars as $k => $v) {
            if ($v === '' || $v === null) continue;
            $escaped  = str_replace("'", "'\\''", (string) $v);
            $exports .= "export {$k}='{$escaped}'\n";
        }

        // 写脚本到远端临时文件
        $tmpFile = '/tmp/nxpanel_deploy_' . uniqid() . '.sh';
        $fullScript = $exports . "\n" . $scriptContent;
        $ssh->exec("cat > {$tmpFile} << 'NXEOF'\n{$fullScript}\nNXEOF");
        $ssh->exec("chmod +x {$tmpFile}");

        $output = $ssh->exec("bash {$tmpFile} 2>&1; echo \"EXIT_CODE:\$?\"");
        $ssh->exec("rm -f {$tmpFile}");
        $ssh->disconnect();

        // 解析退出码
        if (preg_match('/EXIT_CODE:(\d+)$/', trim($output), $m)) {
            $code   = (int) $m[1];
            $output = rtrim(preg_replace('/EXIT_CODE:\d+$/', '', trim($output)));
            if ($code !== 0) {
                throw new \Exception("安装脚本退出码 {$code}，输出:\n{$output}");
            }
        }

        return $output;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 工具方法
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * 生成 X25519 密钥对（纯 PHP，不依赖 Python）
     * 返回 [base64url_private, base64url_public]
     */
    private function generateX25519KeyPair(): array
    {
        // phpseclib3 已在 vendor 中
        $privateKey = \phpseclib3\Crypt\EC::createKey('Curve25519');
        $priv = base64url_encode($privateKey->toString('MontgomeryPrivate', ['format' => 'Raw']));
        $pub  = base64url_encode($privateKey->getPublicKey()->toString('MontgomeryPublic', ['format' => 'Raw']));
        return [$priv, $pub];
    }

    /**
     * 节点类型字符串 → 安装脚本的整数 NODE_TYPE
     * 1=shadowsocks 2=vless 3=vmess 4=hysteria 5=hysteria2 6=trojan 7=tuic 8=anytls
     */
    private function nodeTypeToInt(string $type, string $subType = ''): int
    {
        return match (strtolower($type)) {
            'shadowsocks' => 1,
            'vless'       => 2,
            'vmess'       => 3,
            'hysteria'    => 4,
            'hysteria2'   => 5,
            'trojan'      => 6,
            'tuic'        => 7,
            'anytls'      => 8,
            default       => 2,
        };
    }
}

// ── 全局辅助：base64url 编码（无填充）────────────────────────────────────────
if (!function_exists('base64url_encode')) {
    function base64url_encode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
