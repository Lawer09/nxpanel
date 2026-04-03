<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Provider extends Model
{
    protected $table = 'v2_providers';
    public $timestamps = true;

    protected $fillable = [
        'name',
        'description',
        'website',
        'email',
        'phone',
        'country',
        'type',
        'asn_id',
        'asn',
        'reliability',
        'reputation',
        'speed_level',
        'stability',
        'is_active',
        'regions',
        'services',
        'metadata',
        // 云驱动相关
        'driver',
        'api_credentials',
        'supported_operations',
    ];

    protected $casts = [
        'asn_id'               => 'integer',
        'reliability'          => 'integer',
        'reputation'           => 'integer',
        'speed_level'          => 'integer',
        'stability'            => 'integer',
        'is_active'            => 'boolean',
        'regions'              => 'json',
        'services'             => 'json',
        'metadata'             => 'json',
        'supported_operations' => 'json',
        'created_at'           => 'timestamp',
        'updated_at'           => 'timestamp',
    ];

    /** api_credentials 不在 hidden 中，但通过 accessor 加解密，序列化时隐藏原始密文 */
    protected $hidden = ['api_credentials'];

    /**
     * 读取时解密 api_credentials
     */
    public function getApiCredentialsAttribute(mixed $value): array
    {
        if (empty($value)) {
            return [];
        }
        try {
            return json_decode(decrypt($value), true) ?? [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * 写入时加密 api_credentials
     */
    public function setApiCredentialsAttribute(mixed $value): void
    {
        if (empty($value)) {
            $this->attributes['api_credentials'] = null;
            return;
        }
        $json = is_array($value) ? json_encode($value) : $value;
        $this->attributes['api_credentials'] = encrypt($json);
    }

    /**
     * 关联的ASN
     */
    public function asn()
    {
        return $this->belongsTo(Asn::class, 'asn_id');
    }

    /**
     * 获取活跃的提供商
     */
    public static function getActive()
    {
        return self::where('is_active', true)
            ->orderByDesc('reliability')
            ->get();
    }

    /**
     * 按类型统计
     */
    public static function getStatsByType()
    {
        return self::selectRaw('type, COUNT(*) as count')
            ->whereNotNull('type')
            ->groupBy('type')
            ->orderByDesc('count')
            ->get();
    }

    /**
     * 按国家统计
     */
    public static function getStatsByCountry()
    {
        return self::selectRaw('country, COUNT(*) as count')
            ->whereNotNull('country')
            ->where('is_active', true)
            ->groupBy('country')
            ->orderByDesc('count')
            ->get();
    }
}