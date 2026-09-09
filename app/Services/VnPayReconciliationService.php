<?php

namespace App\Services;

use App\Models\PaymentTransaction;
use App\Payments\GatewayEventType;
use App\Payments\GatewayVerificationException;
use App\Payments\PaymentEventOutcome;
use App\Payments\QueryTransactionRequest;
use App\Payments\ReconciliationResult;
use App\Support\PaymentAttemptReference;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

final class VnPayReconciliationService
{
    public function __construct(
        private readonly VnPayQueryClient $client,
        private readonly PaymentService $payments,
        private readonly PaymentGatewayJournal $journal,
    ) {}

    public function reconcile(PaymentTransaction $target, ?int $actorId = null): ReconciliationResult
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Reconciliation must start outside database transactions.');
        }
        $transaction = PaymentTransaction::with('order')->findOrFail($target->id);
        if ($transaction->gateway !== 'vnpay' || $transaction->currency !== 'VND'
            || $transaction->order?->payment_method !== 'vnpay') {
            if ($transaction->gateway === 'vnpay') {
                $this->journal->flagUncertainty($transaction, 'invalid_local_context');
            }
            return new ReconciliationResult(PaymentEventOutcome::InvalidEvent);
        }
        $request = new QueryTransactionRequest(
            PaymentAttemptReference::generate(), (string) $transaction->transaction_id,
            $transaction->created_at->toDateTimeImmutable(), now()->toDateTimeImmutable(),
            (string) config('vnpay.query_server_ip'),
        );
        $this->journal->append(GatewayEventType::QueryRequested, $transaction,
            metadata: ['request_id' => $request->requestId, 'actor_id' => $actorId]);
        try {
            $event = $this->client->query($request);
        } catch (Throwable $exception) {
            $outcome = $exception instanceof GatewayVerificationException
                ? $exception->outcome : PaymentEventOutcome::TemporaryFailure;
            // Never expose/log the HTTP exception or signed response.
            $this->journal->append(GatewayEventType::QueryConflict, $transaction,
                metadata: ['request_id' => $request->requestId, 'outcome' => $outcome->value], conflict: true);
            $this->journal->flagUncertainty($transaction, $outcome->value);

            return new ReconciliationResult($outcome);
        }
        $this->journal->append(GatewayEventType::QueryResponse, $transaction, $event,
            ['request_id' => $request->requestId, 'actor_id' => $actorId]);
        // The shared core locks/re-reads Order then ALL attempts, and writes the
        // financial outcome + journal atomically. No HTTP occurs in that closure.
        $outcome = $this->payments->settleVerifiedEvent($event);

        return new ReconciliationResult($outcome, $event);
    }
}
