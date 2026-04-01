<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServerTemplate extends Model
{
    protected $table = 'v2_server_template';

    protected $guarded = ['id'];

    protected $casts = [
        'group_ids'          => 'array',
        'route_ids'          => 'array',
        'tags'               => 'array',
        'excludes'           => 'array',
        'ips'                => 'array',
        'protocol_settings'  => 'array',
        'custom_outbounds'   => 'array',
        'custom_routes'      => 'array',
        'cert_config'        => 'array',
        'rate_time_ranges'   => 'array',
        'generation_options' => 'array',
        'show'               => 'boolean',
        'is_default'         => 'boolean',
        'rate_time_enable'   => 'boolean',
    ];

    /**
     * 将模板转换为可直接用于创建节点的配置数组（去除模板专有字段）
     */
    public function toServerConfig(): array
    {
        return collect($this->toArray())
            ->except(['id', 'name', 'description', 'is_default', 'generation_options', 'created_at', 'updated_at'])
            ->filter(fn($v) => $v !== null)
            ->all();
    }

    /**
     * 根据 generation_options 对节点配置数组进行动态参数填充。
     *
     * 规则：
     *   port_random          = true  → port 随机生成（范围 port_min–port_max）
     *   port_same            = true  → server_port = port（仅在 port_random=true 时生效）
     *   server_port_random   = true  → server_port 独立随机生成（port_same=true 时忽略此项）
     *   reality_key_random   = true  → 重新生成 X25519 密钥对并写入 protocol_settings.reality_settings
     *   reality_shortid_random = true → 重新生成 short_id 并写入 protocol_settings.reality_settings
     *
     * @param  array $config  toServerConfig() 的输出，作为基础配置
     * @return array          填充随机值后的配置
     */
    public function applyGenerationOptions(array $config): array
    {
        $opts = $this->generation_options ?? [];

        $portRandom        = (bool) ($opts['port_random']           ?? false);
        $portSame          = (bool) ($opts['port_same']             ?? false);
        $serverPortRandom  = (bool) ($opts['server_port_random']    ?? false);
        $portMin           = (int)  ($opts['port_min']              ?? 10000);
        $portMax           = (int)  ($opts['port_max']              ?? 60000);
        $realityKeyRandom  = (bool) ($opts['reality_key_random']    ?? false);
        $shortIdRandom     = (bool) ($opts['reality_shortid_random'] ?? false);

        // ── 端口随机化 ─────────────────────────────────────────────────────
        if ($portRandom) {
            $port = rand($portMin, $portMax);
            $config['port'] = (string) $port;

            if ($portSame) {
                $config['server_port'] = $port;
            } elseif ($serverPortRandom) {
                $config['server_port'] = rand($portMin, $portMax);
            }
        } elseif ($serverPortRandom) {
            $config['server_port'] = rand($portMin, $portMax);
        }

        // ── Reality 密钥 / short_id 随机化 ────────────────────────────────
        if ($realityKeyRandom || $shortIdRandom) {
            $ps = $config['protocol_settings'] ?? [];
            $rs = $ps['reality_settings'] ?? [];

            if ($realityKeyRandom) {
                [$priv, $pub] = self::generateX25519KeyPair();
                $rs['private_key'] = $priv;
                $rs['public_key']  = $pub;
            }

            if ($shortIdRandom) {
                $rs['short_id'] = bin2hex(random_bytes(8));
            }

            $ps['reality_settings']  = $rs;
            $config['protocol_settings'] = $ps;
        }

        return $config;
    }

    /**
     * 生成 X25519 密钥对（base64url 编码）
     * @return array [privateKey, publicKey]
     */
    private static function generateX25519KeyPair(): array
    {
        $key  = \phpseclib3\Crypt\EC::createKey('Curve25519');
        $priv = rtrim(strtr(base64_encode($key->toString('MontgomeryPrivate')), '+/', '-_'), '=');
        $pub  = rtrim(strtr(base64_encode($key->getPublicKey()->toString('MontgomeryPublic')), '+/', '-_'), '=');
        return [$priv, $pub];
    }
}
