<?php

namespace App\Services;

use App\Models\Order;
use App\Models\PaymentGatewayEvent;
use App\Models\PaymentTransaction;
use App\Payments\GatewayEventType;
use App\Payments\VerifiedPaymentEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class PaymentGatewayJournal
{
    /** Only domain-owned scalar fields may enter the journal, never a request payload. */
    public function append(
        GatewayEventType $type,
        ?PaymentTransaction $transaction = null,
        ?VerifiedPaymentEvent $event = null,
        array $metadata = [],
        bool $conflict = false,
        ?int $orderId = null,
        ?string $gateway = null,
    ): PaymentGatewayEvent {
        $safe = [];
        foreach (['outcome', 'reason', 'request_id', 'actor_id', 'amount', 'currency', 'expires_at',
            'refund_id', 'refund_order_id', 'refund_trans_id'] as $key) {
            $value = $metadata[$key] ?? null;
            if (is_string($value) || is_int($value)) {
                // No URL, hash, free text, arrays or PII are accepted.
                if (preg_match('/^[A-Za-z0-9_.:+ -]{1,100}$/D', (string) $value) === 1) {
                    $safe[$key] = (string) $value;
                }
            }
        }
        if ($event) {
            $safe['amount'] = $event->amount;
            $safe['currency'] = $event->currency;
        }
        ksort($safe);
        $fields = [
            'gateway' => $event?->gateway ?? $transaction?->gateway ?? $gateway ?? 'vnpay',
            'event_type' => $type->value,
            'merchant_reference' => $event?->merchantReference ?? $transaction?->transaction_id,
            'gateway_transaction_id' => $event?->gatewayTransactionId ?? $transaction?->gateway_transaction_id,
            'signature_valid' => $event !== null,
            'response_code' => $event?->responseCode,
            'transaction_status' => $event?->transactionStatus,
            'reconciliation_required' => $conflict,
            'metadata' => $safe,
        ];

        return PaymentGatewayEvent::create($fields + [
            'payment_transaction_id' => $transaction?->id,
            'order_id' => $transaction?->order_id ?? $orderId,
            'event_fingerprint' => hash('sha256', json_encode($fields, JSON_THROW_ON_ERROR)),
            'occurred_at' => $event?->occurredAt?->setTimezone(new \DateTimeZone(config('app.timezone'))),
        ]);
    }

    /** Observations cannot settle or prevent a valid IPN from reaching settlement. */
    public function observe(GatewayEventType $type, ?VerifiedPaymentEvent $event = null, array $metadata = [], ?string $gateway = null): void
    {
        try {
            $this->append($type, event: $event, metadata: $metadata, gateway: $gateway);
        } catch (Throwable) {
            Log::warning('Gateway observation could not be recorded.', ['event_type' => $type->value]);
        }
    }

    /** Transport/protocol uncertainty: lock in the same order as settlement. */
    public function flagUncertainty(PaymentTransaction $target, string $reason): void
    {
        DB::transaction(function () use ($target, $reason): void {
            $order = Order::whereKey($target->order_id)->lockForUpdate()->firstOrFail();
            $transactions = $order->paymentTransactions()->orderBy('id')->lockForUpdate()->get();
            $transaction = $transactions->firstWhere('id', $target->id);
            if (! $transaction) {
                return;
            }
            $payload = $transaction->payload ?? [];
            $prefix = $transaction->gateway === 'momo' ? 'momo' : 'vnpay';
            $requiredKey = $prefix.'_reconciliation_required';
            $reasonKey = $prefix.'_reconciliation_reason';
            // Do not replace an older, stronger financial conflict with a transient one.
            if (! ($payload[$requiredKey] ?? false)) {
                $payload[$requiredKey] = true;
                $payload[$reasonKey] = 'query_uncertain';
                $transaction->payload = $payload;
                $transaction->save();
            }
            $this->append(GatewayEventType::ReconciliationRequired, $transaction,
                metadata: ['reason' => $reason], conflict: true);
        }, 3);
    }
}
