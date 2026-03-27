<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NodePerformanceReport extends Model
{
    protected $table = 'v2_node_performance_report';
    public $timestamps = true;

    protected $fillable = [
        'user_id',
        'node_id',
        'delay',
        'success_rate',
        'client_ip',
        'client_country',
        'client_city',
        'client_isp',
        'user_agent',
        'platform',
        'app_version',
        'metadata',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'node_id' => 'integer',
        'delay' => 'integer',
        'success_rate' => 'integer',
        'metadata' => 'json',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    /**
     * 获取用户关系
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * 获取节点关系
     */
    public function node()
    {
        return $this->belongsTo(Server::class, 'node_id', 'id');
    }

    /**
     * 获取用户最近的性能数据
     */
    public static function getLatestByUser($userId, $limit = 100)
    {
        return self::where('user_id', $userId)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * 获取节点的平均性能
     */
    public static function getNodeAveragePerformance($nodeId, $days = 7)
    {
        $startDate = now()->subDays($days)->startOfDay();

        return self::where('node_id', $nodeId)
            ->where('created_at', '>=', $startDate)
            ->selectRaw('
                AVG(delay) as avg_delay,
                MIN(delay) as min_delay,
                MAX(delay) as max_delay,
                AVG(success_rate) as avg_success_rate,
                COUNT(*) as report_count
            ')
            ->first();
    }
}