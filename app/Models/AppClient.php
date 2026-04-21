<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class AppClient extends Model
{
    protected $table = 'v3_app_clients';

    protected $fillable = [
        'name',
        'app_id',
        'app_token',
        'app_secret',
        'description',
        'is_enabled',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
    ];

    /**
     * 隐藏 secret，仅在创建/重置时返回
     */
    protected $hidden = [
        'app_secret',
    ];

    /**
     * 生成唯一 Token
     */
    public static function generateToken(): string
    {
        return 'nxp_' . Str::random(40);
    }

    /**
     * 生成 Secret
     */
    public static function generateSecret(): string
    {
        return Str::random(48);
    }

    /**
     * Redis key 前缀
     */
    public static function redisKeyPrefix(): string
    {
        return 'app:auth:token:';
    }

    /**
     * 获取当前应用的 Redis key
     */
    public function redisKey(): string
    {
        return self::redisKeyPrefix() . $this->app_id;
    }

    /**
     * 将当前应用信息同步到 Redis
     */
    public function syncToRedis(): void
    {
        Redis::hMSet($this->redisKey(), [
            'token'   => $this->app_token,
            'secret'  => $this->app_secret,
            'enabled' => $this->is_enabled ? 1 : 0,
        ]);
    }

    /**
     * 从 Redis 中移除当前应用信息
     */
    public function removeFromRedis(): void
    {
        Redis::del($this->redisKey());
    }

    /**
     * 全量同步所有应用到 Redis（先清理旧 key 再写入）
     */
    public static function syncAllToRedis(): void
    {
        // 清理所有旧的 app:auth:token:* key
        $prefix = config('database.redis.options.prefix', '');
        $cursor = null;
        do {
            $result = Redis::scan($cursor, [
                'match' => $prefix . self::redisKeyPrefix() . '*',
                'count' => 200,
            ]);
            if ($result === false) break;
            [$cursor, $keys] = $result;
            if (!empty($keys)) {
                // 去掉前缀后删除
                $rawKeys = array_map(fn($k) => str_replace($prefix, '', $k), $keys);
                Redis::del(...$rawKeys);
            }
        } while ($cursor != 0);

        // 写入所有启用的应用
        $clients = self::where('is_enabled', true)->get();
        foreach ($clients as $client) {
            $client->syncToRedis();
        }
    }
}
