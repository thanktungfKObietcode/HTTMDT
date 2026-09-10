<?php

namespace App\Services;

use App\Contracts\PaymentGateway;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\Refund;
use App\Payments\PaymentEventOutcome;
use App\Payments\GatewayEventType;
use App\Payments\VerifiedPaymentEvent;
use App\Support\Money;
use App\Support\PaymentAttemptReference;
use App\Support\PaymentMethod;
use App\Support\VnPayAmount;
use Illuminate\Support\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class PaymentService
{
    public const PAYMENT_METHOD_COD = PaymentMethod::COD;
    public const PAYMENT_METHOD_SIMULATED_ONLINE = PaymentMethod::SIMULATED_ONLINE;

    public const PAYMENT_METHOD_VNPAY = PaymentMethod::VNPAY;

    public const PAYMENT_PENDING = 'pending';
    public const PAYMENT_PAID = 'paid';
    public const PAYMENT_FAILED = 'failed';
    public const PAYMENT_REFUNDED = 'refunded';

    private const REFUND_TRANSITIONS = [
        Refund::STATUS_REQUESTED => [Refund::STATUS_APPROVED, Refund::STATUS_REJECTED],
        Refund::STATUS_APPROVED => [Refund::STATUS_PROCESSING],
        Refund::STATUS_REJECTED => [],
        Refund::STATUS_PROCESSING => [Refund::STATUS_COMPLETED, Refund::STATUS_FAILED],
        Refund::STATUS_COMPLETED => [],
        Refund::STATUS_FAILED => [],
    ];

    public function __construct(private readonly OrderLifecycleService $lifecycleService)
    {
    }

    public function createOrGetPendingTransaction(Order $order, ?int $ownerId = null): PaymentTransaction
    {
        return DB::transaction(function () use ($order, $ownerId): PaymentTransaction {
            $lockedOrder = Order::query()
                ->whereKey($order->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $transactions = $this->lockPaymentTransactions($lockedOrder->id);
            $method = $this->paymentMethod($lockedOrder);

            if ($ownerId !== null && (int) $lockedOrder->user_id !== $ownerId) {
                throw new RuntimeException('Không có quyền thanh toán đơn hàng này.');
            }

            if (! in_array($method, $this->supportedPaymentMethods(), true)) {
                throw new RuntimeException('Phương thức thanh toán không được hỗ trợ.');
            }

            if (in_array((string) $lockedOrder->status, [
                OrderLifecycleService::STATUS_CANCELLED,
                OrderLifecycleService::STATUS_REFUNDED,
            ], true) || (string) $lockedOrder->payment_status === self::PAYMENT_REFUNDED) {
                throw new RuntimeException('Không thể tạo hoặc mở giao dịch thanh toán cho đơn hàng đã kết thúc.');
            }

            if ($method === PaymentMethod::VNPAY) {
                return $this->prepareVnPayAttempt($lockedOrder, $transactions);
            }

            if ((string) $lockedOrder->payment_status === self::PAYMENT_PAID) {
                $settled = $transactions
                    ->filter(fn (PaymentTransaction $candidate): bool =>
                        strtolower((string) $candidate->gateway) === $method
                        && (string) $candidate->payment_status === self::PAYMENT_PAID)
                    ->values();

                if ($settled->count() !== 1) {
                    throw new RuntimeException('Không tìm thấy giao dịch thanh toán duy nhất đã được ghi nhận.');
                }

                /** @var PaymentTransaction $transaction */
                $transaction = $settled->first();
                $this->assertTransactionAmountMatchesOrder($transaction, $lockedOrder);
                $this->closePendingAttemptsExcept(
                    $transactions,
                    (int) $transaction->id,
                    'order_already_settled'
                );

                return $transaction;
            }

            if ((string) $lockedOrder->status === OrderLifecycleService::STATUS_DELIVERED) {
                throw new RuntimeException('Không thể tạo giao dịch thanh toán cho đơn hàng ở trạng thái kết thúc.');
            }

            $pending = $transactions
                ->filter(fn (PaymentTransaction $candidate): bool =>
                    strtolower((string) $candidate->gateway) === $method
                    && (string) $candidate->payment_status === self::PAYMENT_PENDING)
                ->values();

            foreach ($pending as $candidate) {
                $this->assertTransactionAmountMatchesOrder($candidate, $lockedOrder);
            }

            if ($pending->isNotEmpty()) {
                /** @var PaymentTransaction $transaction */
                $transaction = $pending->first();
                $this->closePendingAttemptsExcept(
                    $transactions,
                    (int) $transaction->id,
                    'duplicate_pending_attempt'
                );

                return $transaction;
            }

            $transaction = $lockedOrder->paymentTransactions()->create([
                'gateway' => $method,
                'transaction_id' => $this->generateTransactionId(),
                'payment_status' => self::PAYMENT_PENDING,
                'amount' => Money::fromMinorUnits(Money::toMinorUnits((string) $lockedOrder->total_amount)),
                'currency' => VnPayAmount::CURRENCY_VND,
                'payload' => [
                    'source' => 'order_checkout',
                ],
            ]);
            $this->closePendingAttemptsExcept(
                $transactions,
                (int) $transaction->id,
                'superseded_payment_attempt'
            );

            return $transaction;
        }, 3);
    }

    /** @return array<int, string> */
    public function checkoutPaymentMethods(): array
    {
        $ready = false;
        if (config('vnpay.enabled') === true) {
            try {
                app(PaymentGateway::class)->assertConfigured();
                $ready = true;
            } catch (RuntimeException) {
                // Configuration errors must not expose credentials or break COD.
            }
        }

        return PaymentMethod::checkoutEnabled($ready);
    }

    public function canInitiateVnPay(Order $order): bool
    {
        return $this->paymentMethod($order) === PaymentMethod::VNPAY
            && in_array($order->status, ['pending', 'confirmed'], true)
            && in_array($order->payment_status, [self::PAYMENT_PENDING, self::PAYMENT_FAILED], true)
            && in_array(PaymentMethod::VNPAY, $this->checkoutPaymentMethods(), true);
    }

    /** Caller holds Order, then all payment rows in ascending ID order. */
    private function prepareVnPayAttempt(Order $order, Collection $transactions): PaymentTransaction
    {
        if (config('vnpay.enabled') !== true
            || ! in_array($order->status, ['pending', 'confirmed'], true)
            || ! in_array($order->payment_status, [self::PAYMENT_PENDING, self::PAYMENT_FAILED], true)
            || $transactions->contains(fn ($tx) => in_array($tx->payment_status, [self::PAYMENT_PAID, self::PAYMENT_REFUNDED], true))) {
            throw new RuntimeException('Đơn hàng không còn đủ điều kiện thanh toán VNPay.');
        }

        VnPayAmount::toGatewayAmount((string) $order->total_amount);
        $clock = now('Asia/Ho_Chi_Minh')->startOfSecond();
        foreach ($transactions as $transaction) {
            if ($transaction->payment_status !== self::PAYMENT_PENDING || $transaction->gateway !== PaymentMethod::VNPAY) {
                continue;
            }
            $this->assertTransactionAmountMatchesOrder($transaction, $order);
            if ($transaction->currency !== 'VND') {
                throw new RuntimeException('Loại tiền giao dịch không hợp lệ.');
            }
            if ($transaction->expires_at && $transaction->expires_at->gt($clock)
                && PaymentAttemptReference::isValid((string) $transaction->transaction_id)) {
                $this->closePendingAttemptsExcept($transactions, (int) $transaction->id, 'duplicate_pending_attempt');
                $order->payment_expires_at = $transaction->expires_at;
                $order->payment_status = self::PAYMENT_PENDING;
                $order->save();

                return $transaction;
            }
        }

        $minutes = (int) config('vnpay.expire_minutes', 15);
        if ($minutes < 1 || $minutes > 60) {
            throw new RuntimeException('Thời hạn thanh toán VNPay không hợp lệ.');
        }
        // Timestamp columns have no zone: store in the app's DB timezone, sign in GMT+7.
        $createdAt = $clock->copy()->setTimezone(config('app.timezone'));
        $expiresAt = $clock->copy()->addMinutes($minutes)->setTimezone(config('app.timezone'));
        $transaction = $order->paymentTransactions()->make([
            'gateway' => PaymentMethod::VNPAY,
            'transaction_id' => $this->generateTransactionId(),
            'payment_status' => self::PAYMENT_PENDING,
            'amount' => (string) $order->total_amount,
            'currency' => 'VND',
            'expires_at' => $expiresAt,
            'payload' => ['source' => 'vnpay_initiation'],
        ]);
        $transaction->created_at = $createdAt;
        $transaction->save();
        $this->closePendingAttemptsExcept($transactions, (int) $transaction->id, 'superseded_payment_attempt');
        $order->payment_expires_at = $expiresAt;
        $order->payment_status = self::PAYMENT_PENDING;
        $order->save();

        return $transaction;
    }

    public function settleVerifiedEvent(VerifiedPaymentEvent $event): PaymentEventOutcome
    {
        if (! in_array($event->eventType, [VerifiedPaymentEvent::TYPE_IPN, VerifiedPaymentEvent::TYPE_QUERY], true)
            || $event->gateway !== PaymentMethod::VNPAY
            || $event->paid !== ($event->responseCode === '00' && $event->transactionStatus === '00')
            || ($event->paid && (ltrim($event->gatewayTransactionId, '0') === '' || $event->occurredAt === null))) {
            return PaymentEventOutcome::InvalidEvent;
        }

        try {
            $outcome = $this->settleVnPayEvent($event);
            if ($outcome === PaymentEventOutcome::UnknownReference) {
                app(PaymentGatewayJournal::class)->append(
                    $event->eventType === VerifiedPaymentEvent::TYPE_QUERY ? GatewayEventType::QueryConflict : GatewayEventType::IpnConflict,
                    event: $event, metadata: ['outcome' => $outcome->value], conflict: true);
            }

            return $outcome;
        } catch (QueryException $exception) {
            // The DB unique constraint is the final guard across different orders.
            if (! str_contains($exception->getMessage(), 'payment_transactions_gateway_transaction_unique')
                && ! str_contains($exception->getMessage(), 'payment_transactions.gateway, payment_transactions.gateway_transaction_id')) {
                throw $exception;
            }

            return $this->settleVnPayEvent($event, true);
        }
    }

    private function settleVnPayEvent(VerifiedPaymentEvent $event, bool $identityCollision = false): PaymentEventOutcome
    {
        return DB::transaction(function () use ($event, $identityCollision): PaymentEventOutcome {
            // Resolve identity only; all state decisions below use freshly locked rows.
            $attempt = PaymentTransaction::query()->where('transaction_id', $event->merchantReference)->first();
            if (! $attempt) {
                return PaymentEventOutcome::UnknownReference;
            }
            $order = Order::query()->whereKey($attempt->order_id)->lockForUpdate()->first();
            if (! $order) {
                return PaymentEventOutcome::UnknownReference;
            }
            $transactions = $this->lockPaymentTransactions($order->id);
            $transaction = $transactions->first(fn ($tx) => $tx->transaction_id === $event->merchantReference);
            if (! $transaction) {
                return PaymentEventOutcome::UnknownReference;
            }
            if ($transaction->gateway !== PaymentMethod::VNPAY || $this->paymentMethod($order) !== PaymentMethod::VNPAY
                || $transaction->currency !== 'VND' || $event->currency !== 'VND') {
                return $this->recordVnPayEvent($transaction, $event, PaymentEventOutcome::InvalidEvent);
            }
            if (! Money::equals((string) $order->total_amount, (string) $transaction->amount)
                || ! Money::equals((string) $transaction->amount, $event->amount)
                || Money::toMinorUnits($event->amount) <= 0) {
                return $this->recordVnPayEvent($transaction, $event, PaymentEventOutcome::AmountMismatch);
            }

            // Query API success does not mean payment success. Unfinished, reversal,
            // fraud/refund states must never be interpreted as safe-to-cancel failure.
            if ($event->eventType === VerifiedPaymentEvent::TYPE_QUERY
                && ! $event->paid && ! $event->provesUnpaid()) {
                return $this->recordVnPayEvent($transaction, $event, PaymentEventOutcome::ReconciliationRequired);
            }

            $externalId = ltrim($event->gatewayTransactionId, '0') === '' ? null : $event->gatewayTransactionId;
            $externalIdUsed = $externalId !== null && PaymentTransaction::query()
                ->where('gateway', PaymentMethod::VNPAY)->where('gateway_transaction_id', $externalId)
                ->where('id', '<>', $transaction->id)->exists();
            if ($identityCollision || $externalIdUsed
                || ($transaction->gateway_transaction_id !== null && $externalId !== null
                    && $transaction->gateway_transaction_id !== $externalId)) {
                return $this->recordVnPayEvent($transaction, $event, PaymentEventOutcome::ReconciliationRequired);
            }
            if (in_array($order->status, ['cancelled', 'refunded'], true)
                || $order->payment_status === self::PAYMENT_REFUNDED
                || $transactions->contains(fn ($tx) => $tx->payment_status === self::PAYMENT_REFUNDED)) {
                return $this->recordVnPayEvent($transaction, $event, PaymentEventOutcome::ReconciliationRequired);
            }
            if (! in_array($order->status, ['pending', 'confirmed', 'processing', 'shipped', 'delivered'], true)
                || ! in_array($order->payment_status, [self::PAYMENT_PENDING, self::PAYMENT_FAILED, self::PAYMENT_PAID], true)) {
                return $this->recordVnPayEvent($transaction, $event, PaymentEventOutcome::ReconciliationRequired);
            }
            if ($transaction->payment_status === self::PAYMENT_PAID) {
                if ($event->eventType === VerifiedPaymentEvent::TYPE_QUERY && ! $event->paid) {
                    return $this->recordVnPayEvent($transaction, $event, PaymentEventOutcome::ReconciliationRequired);
                }
                return $this->recordVnPayEvent($transaction, $event, PaymentEventOutcome::AlreadyProcessed);
            }
            $anotherPaid = $transactions->contains(fn ($tx) => $tx->id !== $transaction->id && $tx->payment_status === self::PAYMENT_PAID);
            if ($event->paid && ($anotherPaid || $order->payment_status === self::PAYMENT_PAID)) {
                return $this->recordVnPayEvent($transaction, $event, PaymentEventOutcome::ReconciliationRequired);
            }
            if (! in_array($transaction->payment_status, [self::PAYMENT_PENDING, self::PAYMENT_FAILED], true)) {
                return $this->recordVnPayEvent($transaction, $event, PaymentEventOutcome::ReconciliationRequired);
            }
            if (! $event->paid && $transaction->payment_status === self::PAYMENT_FAILED) {
                return $this->recordVnPayEvent($transaction, $event, PaymentEventOutcome::AlreadyProcessed);
            }

            $transaction->gateway_transaction_id ??= $externalId;
            $transaction->gateway_response_code = $event->responseCode;
            $transaction->gateway_transaction_status = $event->transactionStatus;
            $transaction->payment_status = $event->paid ? self::PAYMENT_PAID : self::PAYMENT_FAILED;
            if ($event->paid) {
                $paidAt = $event->occurredAt?->setTimezone(new \DateTimeZone(config('app.timezone'))) ?? now();
                $transaction->paid_at = $paidAt;
                $order->payment_status = self::PAYMENT_PAID;
                $order->paid_at ??= $paidAt;
                $this->closePendingAttemptsExcept($transactions, (int) $transaction->id, 'another_attempt_settled');
            } elseif (! $anotherPaid && $order->payment_status !== self::PAYMENT_PAID) {
                $otherPending = $transactions->contains(fn ($tx) => $tx->id !== $transaction->id && $tx->payment_status === self::PAYMENT_PENDING);
                $order->payment_status = $otherPending ? self::PAYMENT_PENDING : self::PAYMENT_FAILED;
            }
            // VNPay settles payment only; fulfillment remains under OrderLifecycleService.
            $order->save();

            return $this->recordVnPayEvent($transaction, $event, PaymentEventOutcome::Processed);
        }, 3);
    }

    private function recordVnPayEvent(PaymentTransaction $transaction, VerifiedPaymentEvent $event, PaymentEventOutcome $outcome): PaymentEventOutcome
    {
        $receipt = [
            'source' => $event->eventType,
            'merchant_reference' => $event->merchantReference,
            'gateway_transaction_id' => $event->gatewayTransactionId,
            'amount' => $event->amount,
            'currency' => $event->currency,
            'response_code' => $event->responseCode,
            'transaction_status' => $event->transactionStatus,
            'occurred_at' => $event->occurredAt?->format(DATE_ATOM),
            'signature_verified' => true,
        ];
        $fingerprint = hash('sha256', json_encode($receipt, JSON_THROW_ON_ERROR));
        $payload = $transaction->payload ?? [];
        if (! isset($payload['vnpay_events'][$fingerprint])) {
            $payload['vnpay_events'][$fingerprint] = $receipt + [
                'outcome' => $outcome->value,
                'received_at' => now()->toIso8601String(),
            ];
            $transaction->payload = $payload;
        }
        $conflict = in_array($outcome, [PaymentEventOutcome::ReconciliationRequired,
            PaymentEventOutcome::AmountMismatch, PaymentEventOutcome::InvalidEvent], true);
        if ($conflict) {
            $payload = $transaction->payload ?? [];
            $uncertaintyOnly = $event->eventType === VerifiedPaymentEvent::TYPE_QUERY
                && ! $event->paid && ! $event->provesUnpaid()
                && $outcome === PaymentEventOutcome::ReconciliationRequired
                && (! ($payload['vnpay_reconciliation_required'] ?? false)
                    || ($payload['vnpay_reconciliation_reason'] ?? null) === 'query_uncertain');
            $payload['vnpay_reconciliation_required'] = true;
            $payload['vnpay_reconciliation_reason'] = $uncertaintyOnly ? 'query_uncertain' : 'financial_conflict';
            $transaction->payload = $payload;
        } elseif ($event->eventType === VerifiedPaymentEvent::TYPE_QUERY) {
            $payload = $transaction->payload ?? [];
            // A verified, consistent query may resolve transport/incomplete-state
            // uncertainty, but never silently clear historical money conflicts.
            if (($payload['vnpay_reconciliation_reason'] ?? null) === 'query_uncertain') {
                $payload['vnpay_reconciliation_required'] = false;
                unset($payload['vnpay_reconciliation_reason']);
            }
            $payload['vnpay_last_query_outcome'] = $outcome->value;
            $transaction->payload = $payload;
        }
        if ($transaction->isDirty()) {
            $transaction->save();
        }

        $query = $event->eventType === VerifiedPaymentEvent::TYPE_QUERY;
        $type = $conflict
            ? ($query ? GatewayEventType::QueryConflict : GatewayEventType::IpnConflict)
            : ($query ? GatewayEventType::QuerySettled
                : ($outcome === PaymentEventOutcome::AlreadyProcessed ? GatewayEventType::IpnDuplicate : GatewayEventType::IpnSettled));
        // Required audit outcome: failure here rolls the ENTIRE settlement back.
        app(PaymentGatewayJournal::class)->append($type, $transaction, $event,
            ['outcome' => $outcome->value, 'request_id' => $event->metadata['request_id'] ?? null], $conflict);

        return $outcome;
    }

    /** @return array<int, string> */
    public function callbackPaymentMethods(): array
    {
        return PaymentMethod::callbackEnabled();
    }

    /** @return array<int, string> */
    public function supportedPaymentMethods(): array
    {
        return PaymentMethod::known();
    }

    /** @param array<string, mixed> $payload */
    public function callbackSignature(array $payload): string
    {
        $plain = implode('|', [
            (string) $payload['order_number'],
            (string) $payload['transaction_id'],
            strtolower((string) $payload['gateway']),
            strtolower((string) $payload['payment_status']),
            Money::fromMinorUnits(Money::toMinorUnits((string) $payload['amount'])),
        ]);

        return hash_hmac('sha256', $plain, (string) config('app.key'));
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function handleCallback(array $payload): PaymentTransaction
    {
        $status = strtolower((string) $payload['payment_status']);
        if (! in_array($status, [self::PAYMENT_PAID, self::PAYMENT_FAILED], true)) {
            throw new RuntimeException('Trang thai thanh toan khong hop le.');
        }

        $gateway = strtolower((string) $payload['gateway']);
        if (! in_array($gateway, $this->callbackPaymentMethods(), true)) {
            throw new RuntimeException('Cong thanh toan callback khong hop le.');
        }

        return DB::transaction(function () use ($payload, $status, $gateway): PaymentTransaction {
            $order = Order::query()
                ->where('order_number', $payload['order_number'])
                ->lockForUpdate()
                ->first();

            if (! $order) {
                throw new RuntimeException('Don hang khong ton tai.');
            }

            $transactions = $this->lockPaymentTransactions($order->id);
            $matchingTransactions = $transactions
                ->filter(fn (PaymentTransaction $candidate) =>
                    (string) $candidate->transaction_id === (string) $payload['transaction_id'])
                ->values();

            if ($matchingTransactions->count() !== 1) {
                throw new RuntimeException('Khong tim thay giao dich thanh toan duy nhat.');
            }

            /** @var PaymentTransaction $transaction */
            $transaction = $matchingTransactions->first();

            if ($gateway !== $this->paymentMethod($order)
                || $gateway !== strtolower((string) $transaction->gateway)) {
                throw new RuntimeException('Cong thanh toan khong khop voi don hang hoac giao dich.');
            }

            if ((string) $order->status === OrderLifecycleService::STATUS_CANCELLED) {
                throw new RuntimeException('Don hang da bi huy, callback khong the ghi nhan thanh toan.');
            }

            if ((string) $order->payment_status === self::PAYMENT_REFUNDED
                || (string) $order->status === OrderLifecycleService::STATUS_REFUNDED
                || (string) $transaction->payment_status === self::PAYMENT_REFUNDED) {
                throw new RuntimeException('Giao dịch đã hoàn tiền, callback không thể thay đổi trạng thái.');
            }

            $serverAmount = Money::toMinorUnits((string) $order->total_amount);
            $transactionAmount = Money::toMinorUnits((string) $transaction->amount);
            $callbackAmount = Money::toMinorUnits((string) $payload['amount']);
            if ($serverAmount <= 0
                || $serverAmount !== $transactionAmount
                || $serverAmount !== $callbackAmount) {
                throw new RuntimeException('So tien thanh toan khong hop le.');
            }

            $otherPaidTransactionExists = $transactions->contains(
                fn (PaymentTransaction $candidate) =>
                    (int) $candidate->id !== (int) $transaction->id
                    && (string) $candidate->payment_status === self::PAYMENT_PAID
            );

            if ($status === self::PAYMENT_PAID && $otherPaidTransactionExists) {
                throw new RuntimeException('Don hang da co mot giao dich thanh toan thanh cong khac.');
            }

            if ((string) $transaction->payment_status === $status) {
                return $transaction;
            }

            if ((string) $transaction->payment_status !== self::PAYMENT_PENDING) {
                throw new RuntimeException('Giao dich khong con o trang thai cho phep callback thay doi.');
            }

            $transaction->payment_status = $status;
            if ($status === self::PAYMENT_PAID && ! $transaction->paid_at) {
                $transaction->paid_at = now();
            }
            $transaction->payload = array_merge($transaction->payload ?? [], [
                'callback_payload' => array_intersect_key($payload, array_flip([
                    'order_number', 'transaction_id', 'payment_status', 'amount', 'gateway',
                ])),
                'callback_at' => now()->toDateTimeString(),
            ]);
            $transaction->save();

            if ($status === self::PAYMENT_PAID) {
                $this->closePendingAttemptsExcept(
                    $transactions,
                    (int) $transaction->id,
                    'another_attempt_settled'
                );
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
                if (! $otherPaidTransactionExists) {
                    $order->payment_status = self::PAYMENT_FAILED;
                    $order->save();
                }
            }

            return $transaction;
        }, 3);
    }

    /**
     * @return array{eligible: bool, refundable_amount: string, active_refund: ?Refund}
     */
    public function refundEligibility(Order $order): array
    {
        $paidTransactions = $order->paymentTransactions()
            ->where('payment_status', self::PAYMENT_PAID)
            ->orderBy('id')
            ->get();
        $transaction = $paidTransactions->count() === 1 ? $paidTransactions->first() : null;
        $refunds = $order->refunds()->orderBy('id')->get();
        $activeRefund = $refunds->first(
            fn (Refund $refund) => in_array((string) $refund->status, Refund::ACTIVE_STATUSES, true)
        );

        if ((string) $order->payment_status !== self::PAYMENT_PAID
            || (string) $order->status !== OrderLifecycleService::STATUS_DELIVERED
            || ! $transaction) {
            return [
                'eligible' => false,
                'refundable_amount' => '0.00',
                'active_refund' => $activeRefund,
            ];
        }

        $paidAmount = $this->toMinorUnits((string) $transaction->amount);
        $reservedAmount = $this->sumRefundAmounts($refunds, Refund::BALANCE_CONSUMING_STATUSES);
        $refundableAmount = max(0, $paidAmount - $reservedAmount);

        return [
            'eligible' => ! $activeRefund && $refundableAmount > 0,
            'refundable_amount' => $this->fromMinorUnits($refundableAmount),
            'active_refund' => $activeRefund,
        ];
    }

    public function requestRefund(Order $order, string $amount, ?string $reason, int $actorId): Refund
    {
        $requestedAmount = $this->toMinorUnits($amount);

        if ($requestedAmount <= 0) {
            throw new RuntimeException('Số tiền hoàn phải lớn hơn 0.');
        }

        return DB::transaction(function () use ($order, $requestedAmount, $reason, $actorId): Refund {
            $lockedOrder = Order::query()
                ->whereKey($order->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $lockedOrder->user_id !== $actorId) {
                throw new RuntimeException('Bạn không có quyền yêu cầu hoàn tiền cho đơn hàng này.');
            }

            $transactions = $this->lockPaymentTransactions($lockedOrder->id);
            $transaction = $this->singlePaidTransaction($transactions);
            $refunds = $this->lockRefunds($lockedOrder->id);

            $this->assertRefundableContext($lockedOrder, $transaction, $transactions);

            if ($refunds->contains(
                fn (Refund $refund) => in_array((string) $refund->status, Refund::ACTIVE_STATUSES, true)
            )) {
                throw new RuntimeException('Đơn hàng đang có một yêu cầu hoàn tiền chưa hoàn tất.');
            }

            $paidAmount = $this->assertRefundBalanceWithinPaidAmount($transaction, $refunds);
            $reservedAmount = $this->sumRefundAmounts($refunds, Refund::BALANCE_CONSUMING_STATUSES);

            if ($requestedAmount > $paidAmount - $reservedAmount) {
                throw new RuntimeException('Số tiền hoàn vượt quá số dư có thể hoàn.');
            }

            return Refund::query()->create([
                'order_id' => $lockedOrder->id,
                'payment_transaction_id' => $transaction->id,
                'requested_by' => $actorId,
                'amount' => $this->fromMinorUnits($requestedAmount),
                'reason' => $reason,
                'status' => Refund::STATUS_REQUESTED,
            ]);
        }, 3);
    }

    public function approveRefund(Refund $refund, int $actorId, ?string $adminNote = null): Refund
    {
        return DB::transaction(function () use ($refund, $actorId, $adminNote): Refund {
            [$order, $transaction, $refunds, $lockedRefund, $transactions] = $this->lockRefundContext($refund);

            if ($lockedRefund->status === Refund::STATUS_APPROVED) {
                return $lockedRefund;
            }

            $this->assertRefundableContext($order, $transaction, $transactions);
            $this->assertRefundBalanceWithinPaidAmount($transaction, $refunds);
            $this->transitionRefund($lockedRefund, Refund::STATUS_APPROVED);

            $lockedRefund->reviewed_by = $actorId;
            $lockedRefund->reviewed_at = now();
            $lockedRefund->admin_note = $this->appendAdminNote($lockedRefund->admin_note, $adminNote);
            $lockedRefund->save();

            return $lockedRefund;
        }, 3);
    }

    public function rejectRefund(Refund $refund, int $actorId, ?string $adminNote = null): Refund
    {
        return DB::transaction(function () use ($refund, $actorId, $adminNote): Refund {
            [, , , $lockedRefund] = $this->lockRefundContext($refund);

            if ($lockedRefund->status === Refund::STATUS_REJECTED) {
                return $lockedRefund;
            }

            $this->transitionRefund($lockedRefund, Refund::STATUS_REJECTED);
            $lockedRefund->reviewed_by = $actorId;
            $lockedRefund->reviewed_at = now();
            $lockedRefund->admin_note = $this->appendAdminNote($lockedRefund->admin_note, $adminNote);
            $lockedRefund->save();

            return $lockedRefund;
        }, 3);
    }

    public function executeRefund(
        Refund $refund,
        int $actorId,
        ?string $adminNote = null,
        ?callable $executor = null
    ): Refund {
        $preparation = DB::transaction(function () use ($refund, $actorId, $adminNote): array {
            [$order, $transaction, $refunds, $lockedRefund, $transactions] = $this->lockRefundContext($refund);

            if ($transaction?->gateway === PaymentMethod::VNPAY) {
                throw new RuntimeException('Hoàn tiền VNPay chưa hỗ trợ thực thi. Cần đối soát và hoàn tiền qua cổng; không thể dùng mô phỏng nội bộ.');
            }

            if (in_array($lockedRefund->status, [Refund::STATUS_PROCESSING, Refund::STATUS_COMPLETED], true)) {
                return ['should_execute' => false, 'refund' => $lockedRefund];
            }

            $this->assertRefundableContext($order, $transaction, $transactions);
            $this->assertRefundBalanceWithinPaidAmount($transaction, $refunds);
            $this->transitionRefund($lockedRefund, Refund::STATUS_PROCESSING);

            $lockedRefund->processed_by = $actorId;
            $lockedRefund->processing_at = now();
            $lockedRefund->admin_note = $this->appendAdminNote($lockedRefund->admin_note, $adminNote);
            $lockedRefund->save();

            return ['should_execute' => true, 'refund' => $lockedRefund];
        }, 3);

        if (! $preparation['should_execute']) {
            return $preparation['refund'];
        }

        $executor ??= static function (Refund $refund): void {
            // Internal simulation only. A real gateway adapter is intentionally out of scope.
        };

        try {
            $executor($preparation['refund']);

            return $this->completeProcessingRefund($preparation['refund'], $actorId);
        } catch (Throwable $exception) {
            $this->markProcessingRefundFailed($preparation['refund'], $actorId);

            throw new RuntimeException('Thực thi hoàn tiền thất bại.', 0, $exception);
        }
    }

    private function completeProcessingRefund(Refund $refund, int $actorId): Refund
    {
        return DB::transaction(function () use ($refund, $actorId): Refund {
            [$order, $transaction, $refunds, $lockedRefund, $transactions] = $this->lockRefundContext($refund);

            if ($lockedRefund->status === Refund::STATUS_COMPLETED) {
                return $lockedRefund;
            }

            $this->assertRefundableContext($order, $transaction, $transactions);
            $paidAmount = $this->assertRefundBalanceWithinPaidAmount($transaction, $refunds);
            $completedAmount = $this->sumRefundAmounts($refunds, [Refund::STATUS_COMPLETED])
                + $this->toMinorUnits((string) $lockedRefund->amount);

            if ($completedAmount > $paidAmount) {
                throw new RuntimeException('Tổng tiền hoàn vượt quá số tiền đã thanh toán.');
            }

            $this->transitionRefund($lockedRefund, Refund::STATUS_COMPLETED);
            $lockedRefund->completed_at = now();
            $lockedRefund->processed_by = $actorId;
            $lockedRefund->save();

            if ($completedAmount === $paidAmount) {
                if (! $this->lifecycleService->canTransition(
                    (string) $order->status,
                    OrderLifecycleService::STATUS_REFUNDED
                )) {
                    throw new RuntimeException('Trạng thái đơn hàng không cho phép hoàn tiền toàn bộ.');
                }

                $transaction->payment_status = self::PAYMENT_REFUNDED;
                $transaction->save();

                $order->payment_status = self::PAYMENT_REFUNDED;
                $order->save();

                $this->lifecycleService->transitionAfterCompletedRefund(
                    $order,
                    'Đơn hàng đã được hoàn tiền toàn bộ',
                    $actorId
                );
            }

            return $lockedRefund;
        }, 3);
    }

    private function markProcessingRefundFailed(Refund $refund, int $actorId): void
    {
        DB::transaction(function () use ($refund, $actorId): void {
            [, , , $lockedRefund] = $this->lockRefundContext($refund);

            if (in_array($lockedRefund->status, [Refund::STATUS_FAILED, Refund::STATUS_COMPLETED], true)) {
                return;
            }

            $this->transitionRefund($lockedRefund, Refund::STATUS_FAILED);
            $lockedRefund->processed_by = $actorId;
            $lockedRefund->failed_at = now();
            $lockedRefund->admin_note = $this->appendAdminNote(
                $lockedRefund->admin_note,
                'Thực thi hoàn tiền nội bộ thất bại.'
            );
            $lockedRefund->save();
        }, 3);
    }

    /**
     * @return array{0: Order, 1: ?PaymentTransaction, 2: Collection<int, Refund>, 3: Refund, 4: Collection<int, PaymentTransaction>}
     */
    private function lockRefundContext(Refund $refund): array
    {
        $orderId = Refund::query()->whereKey($refund->getKey())->value('order_id');

        if (! $orderId) {
            throw new RuntimeException('Không tìm thấy yêu cầu hoàn tiền.');
        }

        $order = Order::query()
            ->whereKey($orderId)
            ->lockForUpdate()
            ->firstOrFail();

        $transactions = $this->lockPaymentTransactions($order->id);
        $refunds = $this->lockRefunds($order->id);
        $lockedRefund = $refunds->first(
            fn (Refund $candidate) => (int) $candidate->id === (int) $refund->id
        );

        if (! $lockedRefund) {
            throw new RuntimeException('Không tìm thấy yêu cầu hoàn tiền.');
        }

        $transaction = $transactions->first(
            fn (PaymentTransaction $candidate) =>
                (int) $candidate->id === (int) $lockedRefund->payment_transaction_id
        );

        return [$order, $transaction, $refunds, $lockedRefund, $transactions];
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

    /** @param Collection<int, PaymentTransaction> $transactions */
    private function singlePaidTransaction(Collection $transactions): ?PaymentTransaction
    {
        $paidTransactions = $transactions
            ->filter(fn (PaymentTransaction $transaction) =>
                (string) $transaction->payment_status === self::PAYMENT_PAID)
            ->values();

        if ($paidTransactions->count() > 1) {
            throw new RuntimeException('Đơn hàng có nhiều giao dịch đã thanh toán; không thể xác định giao dịch hoàn tiền an toàn.');
        }

        return $paidTransactions->first();
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
    private function assertRefundableContext(
        Order $order,
        ?PaymentTransaction $transaction,
        Collection $transactions
    ): void
    {
        if ((string) $order->payment_status !== self::PAYMENT_PAID) {
            throw new RuntimeException('Đơn hàng chưa được thanh toán hoặc đã hoàn tiền toàn bộ.');
        }

        if ((string) $order->status !== OrderLifecycleService::STATUS_DELIVERED) {
            throw new RuntimeException('Chỉ đơn hàng đã giao mới có thể yêu cầu hoàn tiền.');
        }

        $paidTransaction = $this->singlePaidTransaction($transactions);

        if (! $transaction
            || ! $paidTransaction
            || (int) $transaction->id !== (int) $paidTransaction->id
            || (string) $transaction->payment_status !== self::PAYMENT_PAID) {
            throw new RuntimeException('Không tìm thấy giao dịch đã thanh toán hợp lệ.');
        }
    }

    /** @param Collection<int, Refund> $refunds */
    private function assertRefundBalanceWithinPaidAmount(
        PaymentTransaction $transaction,
        Collection $refunds
    ): int {
        $paidAmount = $this->toMinorUnits((string) $transaction->amount);

        if ($paidAmount <= 0) {
            throw new RuntimeException('Số tiền giao dịch đã thanh toán không hợp lệ.');
        }

        $reservedAmount = $this->sumRefundAmounts($refunds, Refund::BALANCE_CONSUMING_STATUSES);

        if ($reservedAmount > $paidAmount) {
            throw new RuntimeException('Tổng tiền hoàn đã vượt quá số tiền giao dịch.');
        }

        return $paidAmount;
    }

    /**
     * @param Collection<int, Refund> $refunds
     * @param array<int, string> $statuses
     */
    private function sumRefundAmounts(Collection $refunds, array $statuses): int
    {
        return $refunds
            ->filter(fn (Refund $refund) => in_array((string) $refund->status, $statuses, true))
            ->reduce(
                fn (int $total, Refund $refund): int => $total + $this->toMinorUnits((string) $refund->amount),
                0
            );
    }

    private function transitionRefund(Refund $refund, string $toStatus): void
    {
        $allowed = self::REFUND_TRANSITIONS[(string) $refund->status] ?? [];

        if (! in_array($toStatus, $allowed, true)) {
            throw new RuntimeException('Chuyển trạng thái hoàn tiền không hợp lệ.');
        }

        $refund->status = $toStatus;
    }

    private function toMinorUnits(string $amount): int
    {
        return Money::toMinorUnits($amount);
    }

    private function fromMinorUnits(int $amount): string
    {
        return Money::fromMinorUnits($amount);
    }

    private function appendAdminNote(?string $current, ?string $addition): ?string
    {
        $addition = trim((string) $addition);

        if ($addition === '') {
            return $current;
        }

        $current = trim((string) $current);

        return $current === '' ? $addition : $current . PHP_EOL . $addition;
    }

    /** @param Collection<int, PaymentTransaction> $transactions */
    private function closePendingAttemptsExcept(Collection $transactions, int $keptTransactionId, string $reason): void
    {
        foreach ($transactions as $transaction) {
            if ((int) $transaction->id === $keptTransactionId
                || (string) $transaction->payment_status !== self::PAYMENT_PENDING) {
                continue;
            }

            $transaction->payment_status = self::PAYMENT_FAILED;
            $transaction->payload = array_merge($transaction->payload ?? [], [
                'closed_reason' => $reason,
                'closed_at' => now()->toDateTimeString(),
            ]);
            $transaction->save();
        }
    }

    private function generateTransactionId(): string
    {
        do {
            $transactionId = PaymentAttemptReference::generate();
        } while (PaymentTransaction::query()->where('transaction_id', $transactionId)->exists());

        return $transactionId;
    }

    private function paymentMethod(Order $order): string
    {
        return PaymentMethod::normalize((string) ($order->payment_method ?: self::PAYMENT_METHOD_COD));
    }

    private function assertTransactionAmountMatchesOrder(PaymentTransaction $transaction, Order $order): void
    {
        if (! Money::equals((string) $transaction->amount, (string) $order->total_amount)
            || Money::toMinorUnits((string) $order->total_amount) <= 0) {
            throw new RuntimeException('Số tiền giao dịch không khớp với đơn hàng.');
        }
    }
}
