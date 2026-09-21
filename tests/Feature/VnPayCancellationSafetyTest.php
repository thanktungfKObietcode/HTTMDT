<?php

namespace Tests\Feature;

use App\Models\PaymentGatewayEvent;
use App\Payments\VnPayCancellationPending;
use App\Payments\VnPayGateway;
use App\Services\OrderLifecycleService;
use App\Services\PaymentService;
use App\Services\VnPayReconciliationService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Concerns\VnPayQueryFixtures;
use Tests\TestCase;

class VnPayCancellationSafetyTest extends TestCase
{
    use DatabaseMigrations, VnPayQueryFixtures;

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:') {
            throw new RuntimeException('Cancellation tests require isolated SQLite memory; never use the development DB.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureVnPay();
        $this->travelTo(now()->setDate(2026, 9, 9)->setTime(2, 0, 0));
        Http::preventStrayRequests();
    }

    private function fakeUnpaidQuery(): void
    {
        Http::fake(function ($request) {
            $this->assertSame(0, DB::transactionLevel());

            return Http::response($this->queryResponse($request['vnp_TxnRef'], [
                'vnp_TransactionStatus' => '02',
            ]));
        });
    }

    public function test_verified_unpaid_query_allows_cancellation_and_restores_inventory_once(): void
    {
        [$user, $order, $transaction, $product] = $this->paymentContext();
        $this->fakeUnpaidQuery();

        $this->actingAs($user)->post(route('order.cancel', $order))->assertRedirect();

        $this->assertSame('cancelled', $order->refresh()->status);
        $this->assertSame('failed', $order->payment_status);
        $this->assertSame('failed', $transaction->refresh()->payment_status);
        $this->assertSame(5, $product->refresh()->stock);
        $this->assertSame(1, $order->statusHistory()->where('status', 'cancelled')->count());
        Http::assertSentCount(1);
    }

    public function test_paid_query_settles_but_never_cancels_or_restores_stock(): void
    {
        [$user, $order, $transaction, $product] = $this->paymentContext();
        Http::fake(fn ($request) => Http::response($this->queryResponse($request['vnp_TxnRef'])));

        $this->actingAs($user)->post(route('order.cancel', $order))->assertSessionHasErrors('status');

        $this->assertSame('pending', $order->refresh()->status);
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('paid', $transaction->refresh()->payment_status);
        $this->assertSame(3, $product->refresh()->stock);
    }

    public function test_query_timeout_fails_closed_without_fabricating_payment_status(): void
    {
        [$user, $order, $transaction, $product] = $this->paymentContext();
        Http::fake(fn () => throw new ConnectionException('Fixture timeout'));

        $this->actingAs($user)->post(route('order.cancel', $order))->assertSessionHasErrors('status');

        $this->assertSame('pending', $order->refresh()->status);
        $this->assertSame('pending', $order->payment_status);
        $this->assertSame('pending', $transaction->refresh()->payment_status);
        $this->assertTrue($transaction->requiresReconciliation());
        $this->assertSame(3, $product->refresh()->stock);
        $this->assertSame(VnPayCancellationPending::MESSAGE, session('errors')->first('status'));
    }

    public function test_invalid_query_signature_fails_closed(): void
    {
        [$user, $order, $transaction, $product] = $this->paymentContext();
        Http::fake(function ($request) {
            $response = $this->queryResponse($request['vnp_TxnRef'], ['vnp_TransactionStatus' => '02']);
            $response['vnp_SecureHash'] = str_repeat('0', 128);

            return Http::response($response);
        });

        $this->actingAs($user)->post(route('order.cancel', $order))->assertSessionHasErrors('status');

        $this->assertSame('pending', $order->refresh()->status);
        $this->assertSame('pending', $transaction->refresh()->payment_status);
        $this->assertSame(3, $product->refresh()->stock);
    }

    public function test_signed_inconclusive_query_cannot_authorize_cancellation(): void
    {
        [$user, $order, $transaction, $product] = $this->paymentContext();
        Http::fake(fn ($request) => Http::response($this->queryResponse($request['vnp_TxnRef'], [
            'vnp_TransactionStatus' => '01',
        ])));

        $this->actingAs($user)->post(route('order.cancel', $order))->assertSessionHasErrors('status');

        $this->assertSame('pending', $order->refresh()->status);
        $this->assertSame('pending', $transaction->refresh()->payment_status);
        $this->assertSame(3, $product->refresh()->stock);
    }

