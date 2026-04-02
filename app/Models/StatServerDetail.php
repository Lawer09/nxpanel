<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * App\Models\StatServerDetail
 *
 * 节点流量分钟级明细，每分钟一条，按分钟累加上报数据。
 *
 * @property int    $id
 * @property int    $server_id    节点 ID
 * @property string $server_type  节点协议类型
 * @property int    $u            上行流量（字节）
 * @property int    $d            下行流量（字节）
 * @property int    $year         年，如 2026
 * @property int    $month        月，1–12
 * @property int    $day          日，1–31
 * @property int    $hour         时，0–23
 * @property int    $minute       分，0–59
 * @property int    $record_at    分钟级 Unix 时间戳（floor 到分钟整点）
 * @property int    $created_at
 * @property int    $updated_at
 */
class StatServerDetail extends Model
{
    protected $table      = 'v2_stat_server_detail';
    protected $dateFormat = 'U';
    protected $guarded    = ['id'];
    protected $casts      = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    public function server()
    {
        return $this->belongsTo(Server::class, 'server_id');
    }
}
