<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Refund;
use App\Support\Money;
use App\Support\PaymentMethod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
            return false;
        }

        return in_array($toStatus, $this->transitions[$fromStatus] ?? [], true);
    }

    public function transition(Order $order, string $toStatus, ?string $note = null, ?int $changedBy = null): Order
    {
        if ($toStatus === self::STATUS_REFUNDED) {
            throw new RuntimeException('Trạng thái refunded chỉ được cập nhật qua refund workflow đã hoàn tất.');
        }

        return $this->performTransition($order, $toStatus, $note, $changedBy);
    }

    public function transitionAsCustomer(Order $order, string $toStatus, ?string $note = null, ?int $changedBy = null): Order
    {
        $allowedFromStatuses = match ($toStatus) {
            self::STATUS_CANCELLED => [self::STATUS_PENDING, self::STATUS_CONFIRMED],
            self::STATUS_DELIVERED => [self::STATUS_SHIPPED],
            default => throw new RuntimeException('Khách hàng không được phép thực hiện chuyển trạng thái này.'),
        };

        return $this->performTransition(
            $order,
            $toStatus,
            $note,
            $changedBy,
            $allowedFromStatuses
        );
    }

    public function transitionAfterCompletedRefund(
        Order $order,
        ?string $note = null,
        ?int $changedBy = null
    ): Order {
        return $this->performTransition(
            $order,
            self::STATUS_REFUNDED,
            $note,
            $changedBy,
            null,
            true
        );
    }

    /**
     * @param  array<int, string>|null  $allowedFromStatuses
     */
    private function performTransition(
        Order $order,
        string $toStatus,
        ?string $note,
        ?int $changedBy,
        ?array $allowedFromStatuses = null,
        bool $completedRefundTransition = false
    ): Order {
        return DB::transaction(function () use (
            $order,
            $toStatus,
            $note,
            $changedBy,
            $allowedFromStatuses,
            $completedRefundTransition
        ): Order {
            $lockedOrder = Order::query()
                ->whereKey($order->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $transactions = $this->lockPaymentTransactions($lockedOrder->id);
            $refunds = $completedRefundTransition
                ? $this->lockRefunds($lockedOrder->id)
                : collect();
            $fromStatus = (string) $lockedOrder->status;

            if ($fromStatus === $toStatus) {
                return $lockedOrder;
            }

            if ($allowedFromStatuses !== null && ! in_array($fromStatus, $allowedFromStatuses, true)) {
                throw new RuntimeException('Khách hàng không được phép chuyển đơn hàng từ trạng thái hiện tại.');
            }

            if (! $this->canTransition($fromStatus, $toStatus)) {
                throw new RuntimeException('Chuyển trạng thái đơn hàng không hợp lệ.');
            }

            $codCollected = false;

            if ($toStatus === self::STATUS_REFUNDED) {
                if (! $completedRefundTransition) {
                    throw new RuntimeException('Trạng thái refunded chỉ được cập nhật qua refund workflow đã hoàn tất.');
                }

                $this->assertCompletedFinancialRefund($lockedOrder, $transactions, $refunds);
            } elseif ($toStatus === self::STATUS_CANCELLED) {
                $this->assertCancellationIsFinanciallySafe($lockedOrder, $transactions);
                $this->closePendingPaymentAttempts($transactions);
                $this->restoreInventory($lockedOrder);
                $lockedOrder->payment_status = PaymentService::PAYMENT_FAILED;
            } else {
                $this->assertFulfillmentPaymentState($lockedOrder, $toStatus, $transactions);

                if ($toStatus === self::STATUS_SHIPPED && ! $lockedOrder->shipped_at) {
                    $lockedOrder->shipped_at = now();
                }

                if ($toStatus === self::STATUS_DELIVERED) {
                    if (PaymentMethod::isCod($this->paymentMethod($lockedOrder))) {
                        $codCollected = $this->settleCodOnDelivery($lockedOrder, $transactions);
                    }

                    if (! $lockedOrder->delivered_at) {
                        $lockedOrder->delivered_at = now();
                    }
                }
            }

            $lockedOrder->status = $toStatus;
            $lockedOrder->save();

            $historyNote = $note ?: ('Cập nhật trạng thái từ '.$fromStatus.' sang '.$toStatus);
            if ($codCollected) {
                $historyNote .= ' Thanh toán COD đã được ghi nhận khi giao hàng.';
            }

            OrderStatusHistory::create([
                'order_id' => $lockedOrder->id,
                'status' => $toStatus,
                'note' => $historyNote,
                'changed_by' => $changedBy,
            ]);

            return $lockedOrder;
        }, 3);
    }

    /** @return Collection<int, PaymentTransaction> */
    private function lockPaymentTransactions(int $orderId): Collection
    {
        return PaymentTransaction::query()
            ->where('order_id', $orderId)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /** @return Collection<int, Refund> */
    private function lockRefunds(int $orderId): Collection
    {
        return Refund::query()
            ->where('order_id', $orderId)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /** @param Collection<int, PaymentTransaction> $transactions */
    private function assertCancellationIsFinanciallySafe(Order $order, Collection $transactions): void
    {
        if ($transactions->contains(fn (PaymentTransaction $transaction): bool =>
            $transaction->gateway === PaymentMethod::VNPAY && $transaction->requiresReconciliation())) {
            throw new RuntimeException('Giao dịch VNPay cần đối soát; chưa thể hủy đơn hàng an toàn.');
        }

        if (in_array((string) $order->payment_status, [PaymentService::PAYMENT_PAID, PaymentService::PAYMENT_REFUNDED], true)
            || $transactions->contains(fn (PaymentTransaction $transaction): bool => in_array(
                (string) $transaction->payment_status,
                [PaymentService::PAYMENT_PAID, PaymentService::PAYMENT_REFUNDED],
                true
            ))) {
            throw new RuntimeException('Đơn hàng đã thanh toán không thể hủy trực tiếp; vui lòng sử dụng quy trình hoàn tiền.');
        }

        if (! in_array((string) $order->payment_status, [PaymentService::PAYMENT_PENDING, PaymentService::PAYMENT_FAILED], true)
            || $transactions->contains(fn (PaymentTransaction $transaction): bool => ! in_array(
                (string) $transaction->payment_status,
                [PaymentService::PAYMENT_PENDING, PaymentService::PAYMENT_FAILED],
                true
            ))) {
            throw new RuntimeException('Trạng thái thanh toán không cho phép hủy đơn hàng an toàn.');
        }
    }

    /** @param Collection<int, PaymentTransaction> $transactions */
    private function closePendingPaymentAttempts(Collection $transactions): void
    {
        foreach ($transactions as $transaction) {
            if ((string) $transaction->payment_status !== PaymentService::PAYMENT_PENDING) {
                continue;
            }

            $transaction->payment_status = PaymentService::PAYMENT_FAILED;
            $transaction->payload = array_merge($transaction->payload ?? [], [
                'closed_reason' => 'order_cancelled',
                'closed_at' => now()->toDateTimeString(),
            ]);
            $transaction->save();
        }
    }

    /** @param Collection<int, PaymentTransaction> $transactions */
    private function assertFulfillmentPaymentState(Order $order, string $toStatus, Collection $transactions): void
    {
        if (! in_array($toStatus, [
            self::STATUS_CONFIRMED,
            self::STATUS_PROCESSING,
            self::STATUS_SHIPPED,
            self::STATUS_DELIVERED,
        ], true)) {
            return;
        }

        $method = $this->paymentMethod($order);

        if (PaymentMethod::isCod($method)) {
            if (! in_array((string) $order->payment_status, [PaymentService::PAYMENT_PENDING, PaymentService::PAYMENT_PAID], true)) {
                throw new RuntimeException('Trạng thái thanh toán COD không cho phép tiếp tục xử lý đơn hàng.');
            }

            if ((string) $order->payment_status === PaymentService::PAYMENT_PAID) {
                $this->assertSinglePaidTransaction($order, $transactions, $method);
            } elseif ($transactions->contains(fn (PaymentTransaction $transaction): bool => in_array(
                (string) $transaction->payment_status,
                [PaymentService::PAYMENT_PAID, PaymentService::PAYMENT_REFUNDED],
                true
            ))) {
                throw new RuntimeException('Trạng thái giao dịch COD không nhất quán với đơn hàng.');
            }

            return;
        }

        if (! PaymentMethod::isOnline($method)) {
            throw new RuntimeException('Phương thức thanh toán của đơn hàng không được hỗ trợ.');
        }

        $this->assertSinglePaidTransaction($order, $transactions, $method);
    }

    /** @param Collection<int, PaymentTransaction> $transactions */
    private function assertSinglePaidTransaction(Order $order, Collection $transactions, string $method): PaymentTransaction
    {
        if ((string) $order->payment_status !== PaymentService::PAYMENT_PAID) {
            throw new RuntimeException('Đơn hàng chưa được thanh toán nên không thể tiếp tục fulfillment.');
        }

        if ($transactions->contains(fn (PaymentTransaction $transaction): bool =>
            (string) $transaction->payment_status === PaymentService::PAYMENT_REFUNDED)) {
            throw new RuntimeException('Đơn hàng có giao dịch đã hoàn tiền nên không thể tiếp tục fulfillment.');
        }

        $paidTransactions = $transactions
            ->filter(fn (PaymentTransaction $transaction): bool => (string) $transaction->payment_status === PaymentService::PAYMENT_PAID)
            ->values();

        if ($paidTransactions->count() !== 1) {
            throw new RuntimeException('Đơn hàng phải có đúng một giao dịch thanh toán thành công.');
        }

        /** @var PaymentTransaction $transaction */
        $transaction = $paidTransactions->first();

        if (strtolower((string) $transaction->gateway) !== $method
            || ! Money::equals((string) $transaction->amount, (string) $order->total_amount)) {
            throw new RuntimeException('Giao dịch thanh toán không khớp với đơn hàng.');
        }

        return $transaction;
    }

    /** @param Collection<int, PaymentTransaction> $transactions */
    private function settleCodOnDelivery(Order $order, Collection $transactions): bool
    {
        if ((string) $order->payment_status === PaymentService::PAYMENT_PAID) {
            $this->assertSinglePaidTransaction($order, $transactions, PaymentService::PAYMENT_METHOD_COD);
            $this->closeOtherPendingAttempts($transactions, null);
            $order->paid_at ??= now();

            return false;
        }

        if ((string) $order->payment_status !== PaymentService::PAYMENT_PENDING) {
            throw new RuntimeException('Đơn COD không ở trạng thái có thể ghi nhận thu tiền.');
        }

        $eligible = $transactions
            ->filter(fn (PaymentTransaction $transaction): bool =>
                (string) $transaction->payment_status === PaymentService::PAYMENT_PENDING
                && strtolower((string) $transaction->gateway) === PaymentService::PAYMENT_METHOD_COD
                && Money::equals((string) $transaction->amount, (string) $order->total_amount))
            ->values();

        if ($eligible->isEmpty()) {
            throw new RuntimeException('Không tìm thấy giao dịch COD pending hợp lệ để ghi nhận thu tiền.');
        }

        /** @var PaymentTransaction $transaction */
        $transaction = $eligible->first();
        $transaction->payment_status = PaymentService::PAYMENT_PAID;
        $transaction->paid_at ??= now();
        $transaction->payload = array_merge($transaction->payload ?? [], [
            'cod_collected_at' => now()->toDateTimeString(),
            'source' => 'delivery_confirmation',
        ]);
        $transaction->save();
        $this->closeOtherPendingAttempts($transactions, (int) $transaction->id);

        $order->payment_status = PaymentService::PAYMENT_PAID;
        $order->paid_at ??= now();

        return true;
    }

    /** @param Collection<int, PaymentTransaction> $transactions */
    private function closeOtherPendingAttempts(Collection $transactions, ?int $settledTransactionId): void
    {
        foreach ($transactions as $transaction) {
            if ((int) $transaction->id === $settledTransactionId
                || (string) $transaction->payment_status !== PaymentService::PAYMENT_PENDING) {
                continue;
            }

            $transaction->payment_status = PaymentService::PAYMENT_FAILED;
            $transaction->payload = array_merge($transaction->payload ?? [], [
                'closed_reason' => 'another_attempt_settled',
                'closed_at' => now()->toDateTimeString(),
            ]);
            $transaction->save();
        }
    }

    /**
     * @param Collection<int, PaymentTransaction> $transactions
     * @param Collection<int, Refund> $refunds
     */
    private function assertCompletedFinancialRefund(Order $order, Collection $transactions, Collection $refunds): void
    {
        if ((string) $order->payment_status !== PaymentService::PAYMENT_REFUNDED) {
            throw new RuntimeException('Đơn hàng chưa hoàn tất hoàn tiền tài chính.');
        }

        $refundedTransactions = $transactions
            ->filter(fn (PaymentTransaction $transaction): bool => (string) $transaction->payment_status === PaymentService::PAYMENT_REFUNDED)
            ->values();

        if ($refundedTransactions->count() !== 1) {
            throw new RuntimeException('Không tìm thấy giao dịch đã hoàn tiền duy nhất.');
        }

        /** @var PaymentTransaction $transaction */
        $transaction = $refundedTransactions->first();
        $completedAmount = $refunds
            ->filter(fn (Refund $refund): bool =>
                (int) $refund->payment_transaction_id === (int) $transaction->id
                && (string) $refund->status === Refund::STATUS_COMPLETED)
            ->reduce(
                fn (int $total, Refund $refund): int => $total + Money::toMinorUnits((string) $refund->amount),
                0
            );

        if (! Money::equals((string) $transaction->amount, (string) $order->total_amount)
            || $completedAmount !== Money::toMinorUnits((string) $transaction->amount)) {
            throw new RuntimeException('Số tiền hoàn tất không khớp với giao dịch của đơn hàng.');
        }
    }

    private function paymentMethod(Order $order): string
    {
        return strtolower((string) ($order->payment_method ?: PaymentService::PAYMENT_METHOD_COD));
    }

    private function restoreInventory(Order $order): void
    {
        $items = $order->items()
            ->orderBy('product_id')
            ->orderByRaw('product_variant_id IS NULL')
            ->orderBy('product_variant_id')
            ->lockForUpdate()
            ->get();

        foreach ($items as $item) {
            $quantity = (int) $item->quantity;

            if ($quantity < 1) {
                throw new RuntimeException('So luong san pham trong don hang khong hop le.');
            }

            $product = Product::query()
                ->whereKey($item->product_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($item->product_variant_id) {
                $variant = ProductVariant::query()
                    ->whereKey($item->product_variant_id)
                    ->where('product_id', $item->product_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $variant->increment('stock', $quantity);
                continue;
            }

            $product->increment('stock', $quantity);
        }
    }
}
