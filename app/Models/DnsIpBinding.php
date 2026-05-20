<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DnsIpBinding extends Model
{
    protected $table = 'dns_ip_bindings';

    protected $guarded = ['id'];

    protected $casts = [
        'ttl' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function providerAccount(): BelongsTo
    {
        return $this->belongsTo(DnsProviderAccount::class, 'provider_account_id');
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(DnsDomain::class, 'domain_id');
    }
}
