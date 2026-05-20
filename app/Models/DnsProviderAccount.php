<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DnsProviderAccount extends Model
{
    protected $table = 'dns_provider_accounts';

    protected $guarded = ['id'];

    protected $casts = [
        'config_json' => 'array',
        'last_synced_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function provider(): BelongsTo
    {
        return $this->belongsTo(DnsProvider::class, 'provider_code', 'name');
    }

    public function domains(): HasMany
    {
        return $this->hasMany(DnsDomain::class, 'provider_account_id');
    }

    public function ipBindings(): HasMany
    {
        return $this->hasMany(DnsIpBinding::class, 'provider_account_id');
    }
}
