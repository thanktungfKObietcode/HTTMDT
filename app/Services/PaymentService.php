<?php

namespace App\Services;

use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\Refund;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PaymentService
{
    public const PAYMENT_PENDING = 'pending';
    public const PAYMENT_PAID = 'paid';
    public const PAYMENT_FAILED = 'failed';
    public const PAYMENT_REFUNDED = 'refunded';

    public function __construct(private readonly OrderLifecycleService $lifecycleService)
    {
    }

    public function createOrGetPendingTransaction(Order $order): PaymentTransaction
    {
        $pending = $order->paymentTransactions()
            ->where('gateway', $order->payment_method ?: 'cod')
            ->where('payment_status', self::PAYMENT_PENDING)
            ->latest('id')
            ->first();

        if ($pending) {
            return $pending;
        }

        return $order->paymentTransactions()->create([
            'gateway' => $order->payment_method ?: 'cod',
            'transaction_id' => $this->generateTransactionId($order),
            'payment_status' => self::PAYMENT_PENDING,
            'amount' => (float) $order->total_amount,
            'payload' => [
                'source' => 'order_checkout',
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function handleCallback(array $payload): PaymentTransaction
    {
        $order = Order::where('order_number', $payload['order_number'])->first();
        if (! $order) {
            throw new RuntimeException('Don hang khong ton tai.');
        }

        $status = strtolower((string) $payload['payment_status']);
        if (! in_array($status, [self::PAYMENT_PAID, self::PAYMENT_FAILED], true)) {
            throw new RuntimeException('Trang thai thanh toan khong hop le.');
        }

        $transaction = $order->paymentTransactions()
            ->where('transaction_id', $payload['transaction_id'])
            ->latest('id')
            ->first();

        if (! $transaction) {
            throw new RuntimeException('Khong tim thay giao dich thanh toan.');
        }

        $serverAmount = (float) $order->total_amount;
        $callbackAmount = (float) $payload['amount'];
        if (abs($serverAmount - $callbackAmount) > 0.0001) {
            throw new RuntimeException('So tien thanh toan khong hop le.');
        }

        if ($transaction->payment_status === $status) {
            return $transaction;
        }

        if ($transaction->payment_status === self::PAYMENT_PAID && $status === self::PAYMENT_FAILED) {
            throw new RuntimeException('Giao dich da thanh cong, khong the chuyen sang that bai.');
        }

        return DB::transaction(function () use ($payload, $order, $transaction, $status) {
            $transaction->payment_status = $status;
            $transaction->payload = array_merge($transaction->payload ?? [], [
                'callback_payload' => $payload,
                'callback_at' => now()->toDateTimeString(),
            ]);
            $transaction->save();

            if ($status === self::PAYMENT_PAID) {
                $order->payment_status = self::PAYMENT_PAID;
                if (! $order->paid_at) {
                    $order->paid_at = now();
                }
                $order->save();

                if ($order->status === OrderLifecycleService::STATUS_PENDING) {
                    $this->lifecycleService->transition(
                        $order,
                        OrderLifecycleService::STATUS_CONFIRMED,
                        'Thanh toan thanh cong qua callback',
                        null
                    );
                }
            }

            if ($status === self::PAYMENT_FAILED) {
                $order->payment_status = self::PAYMENT_FAILED;
                $order->save();
            }

            return $transaction;
        });
    }

    public function requestRefund(Order $order, float $amount, ?string $reason, ?int $actorId): Refund
    {
        if (! in_array((string) $order->payment_status, [self::PAYMENT_PAID, self::PAYMENT_REFUNDED], true)) {
            throw new RuntimeException('Don hang chua du dieu kien hoan tien.');
        }

        if ($amount <= 0) {
            throw new RuntimeException('So tien hoan phai lon hon 0.');
        }

        $alreadyRefunded = (float) $order->refunds()->sum('amount');
        $maxRefundable = max(0, (float) $order->total_amount - $alreadyRefunded);
        if ($amount > $maxRefundable) {
            throw new RuntimeException('So tien hoan vuot qua gioi han cho phep.');
        }

        $transaction = $order->paymentTransactions()->where('payment_status', self::PAYMENT_PAID)->latest('id')->first();

        return DB::transaction(function () use ($order, $transaction, $amount, $reason, $actorId, $maxRefundable) {
            $refund = $order->refunds()->create([
                'payment_transaction_id' => $transaction?->id,
                'amount' => $amount,
                'reason' => $reason,
                'status' => 'completed',
            ]);

            $remaining = $maxRefundable - $amount;

            if ($remaining <= 0.0001) {
                $order->payment_status = self::PAYMENT_REFUNDED;
                $order->save();

                if ($this->lifecycleService->canTransition((string) $order->status, OrderLifecycleService::STATUS_REFUNDED)) {
                    $this->lifecycleService->transition(
                        $order,
                        OrderLifecycleService::STATUS_REFUNDED,
                        'Don hang da duoc hoan tien toan bo',
                        $actorId
                    );
                }
            }

            return $refund;
        });
    }

    private function generateTransactionId(Order $order): string
    {
        return 'TXN-' . $order->id . '-' . now()->format('YmdHis') . '-' . random_int(1000, 9999);
    }
}
