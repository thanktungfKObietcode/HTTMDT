<?php

namespace App\Services;

use App\Contracts\PaymentGateway;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Payments\PaymentUrlRequest;
use App\Payments\GatewayEventType;
use App\Payments\VerifiedPaymentEvent;
use App\Support\Money;
use App\Support\PaymentMethod;
use RuntimeException;

final class VnPayPaymentService
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly PaymentService $payments,
    ) {}

    public function paymentUrl(Order $order, int $ownerId, string $clientIp): string
    {
        $this->gateway->assertConfigured();
        $order = $order->fresh();
        if (! $order || (int) $order->user_id !== $ownerId || ! $this->payments->canInitiateVnPay($order)) {
            throw new RuntimeException('Đơn hàng không đủ điều kiện thanh toán VNPay.');
        }

        $transaction = $this->payments->createOrGetPendingTransaction($order, $ownerId);
        // The payment/checkout transaction has returned before the gateway is invoked.
        $order->refresh();
        if (! $this->payments->canInitiateVnPay($order)
            || $transaction->gateway !== PaymentMethod::VNPAY
            || $transaction->currency !== 'VND'
            || ! $transaction->expires_at || $transaction->expires_at->isPast()
            || ! Money::equals((string) $order->total_amount, (string) $transaction->amount)) {
            throw new RuntimeException('Giao dịch không còn đủ điều kiện thanh toán.');
        }

        $url = $this->gateway->buildPaymentUrl(new PaymentUrlRequest(
            merchantReference: (string) $transaction->transaction_id,
            amount: (string) $order->total_amount,
            currency: $transaction->currency,
            orderInfo: 'Thanh toan Silver Atelier '.$transaction->transaction_id,
            clientIp: $clientIp,
            createdAt: $transaction->created_at->toDateTimeImmutable(),
            expiresAt: $transaction->expires_at->toDateTimeImmutable(),
        ));
        // Commit initiation evidence before handing a signed URL to the browser.
        app(PaymentGatewayJournal::class)->append(GatewayEventType::PaymentInitiated, $transaction,
            metadata: ['amount' => (string) $transaction->amount, 'currency' => $transaction->currency,
                'expires_at' => $transaction->expires_at->toIso8601String()]);

        return $url;
    }

    /** Read-only: never resolve/create an attempt through createOrGetPendingTransaction. */
    public function returnResult(VerifiedPaymentEvent $event, ?int $viewerId): array
    {
        $transaction = PaymentTransaction::query()->with('order')
            ->where('transaction_id', $event->merchantReference)->first();
        $order = $transaction?->order;
        if (! $transaction || ! $order || $transaction->transaction_id !== $event->merchantReference) {
            return ['state' => 'unknown', 'order' => null];
        }
        if ($transaction->gateway !== PaymentMethod::VNPAY || $order->payment_method !== PaymentMethod::VNPAY
            || $transaction->currency !== 'VND' || $event->currency !== 'VND'
            || ! Money::equals((string) $transaction->amount, $event->amount)
            || ! Money::equals((string) $order->total_amount, $event->amount)
            || ($transaction->gateway_transaction_id !== null
                && ltrim($event->gatewayTransactionId, '0') !== ''
                && $transaction->gateway_transaction_id !== $event->gatewayTransactionId)) {
            return ['state' => 'invalid', 'order' => null];
        }

        $state = match (true) {
            in_array($order->status, ['cancelled', 'refunded'], true) || $order->payment_status === 'refunded' => 'closed',
            $order->payment_status === 'paid' => 'paid',
            ! $event->paid || $transaction->payment_status === 'failed' => 'failed',
            default => 'pending',
        };

        return [
            'state' => $state,
            'order' => $viewerId !== null && (int) $order->user_id === $viewerId ? $order : null,
        ];
    }
}