    public function test_paid_ipn_after_unpaid_proof_but_before_locked_recheck_blocks_cancellation(): void
    {
        [, $order, $transaction, $product] = $this->paymentContext();
        $this->fakeUnpaidQuery();
        $proof = app(VnPayReconciliationService::class)->reconcile($transaction);
        $this->assertTrue($proof->provesUnpaid());

        app(PaymentService::class)->settleVerifiedEvent(
            app(VnPayGateway::class)->verifyIpn($this->ipnPayload($transaction))
        );

        try {
            app(OrderLifecycleService::class)->transitionAfterVerifiedVnPayQuery($order, [$transaction->id => $proof]);
            $this->fail('A paid IPN must defeat earlier unpaid proof.');
        } catch (RuntimeException) {
            $this->assertSame('pending', $order->refresh()->status);
            $this->assertSame('paid', $order->payment_status);
            $this->assertSame(3, $product->refresh()->stock);
        }
    }

    public function test_all_attempts_must_have_distinct_verified_unpaid_proofs(): void
    {
        [$user, $order, $first, $product] = $this->paymentContext();
        $first->update(['payment_status' => 'failed']);
        $second = app(PaymentService::class)->createOrGetPendingTransaction($order);
        Http::fake(fn ($request) => Http::response($this->queryResponse($request['vnp_TxnRef'], [
            'vnp_TransactionStatus' => '02',
            'vnp_TransactionNo' => $request['vnp_TxnRef'] === $first->transaction_id ? '1111111' : '2222222',
        ])));

        $this->actingAs($user)->post(route('order.cancel', $order))->assertRedirect();

        $this->assertSame('cancelled', $order->refresh()->status);
        $this->assertSame('failed', $first->refresh()->payment_status);
        $this->assertSame('failed', $second->refresh()->payment_status);
        $this->assertSame(5, $product->refresh()->stock);
        Http::assertSentCount(2);
    }

    public function test_one_paid_attempt_blocks_multi_attempt_cancellation(): void
    {
        [$user, $order, $first, $product] = $this->paymentContext();
        $first->update(['payment_status' => 'failed']);
        $second = app(PaymentService::class)->createOrGetPendingTransaction($order);
        Http::fake(fn ($request) => Http::response($this->queryResponse($request['vnp_TxnRef'], [
            'vnp_TransactionStatus' => $request['vnp_TxnRef'] === $first->transaction_id ? '02' : '00',
            'vnp_TransactionNo' => $request['vnp_TxnRef'] === $first->transaction_id ? '1111111' : '2222222',
        ])));

        $this->actingAs($user)->post(route('order.cancel', $order))->assertSessionHasErrors('status');

        $this->assertSame('pending', $order->refresh()->status);
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('paid', $second->refresh()->payment_status);
        $this->assertSame(3, $product->refresh()->stock);
    }

    public function test_one_unresolved_attempt_blocks_multi_attempt_cancellation(): void
    {
        [$user, $order, $first, $product] = $this->paymentContext();
        $first->update(['payment_status' => 'failed']);
        $second = app(PaymentService::class)->createOrGetPendingTransaction($order);
        Http::fake(function ($request) use ($first) {
            if ($request['vnp_TxnRef'] !== $first->transaction_id) {
                throw new ConnectionException('Fixture timeout');
            }

            return Http::response($this->queryResponse($request['vnp_TxnRef'], [
                'vnp_TransactionStatus' => '02', 'vnp_TransactionNo' => '1111111',
            ]));
        });

        $this->actingAs($user)->post(route('order.cancel', $order))->assertSessionHasErrors('status');

        $this->assertSame('pending', $order->refresh()->status);
        $this->assertSame('pending', $second->refresh()->payment_status);
        $this->assertSame(3, $product->refresh()->stock);
        $this->assertSame(2, PaymentGatewayEvent::query()->where('event_type', 'query_requested')->count());
    }

