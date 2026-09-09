<?php

namespace App\Models;

use App\Payments\GatewayEventType;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class PaymentGatewayEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = [
        'event_type' => GatewayEventType::class,
        'signature_valid' => 'boolean',
        'reconciliation_required' => 'boolean',
        'metadata' => 'array',
        'occurred_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        static::updating(static fn () => throw new LogicException('Gateway journal is append-only.'));
        static::deleting(static fn () => throw new LogicException('Gateway journal is append-only.'));
    }
}
