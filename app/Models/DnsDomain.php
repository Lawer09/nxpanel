<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DnsDomain extends Model
{
    protected $table = 'dns_domains';

    protected $guarded = ['id'];

    protected $casts = [
        'is_available' => 'integer',
        'last_synced_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function providerAccount(): BelongsTo
    {
        return $this->belongsTo(DnsProviderAccount::class, 'provider_account_id');
    }

    public function ipBindings(): HasMany
    {
        return $this->hasMany(DnsIpBinding::class, 'domain_id');
    }
}
