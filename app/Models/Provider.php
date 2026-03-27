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
    ];

    protected $casts = [
        'asn_id' => 'integer',
        'reliability' => 'integer',
        'reputation' => 'integer',
        'speed_level' => 'integer',
        'stability' => 'integer',
        'is_active' => 'boolean',
        'regions' => 'json',
        'services' => 'json',
        'metadata' => 'json',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

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