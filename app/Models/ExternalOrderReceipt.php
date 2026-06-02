<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExternalOrderReceipt extends Model
{
    public const PROVIDER_WOOCOMMERCE = 'woocommerce';

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSED = 'processed';
    public const STATUS_FAILED = 'failed';

    protected $table = 'external_order_receipts';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'payload' => 'array',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    /**
     * Get the local user matched by the third-party order payload.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * Get the local order created from this third-party receipt.
     */
    public function localOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'local_order_id', 'id');
    }
}
