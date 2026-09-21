<?php

namespace App\Services;

use App\Models\Refund;
use App\Payments\GatewayEventType;
use App\Payments\MoMoGateway;
use App\Support\PaymentAttemptReference;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use LogicException;
use RuntimeException;
use Throwable;

final class MoMoRefundService
{
    public function __construct(private readonly MoMoGateway $gateway,
        private readonly PaymentService $payments, private readonly PaymentGatewayJournal $journal) {}

    public function execute(Refund $refund, int $actorId, ?string $adminNote = null): Refund
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('MoMo refund HTTP must run outside database transactions.');
        }
        $this->gateway->assertConfigured();
        $refund = Refund::with('paymentTransaction')->findOrFail($refund->id);
        if ($refund->paymentTransaction?->gateway !== 'momo') {
            throw new RuntimeException('Refund is not a MoMo refund.');
        }
        if ($refund->status === Refund::STATUS_COMPLETED) {
            return $refund;
        }
        if ($refund->status === Refund::STATUS_PROCESSING) {
            return $this->query($refund, $actorId);
        }
        $this->gateway->amount((string) $refund->amount);
        $preparation = $this->payments->prepareMoMoRefund($refund, $actorId, $adminNote);
        $refund = $preparation['refund'];
        if (! $preparation['should_send']) {
            return $refund->status === Refund::STATUS_PROCESSING ? $this->query($refund, $actorId) : $refund;
        }
        $tx = $refund->paymentTransaction()->firstOrFail();
        $orderId = $this->refundOrderId($refund);
        $requestId = $this->refundRequestId($refund);
        $amount = $this->gateway->amount((string) $refund->amount);
        $this->journal->append(GatewayEventType::RefundRequested, $tx,
            metadata: ['request_id' => $requestId, 'refund_id' => $refund->id,
                'refund_order_id' => $orderId, 'amount' => (string) $refund->amount]);
        try {
            $body = $this->gateway->refundPayload($orderId, $requestId, (string) $refund->amount,
                (string) $tx->gateway_transaction_id);
            $response = $this->post('refund_url', $body);
            if ($this->gateway->refundResponseSucceeded($response, $orderId, $requestId, $amount)) {
                return $this->complete($refund, $actorId, $tx, (string) $response['transId']);
            }
        } catch (Throwable) {
            // Unknown transport/result: never mark failed or replay a different refund request.
        }
        $this->journal->append(GatewayEventType::RefundInconclusive, $tx,
            metadata: ['request_id' => $requestId, 'refund_id' => $refund->id,
                'refund_order_id' => $orderId], conflict: true);
        return $refund->fresh();
    }

    public function query(Refund $target, int $actorId): Refund
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('MoMo refund query HTTP must run outside database transactions.');
        }
        $this->gateway->assertConfigured();
        $refund = Refund::with('paymentTransaction')->findOrFail($target->id);
        if ($refund->paymentTransaction?->gateway !== 'momo' || $refund->status !== Refund::STATUS_PROCESSING) {
            return $refund;
        }
        $tx = $refund->paymentTransaction;
        $requestId = PaymentAttemptReference::generate();
        try {
            $body = $this->gateway->queryPayload($this->refundOrderId($refund), $requestId);
            $response = $this->post('refund_query_url', $body);
            if ($this->gateway->refundQuerySucceeded($response, $this->refundOrderId($refund),
                $requestId, $this->gateway->amount((string) $refund->amount))) {
                $matching = collect($response['refundTrans'])->firstWhere('orderId', $this->refundOrderId($refund));
                return $this->complete($refund, $actorId, $tx, (string) $matching['transId']);
            }
        } catch (Throwable) {
            // Leave processing for another verified query/manual investigation.
        }
        $this->journal->append(GatewayEventType::RefundInconclusive, $tx,
            metadata: ['request_id' => $requestId, 'refund_id' => $refund->id,
                'refund_order_id' => $this->refundOrderId($refund)], conflict: true);
        return $refund->fresh();
    }

    private function complete(Refund $refund, int $actorId, \App\Models\PaymentTransaction $tx,
        string $refundTransId): Refund
    {
        return DB::transaction(function () use ($refund, $actorId, $tx, $refundTransId): Refund {
            $completed = $this->payments->completeMoMoRefund($refund, $actorId);
            $this->journal->append(GatewayEventType::RefundSettled, $tx,
                metadata: ['refund_id' => $refund->id, 'refund_order_id' => $this->refundOrderId($refund),
                    'refund_trans_id' => $refundTransId, 'amount' => (string) $refund->amount]);
            return $completed;
        }, 3);
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    private function post(string $configKey, array $body): array
    {
        $response = Http::acceptJson()->asJson()->connectTimeout(3)
            ->timeout((int) config('momo.timeout_seconds'))->withoutRedirecting()
            ->post((string) config('momo.'.$configKey), $body);
        if (! $response->successful() || ! is_array($response->json())) {
            throw new RuntimeException('MoMo refund status is uncertain.');
        }
        return $response->json();
    }

    private function refundOrderId(Refund $refund): string
    {
        return 'MMREF'.$refund->id;
    }

    private function refundRequestId(Refund $refund): string
    {
        return 'MMREQ'.$refund->id;
    }
}
