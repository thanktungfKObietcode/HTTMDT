<?php

namespace App\Services;

use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Payments\GatewayEventType;
use App\Payments\MoMoGateway;
use App\Payments\VerifiedPaymentEvent;
use App\Support\Money;
use App\Support\PaymentMethod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class MoMoPaymentService
{
    public function __construct(private readonly MoMoGateway $gateway, private readonly PaymentService $payments,
        private readonly PaymentGatewayJournal $journal) {}

    public function paymentUrl(Order $order, int $ownerId): string
    {
        if (DB::transactionLevel() !== 0) {
            throw new RuntimeException('MoMo create must run outside database transactions.');
        }
        $this->gateway->assertConfigured();
        $order = $order->fresh();
        if (! $order || (int) $order->user_id !== $ownerId || ! $this->payments->canInitiateMoMo($order)) {
            throw new RuntimeException('Order cannot start MoMo payment.');
        }
        $transaction = $this->payments->createOrGetPendingTransaction($order, $ownerId);
        $order->refresh();
        if (! $this->payments->canInitiateMoMo($order) || $transaction->gateway !== PaymentMethod::MOMO
            || $transaction->payment_status !== PaymentService::PAYMENT_PENDING
            || $transaction->currency !== 'VND'
            || ! Money::equals((string) $order->total_amount, (string) $transaction->amount)) {
            throw new RuntimeException('MoMo payment attempt is no longer eligible.');
        }
        $requestId = $transaction->payload['momo_request_id'] ?? null;
        if (! is_string($requestId) || preg_match('/^[A-Za-z0-9_-]{1,50}$/D', $requestId) !== 1) {
            throw new RuntimeException('MoMo payment request identity is missing.');
        }
        $payload = $this->gateway->createPayload((string) $transaction->transaction_id,
            $requestId, (string) $order->total_amount);
        try {
            $response = Http::acceptJson()->asJson()->connectTimeout(3)
                ->timeout((int) config('momo.timeout_seconds'))->withoutRedirecting()
                ->post((string) config('momo.create_url'), $payload);
        } catch (\Throwable) {
            throw new RuntimeException('MoMo create status is uncertain; retry or reconcile this attempt.');
        }
        if (! $response->successful() || ! is_array($response->json())) {
            throw new RuntimeException('MoMo create status is uncertain; retry or reconcile this attempt.');
        }
        $url = $this->gateway->verifiedPayUrl($response->json(), (string) $transaction->transaction_id,
            $requestId, $this->gateway->amount((string) $transaction->amount));
        $this->journal->append(GatewayEventType::PaymentInitiated, $transaction,
            metadata: ['amount' => (string) $transaction->amount, 'currency' => 'VND']);
        return $url;
    }

    /** Read-only: the browser redirect never settles money. */
    public function returnResult(VerifiedPaymentEvent $event, ?int $viewerId): array
    {
        $transaction = PaymentTransaction::query()->with('order')
            ->where('transaction_id', $event->merchantReference)->first();
        $order = $transaction?->order;
        if (! $order || $transaction->gateway !== PaymentMethod::MOMO
            || $order->payment_method !== PaymentMethod::MOMO
            || ($transaction->payload['momo_request_id'] ?? null) !== ($event->metadata['request_id'] ?? null)
            || ! Money::equals((string) $transaction->amount, $event->amount)
            || ! Money::equals((string) $order->total_amount, $event->amount)) {
            return ['state' => 'invalid', 'order' => null];
        }
        $state = match (true) {
            in_array($order->status, ['cancelled', 'refunded'], true) => 'closed',
            $order->payment_status === PaymentService::PAYMENT_PAID => 'paid',
            $transaction->payment_status === PaymentService::PAYMENT_FAILED => 'failed',
            default => 'pending',
        };
        return ['state' => $state,
            'order' => $viewerId !== null && (int) $order->user_id === $viewerId ? $order : null];
    }
}