    public function test_later_verified_query_can_clear_transient_uncertainty_and_cancel(): void
    {
        [$user, $order, $transaction, $product] = $this->paymentContext();
        $available = false;
        Http::fake(function ($request) use (&$available) {
            if (! $available) {
                throw new ConnectionException('Fixture timeout');
            }

            return Http::response($this->queryResponse($request['vnp_TxnRef'], [
                'vnp_TransactionStatus' => '02',
            ]));
        });
        $this->actingAs($user)->post(route('order.cancel', $order))->assertSessionHasErrors('status');
        $this->assertTrue($transaction->refresh()->requiresReconciliation());

        $available = true;
        $this->post(route('order.cancel', $order))->assertRedirect();

        $this->assertSame('cancelled', $order->refresh()->status);
        $this->assertFalse($transaction->refresh()->requiresReconciliation());
        $this->assertSame(5, $product->refresh()->stock);
    }

    public function test_new_attempt_after_proof_invalidates_cancellation_snapshot(): void
    {
        [, $order, $first, $product] = $this->paymentContext();
        $this->fakeUnpaidQuery();
        $proof = app(VnPayReconciliationService::class)->reconcile($first);
        app(PaymentService::class)->createOrGetPendingTransaction($order);

        try {
            app(OrderLifecycleService::class)->transitionAfterVerifiedVnPayQuery($order, [$first->id => $proof]);
            $this->fail('An unqueried attempt must block cancellation.');
        } catch (RuntimeException) {
            $this->assertSame('pending', $order->refresh()->status);
            $this->assertSame(3, $product->refresh()->stock);
        }
    }

    public function test_direct_lifecycle_cancellation_uses_the_same_query_guard(): void
    {
        [, $order, , $product] = $this->paymentContext();
        $this->fakeUnpaidQuery();

        app(OrderLifecycleService::class)->transition($order, 'cancelled');

        $this->assertSame('cancelled', $order->refresh()->status);
        $this->assertSame(5, $product->refresh()->stock);
        Http::assertSentCount(1);
    }

    public function test_cancellation_called_inside_a_transaction_never_starts_querydr(): void
    {
        [, $order, , $product] = $this->paymentContext();

        try {
            DB::transaction(fn () => app(OrderLifecycleService::class)->transition($order, 'cancelled'));
            $this->fail('VNPay QueryDR must not run inside a database transaction.');
        } catch (VnPayCancellationPending) {
            $this->assertSame('pending', $order->refresh()->status);
            $this->assertSame(3, $product->refresh()->stock);
            Http::assertNothingSent();
        }
    }

    public function test_existing_paid_gateway_evidence_blocks_cancellation_without_query(): void
    {
        [$user, $order, $transaction, $product] = $this->paymentContext();
        $transaction->update(['gateway_response_code' => '00', 'gateway_transaction_status' => '00']);

        $this->actingAs($user)->post(route('order.cancel', $order))->assertSessionHasErrors('status');

        $this->assertSame('pending', $order->refresh()->status);
        $this->assertSame(3, $product->refresh()->stock);
        Http::assertNothingSent();
    }

    public function test_missing_payment_attempt_cannot_be_treated_as_unpaid(): void
    {
        [$user, $order, $transaction, $product] = $this->paymentContext();
        $transaction->delete();

        $this->actingAs($user)->post(route('order.cancel', $order))->assertSessionHasErrors('status');

        $this->assertSame('pending', $order->refresh()->status);
        $this->assertSame(3, $product->refresh()->stock);
        Http::assertNothingSent();
    }

    public function test_repeated_cancellation_does_not_restore_stock_twice_or_requery(): void
    {
        [$user, $order, , $product] = $this->paymentContext();
        $this->fakeUnpaidQuery();

        $this->actingAs($user)->post(route('order.cancel', $order))->assertRedirect();
        $this->post(route('order.cancel', $order))->assertRedirect();

        $this->assertSame(5, $product->refresh()->stock);
        $this->assertSame(1, $order->statusHistory()->where('status', 'cancelled')->count());
        Http::assertSentCount(1);
    }

    public function test_cod_cancellation_needs_no_query_and_keeps_previous_behavior(): void
    {
        [$user, $order, $transaction, $product] = $this->paymentContext();
        $order->update(['payment_method' => 'cod']);
        $transaction->update(['gateway' => 'cod']);

        $this->actingAs($user)->post(route('order.cancel', $order))->assertRedirect();

        $this->assertSame('cancelled', $order->refresh()->status);
        $this->assertSame(5, $product->refresh()->stock);
        Http::assertNothingSent();
    }
}
