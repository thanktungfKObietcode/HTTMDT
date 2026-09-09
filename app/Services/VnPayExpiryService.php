<?php

namespace App\Services;

use App\Models\Order;
use App\Payments\GatewayEventType;
use App\Payments\ReconciliationResult;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use LogicException;

final class VnPayExpiryService
{
    public function __construct(
        private readonly VnPayReconciliationService $reconciliation,
        private readonly PaymentGatewayJournal $journal,
        private readonly OrderLifecycleService $lifecycle,
    ) {}

    public function candidates(): Builder
    {
        return Order::where('payment_method', 'vnpay')
            ->whereIn('status', ['pending', 'confirmed'])
            ->whereIn('payment_status', ['pending', 'failed'])
            ->whereNotNull('payment_expires_at')->where('payment_expires_at', '<=', now())
            ->orderBy('id');
    }

    public function expire(Order $candidate): string
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Expiry must start outside database transactions.');
        }
        $order = Order::with(['paymentTransactions' => fn ($q) => $q->orderBy('id')])->findOrFail($candidate->id);
        if ($order->payment_status === 'paid'
            || $order->paymentTransactions->contains('payment_status', 'paid')) {
            return 'skipped_paid';
        }
        if (! $this->eligible($order)) {
            return 'skipped_conflict';
        }
        $this->journal->append(GatewayEventType::ExpiryChecked, orderId: $order->id);
        $transactions = $order->paymentTransactions;
        $maxAttempts = max(1, min(50, (int) config('vnpay.expiry_max_attempts', 20)));
        if ($transactions->isEmpty() || $transactions->count() > $maxAttempts) {
            $this->journal->append(GatewayEventType::ExpirySkipped,
                metadata: ['reason' => 'missing_or_excessive_attempts'], conflict: true, orderId: $order->id);

            return 'skipped_conflict';
        }

        $proofs = [];
        foreach ($transactions as $transaction) {
            // Query every attempt, including superseded/failed legacy attempts.
            // No initiation evidence is NOT evidence that no money was collected.
            if ($transaction->gateway !== 'vnpay' || ! $transaction->expires_at
                || $transaction->expires_at->isFuture()) {
                $this->journal->flagUncertainty($transaction, 'attempt_not_expired_or_invalid');

                return 'skipped_conflict';
            }
            $proofs[$transaction->id] = $this->reconciliation->reconcile($transaction);
        }

        return $this->finalize($order, $proofs);
    }

    /** @param array<int, ReconciliationResult> $proofs Verified during THIS run, never cached unpaid evidence. */
    private function finalize(Order $candidate, array $proofs): string
    {
        return DB::transaction(function () use ($candidate, $proofs): string {
            $order = Order::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            $transactions = $order->paymentTransactions()->orderBy('id')->lockForUpdate()->get();
            $skip = function (string $reason, string $result = 'skipped_conflict') use ($order): string {
                $this->journal->append(GatewayEventType::ExpirySkipped, metadata: ['reason' => $reason],
                    conflict: $result === 'skipped_conflict', orderId: $order->id);

                return $result;
            };
            if ($order->payment_status === 'paid' || $transactions->contains('payment_status', 'paid')) {
                return $skip('paid_after_query', 'skipped_paid');
            }
            if (! $this->eligible($order) || $transactions->isEmpty() || $transactions->count() !== count($proofs)) {
                return $skip('state_or_attempts_changed');
            }
            foreach ($transactions as $transaction) {
                $proof = $proofs[$transaction->id] ?? null;
                $event = $proof?->event;
                if (! $proof?->provesUnpaid() || ! $event
                    || $transaction->requiresReconciliation()
                    || $transaction->payment_status !== 'failed' || $transaction->gateway !== 'vnpay'
                    || $transaction->currency !== 'VND' || $event->currency !== 'VND'
                    || ! $transaction->expires_at || $transaction->expires_at->isFuture()
                    || $event->merchantReference !== $transaction->transaction_id
                    || ! Money::equals($event->amount, (string) $transaction->amount)
                    || ! Money::equals($event->amount, (string) $order->total_amount)
                    || ($transaction->gateway_transaction_id !== null
                        && $transaction->gateway_transaction_id !== $event->gatewayTransactionId)) {
                    return $skip('unpaid_not_proven_for_every_attempt');
                }
            }
            // Reentrant same-order locks; ONLY lifecycle owns stock restoration.
            $this->lifecycle->transition($order, OrderLifecycleService::STATUS_CANCELLED,
                'VNPay hết hạn; QueryDR xác minh mọi giao dịch thất bại.');
            $this->journal->append(GatewayEventType::ExpiryCancelled,
                metadata: ['reason' => 'verified_unpaid'], orderId: $order->id);

            return 'cancelled';
        }, 3);
    }

    private function eligible(Order $order): bool
    {
        return $order->payment_method === 'vnpay'
            && in_array($order->status, ['pending', 'confirmed'], true)
            && in_array($order->payment_status, ['pending', 'failed'], true)
            && $order->payment_expires_at !== null && $order->payment_expires_at->lte(now());
    }
}
