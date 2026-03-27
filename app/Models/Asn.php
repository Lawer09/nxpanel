<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Asn extends Model
{
    protected $table = 'v2_asns';
    public $timestamps = true;

    protected $fillable = [
        'asn',
        'name',
        'description',
        'country',
        'type',
        'is_datacenter',
        'reliability',
        'reputation',
        'metadata',
    ];

    protected $casts = [
        'is_datacenter' => 'boolean',
        'reliability' => 'integer',
        'reputation' => 'integer',
        'metadata' => 'json',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    /**
     * 关联的提供商
     */
    public function providers()
    {
        return $this->hasMany(Provider::class, 'asn_id');
    }

    /**
     * 按国家统计
     */
    public static function getStatsByCountry()
    {
        return self::selectRaw('country, COUNT(*) as count')
            ->whereNotNull('country')
            ->groupBy('country')
            ->orderByDesc('count')
            ->get();
    }

    /**
     * 获取高可靠性ASN
     */
    public static function getReliable($minReliability = 80)
    {
        return self::where('reliability', '>=', $minReliability)
            ->orderByDesc('reliability')
            ->get();
    }
}