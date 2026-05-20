<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DnsProvider extends Model
{
    protected $table = 'dns_provider';

    protected $guarded = ['id'];

    protected $casts = [
        'request_timeout' => 'integer',
        'rate_limit_per_minute' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function accounts(): HasMany
    {
        return $this->hasMany(DnsProviderAccount::class, 'provider_code', 'name');
    }
}
