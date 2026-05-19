<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AutomationRule extends Model
{
    protected $table = 'automation_rules';

    protected $guarded = ['id'];

    protected $casts = [
        'target_scope_json' => 'array',
        'conditions_json' => 'array',
        'actions_json' => 'array',
        'cooldown_seconds' => 'integer',
        'recovery_enabled' => 'integer',
        'enabled' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public const LOGIC_ALL = 'all';
    public const LOGIC_ANY = 'any';
}
