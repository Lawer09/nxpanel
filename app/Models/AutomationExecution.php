<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AutomationExecution extends Model
{
    protected $table = 'automation_executions';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'metrics_snapshot' => 'array',
        'matched_conditions' => 'array',
        'actions_snapshot' => 'array',
        'action_results' => 'array',
        'executed_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public const STATUS_TRIGGERED = 'triggered';
    public const STATUS_RECOVERED = 'recovered';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_FAILED = 'failed';
}
