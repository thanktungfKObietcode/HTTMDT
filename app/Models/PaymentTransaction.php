<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentTransaction extends Model
{
    protected $table = 'payment_transactions';

    protected $fillable = [
        'order_id',
        'gateway',
        'transaction_id',
        'gateway_transaction_id',
        'payment_status',
        'amount',
        'currency',
        'gateway_response_code',
        'gateway_transaction_status',
        'paid_at',
        'expires_at',
        'payload',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'expires_at' => 'datetime',
        'payload' => 'array',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function gatewayEvents(): HasMany
    {
        return $this->hasMany(PaymentGatewayEvent::class);
    }

    public function requiresReconciliation(): bool
    {
        return (bool) (($this->payload['vnpay_reconciliation_required'] ?? false)
            || ($this->payload['momo_reconciliation_required'] ?? false));
    }
}
