<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderStatusHistory;
use RuntimeException;

class OrderLifecycleService
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SHIPPED = 'shipped';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_REFUNDED = 'refunded';

    /** @var array<string, array<int, string>> */
    private array $transitions = [
        self::STATUS_PENDING => [self::STATUS_CONFIRMED, self::STATUS_CANCELLED],
        self::STATUS_CONFIRMED => [self::STATUS_PROCESSING, self::STATUS_CANCELLED],
        self::STATUS_PROCESSING => [self::STATUS_SHIPPED, self::STATUS_CANCELLED],
        self::STATUS_SHIPPED => [self::STATUS_DELIVERED],
        self::STATUS_DELIVERED => [self::STATUS_REFUNDED],
        self::STATUS_CANCELLED => [],
        self::STATUS_REFUNDED => [],
    ];

    public function canTransition(string $fromStatus, string $toStatus): bool
    {
        if ($fromStatus === $toStatus) {
            return true;
        }

        return in_array($toStatus, $this->transitions[$fromStatus] ?? [], true);
    }

    public function transition(Order $order, string $toStatus, ?string $note = null, ?int $changedBy = null): Order
    {
        if (! $this->canTransition((string) $order->status, $toStatus)) {
            throw new RuntimeException('Chuyen trang thai don hang khong hop le.');
        }

        $fromStatus = $order->status;
        $order->status = $toStatus;

        if ($toStatus === self::STATUS_SHIPPED && ! $order->shipped_at) {
            $order->shipped_at = now();
        }

        if ($toStatus === self::STATUS_DELIVERED && ! $order->delivered_at) {
            $order->delivered_at = now();
        }

        $order->save();

        OrderStatusHistory::create([
            'order_id' => $order->id,
            'status' => $toStatus,
            'note' => $note ?: ('Cap nhat trang thai tu ' . $fromStatus . ' sang ' . $toStatus),
            'changed_by' => $changedBy,
        ]);

        return $order;
    }
}
