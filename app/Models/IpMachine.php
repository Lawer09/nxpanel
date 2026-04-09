<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class IpMachine extends Pivot
{
    protected $table = 'ip_machine';
    
    public $incrementing = true;

    protected $fillable = [
        'ip_id',
        'machine_id',
        'is_primary',
        'is_egress',
        'bind_status',
        'bound_at',
        'unbound_at',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'is_egress' => 'boolean',
        'bound_at' => 'datetime',
        'unbound_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * 关联到IP
     */
    public function ip()
    {
        return $this->belongsTo(IpPool::class, 'ip_id');
    }

    /**
     * 关联到机器
     */
    public function machine()
    {
        return $this->belongsTo(Machine::class, 'machine_id');
    }

    /**
     * 设置为主IP
     */
    public function setPrimary()
    {
        // 先将该机器的其他IP设为非主IP
        static::where('machine_id', $this->machine_id)
            ->where('id', '!=', $this->id)
            ->update(['is_primary' => false]);

        // 设置当前为主IP
        $this->update(['is_primary' => true]);
    }

    /**
     * 激活绑定
     */
    public function activate()
    {
        $this->update([
            'bind_status' => 'active',
            'bound_at' => now(),
            'unbound_at' => null,
        ]);
    }

    /**
     * 停用绑定
     */
    public function deactivate()
    {
        $this->update([
            'bind_status' => 'inactive',
            'unbound_at' => now(),
        ]);
    }
}