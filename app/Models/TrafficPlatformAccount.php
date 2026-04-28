<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrafficPlatformAccount extends Model
{
    protected $table = 'traffic_platform_accounts';

    protected $guarded = ['id'];

    protected $casts = [
        'credential_json' => 'array',
        'enabled'         => 'integer',
        'last_sync_at'    => 'datetime',
        'created_at'      => 'datetime',
        'updated_at'      => 'datetime',
    ];

    protected $hidden = ['credential_json'];

    public function platform(): BelongsTo
    {
        return $this->belongsTo(TrafficPlatform::class, 'platform_id');
    }

    /**
     * 获取脱敏后的凭据
     */
    public function getMaskedCredential(): array
    {
        $cred = $this->credential_json;
        if (!is_array($cred)) {
            return [];
        }

        $masked = [];
        foreach ($cred as $key => $value) {
            if (in_array($key, ['secret', 'token', 'password', 'api_key'])) {
                $masked[$key] = $value ? '******' : '';
            } else {
                $masked[$key] = $value;
            }
        }
        return $masked;
    }
}
