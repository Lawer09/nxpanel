<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NodePerformanceAggregated extends Model
{
    protected $table = 'v2_node_performance_aggregated';

    public $timestamps = false;

    protected $fillable = [
        'date',
        'hour',
        'minute',
        'node_id',
        'client_country',
        'client_city',
        'platform',
        'client_isp',
        'avg_success_rate',
        'avg_delay',
        'total_count',
        'created_at',
    ];

    protected $casts = [
        'date'             => 'date:Y-m-d',
        'hour'             => 'integer',
        'minute'           => 'integer',
        'node_id'          => 'integer',
        'avg_success_rate' => 'float',
        'avg_delay'        => 'float',
        'total_count'      => 'integer',
    ];

    public function node()
    {
        return $this->belongsTo(Server::class, 'node_id', 'id');
    }
}
