<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AutomationRuleState extends Model
{
    protected $table = 'automation_rule_states';

    protected $guarded = ['id'];

    protected $casts = [
        'last_evaluation_at' => 'datetime',
        'last_triggered_at' => 'datetime',
        'last_recovered_at' => 'datetime',
        'suppress_until' => 'datetime',
        'extra_json' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public const STATUS_NORMAL = 'normal';
    public const STATUS_ALERTING = 'alerting';
}
