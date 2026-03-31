<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 节点部署任务记录
 *
 * @property int         $id
 * @property int|null    $batch_id      批次ID（同一次批量部署共享）
 * @property int         $machine_id    目标机器ID
 * @property int|null    $server_id     成功后关联的节点ID
 * @property string      $status        pending/running/success/failed
 * @property array       $deploy_config 本次部署配置快照
 * @property string|null $output        SSH 执行输出
 * @property \Carbon\Carbon|null $started_at
 * @property \Carbon\Carbon|null $finished_at
 * @property \Carbon\Carbon      $created_at
 * @property \Carbon\Carbon      $updated_at
 */
class NodeDeployTask extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED  = 'failed';

    protected $table = 'node_deploy_tasks';

    protected $fillable = [
        'batch_id',
        'machine_id',
        'server_id',
        'status',
        'deploy_config',
        'output',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'deploy_config' => 'array',
        'started_at'    => 'datetime',
        'finished_at'   => 'datetime',
    ];

    public function machine()
    {
        return $this->belongsTo(Machine::class, 'machine_id');
    }

    public function server()
    {
        return $this->belongsTo(Server::class, 'server_id');
    }
}
