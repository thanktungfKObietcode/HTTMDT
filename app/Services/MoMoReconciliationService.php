<?php

namespace App\Services;

use App\Models\PaymentTransaction;
use App\Payments\GatewayEventType;
use App\Payments\MoMoGateway;
use App\Payments\PaymentEventOutcome;
use App\Support\Money;
use App\Support\PaymentAttemptReference;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use LogicException;
use RuntimeException;
use Throwable;

final class MoMoReconciliationService
{
    public function __construct(private readonly MoMoGateway $gateway,
        private readonly PaymentService $payments, private readonly PaymentGatewayJournal $journal) {}

    public function reconcile(PaymentTransaction $target, ?int $actorId = null): PaymentEventOutcome
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('MoMo query must run outside database transactions.');
        }
        $this->gateway->assertConfigured();
        $tx = PaymentTransaction::with('order')->findOrFail($target->id);
        if ($tx->gateway !== 'momo' || $tx->order?->payment_method !== 'momo'
            || $tx->currency !== 'VND' || ! Money::equals((string) $tx->amount, (string) $tx->order->total_amount)) {
            return PaymentEventOutcome::InvalidEvent;
        }
        $requestId = PaymentAttemptReference::generate();
        $this->journal->append(GatewayEventType::QueryRequested, $tx,
            metadata: ['request_id' => $requestId, 'actor_id' => $actorId]);
        try {
            $payload = $this->gateway->queryPayload((string) $tx->transaction_id, $requestId);
            $response = Http::acceptJson()->asJson()->connectTimeout(3)
                ->timeout((int) config('momo.timeout_seconds'))->withoutRedirecting()
                ->post((string) config('momo.query_url'), $payload);
            if (! $response->successful() || ! is_array($response->json())) {
                throw new RuntimeException('MoMo query unavailable.');
            }
            $event = $this->gateway->queryEvent($response->json(), (string) $tx->transaction_id,
                $requestId, $this->gateway->amount((string) $tx->amount));
        } catch (Throwable) {
            $this->journal->append(GatewayEventType::QueryConflict, $tx,
                metadata: ['request_id' => $requestId, 'outcome' => PaymentEventOutcome::TemporaryFailure->value], conflict: true);
            $this->journal->flagUncertainty($tx, 'query_unavailable');
            return PaymentEventOutcome::TemporaryFailure;
        }
        if ($event === null) {
            $this->journal->append(GatewayEventType::QueryConflict, $tx,
                metadata: ['request_id' => $requestId, 'outcome' => PaymentEventOutcome::ReconciliationRequired->value], conflict: true);
            $this->journal->flagUncertainty($tx, 'query_inconclusive');
            return PaymentEventOutcome::ReconciliationRequired;
        }
        $this->journal->append(GatewayEventType::QueryResponse, $tx, $event,
            ['request_id' => $requestId, 'actor_id' => $actorId]);
        return $this->payments->settleVerifiedEvent($event);
    }
}
