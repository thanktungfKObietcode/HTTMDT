<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PaymentGatewayEvent;
use App\Models\PaymentTransaction;
use App\Models\Permission;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\User;
use App\Payments\GatewayEventType;
use App\Payments\PaymentEventOutcome;
use App\Payments\VnPayGateway;
use App\Services\OrderLifecycleService;
use App\Services\PaymentGatewayJournal;
use App\Services\PaymentService;
use App\Services\VnPayExpiryService;
use App\Services\VnPayPaymentService;
use App\Services\VnPayReconciliationService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\VnPayQueryFixtures;
use Tests\TestCase;

class PhaseSixCReconciliationExpiryTest extends TestCase
{
    // Unlike RefreshDatabase's wrapping transaction, this lets us test a HARD
    // zero-transaction HTTP boundary. Migrations are confined to SQLite memory.
    use DatabaseMigrations, VnPayQueryFixtures;

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:') {
            throw new RuntimeException('Phase 6C tests require isolated SQLite memory; never use the development DB.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureVnPay();
        $this->travelTo(now()->setDate(2026, 9, 9)->setTime(2, 0, 0));
        Http::preventStrayRequests();
    }

    private function fakeQuery(array $overrides = []): void
    {
        Http::fake(fn ($request) => Http::response($this->queryResponse($request['vnp_TxnRef'], $overrides)));
    }

    private function ipn(PaymentTransaction $transaction, array $overrides = []): PaymentEventOutcome
    {
        return app(PaymentService::class)->settleVerifiedEvent(
            app(VnPayGateway::class)->verifyIpn($this->ipnPayload($transaction, $overrides)));
    }

    private function reconcile(PaymentTransaction $tx): \App\Payments\ReconciliationResult
    {
        return app(VnPayReconciliationService::class)->reconcile($tx);
    }

    private function expire(Order $order): string
    {
        return app(VnPayExpiryService::class)->expire($order);
    }

    private function makeExpired(): void
    {
        $this->travel(30)->minutes();
    }

    public function test_query_http_uses_persisted_identity_and_runs_with_no_database_transaction(): void
    {
        [, $order, $tx, $product] = $this->paymentContext();
        Http::fake(function ($request) use ($tx) {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame('POST', $request->method());
            $this->assertSame(config('vnpay.query_url'), $request->url());
            $this->assertSame($tx->transaction_id, $request['vnp_TxnRef']);
            $this->assertSame('20260909090000', $request['vnp_TransactionDate']);
            $this->assertSame('127.0.0.1', $request['vnp_IpAddr']);
            $this->assertMatchesRegularExpression('/^[A-Z0-9]{26}$/', $request['vnp_RequestId']);

            return Http::response($this->queryResponse($tx->transaction_id));
        });
        $this->assertSame(PaymentEventOutcome::Processed, $this->reconcile($tx)->outcome);
        $this->assertSame('paid', $order->refresh()->payment_status);
        $this->assertSame('pending', $order->status);
        $this->assertSame('paid', $tx->refresh()->payment_status);
        $this->assertSame('1234567', $tx->gateway_transaction_id);
        $this->assertSame(3, $product->refresh()->stock);
        $this->assertDatabaseHas('payment_gateway_events', ['event_type' => 'query_requested', 'payment_transaction_id' => $tx->id]);
        $this->assertDatabaseHas('payment_gateway_events', ['event_type' => 'query_response', 'signature_valid' => true]);
        $this->assertDatabaseHas('payment_gateway_events', ['event_type' => 'query_settled', 'payment_transaction_id' => $tx->id]);
    }

    public function test_repeated_query_and_ipn_converge_on_one_settlement(): void
    {
        [, $order, $tx, $product] = $this->paymentContext();
        $this->fakeQuery();
        $this->assertSame(PaymentEventOutcome::Processed, $this->reconcile($tx)->outcome);
        $paidAt = $tx->refresh()->paid_at->toDateTimeString();
        $this->assertSame(PaymentEventOutcome::AlreadyProcessed, $this->reconcile($tx)->outcome);
        $this->assertSame(PaymentEventOutcome::AlreadyProcessed, $this->ipn($tx));
        $this->assertSame(1, $order->paymentTransactions()->where('payment_status', 'paid')->count());
        $this->assertSame($paidAt, $tx->refresh()->paid_at->toDateTimeString());
        $this->assertSame(3, $product->refresh()->stock);
        Http::assertSentCount(2);
    }

    public function test_reconciliation_cannot_be_called_inside_any_transaction(): void
    {
        [, , $tx] = $this->paymentContext();
        try {
            DB::transaction(fn () => $this->reconcile($tx));
            $this->fail('QueryDR must not run under DB locks.');
        } catch (\LogicException) {
            Http::assertNothingSent();
        }
    }

    #[DataProvider('transportFailures')]
    public function test_transport_or_signature_failure_keeps_finances_and_flags_uncertainty(string $failure): void
    {
        [, $order, $tx, $product] = $this->paymentContext();
        Http::fake(function () use ($failure, $tx) {
            if ($failure === 'timeout') {
                throw new ConnectionException('fixture timeout');
            }
            if ($failure === 'http') {
                return Http::response('untrusted body', 503);
            }
            $body = $this->queryResponse($tx->transaction_id);
            $body['vnp_SecureHash'] = str_repeat('f', 128);

            return Http::response($body);
        });
        $this->reconcile($tx);
        $this->assertSame('pending', $order->refresh()->payment_status);
        $this->assertSame('pending', $tx->refresh()->payment_status);
        $this->assertTrue($tx->requiresReconciliation());
        $this->assertSame(3, $product->refresh()->stock);
        $this->assertDatabaseHas('payment_gateway_events', ['event_type' => 'reconciliation_required']);
        Http::assertSentCount($failure === 'timeout' ? 0 : 1);
    }

    public static function transportFailures(): array
    {
        return [['timeout'], ['http'], ['signature']];
    }

    public function test_verified_query_can_clear_transient_uncertainty_only(): void
    {
        [, , $tx] = $this->paymentContext();
        app(PaymentGatewayJournal::class)->flagUncertainty($tx, 'timeout');
        $this->fakeQuery(['vnp_TransactionStatus' => '02']);
        $this->assertTrue($this->reconcile($tx)->provesUnpaid());
        $this->assertFalse($tx->refresh()->requiresReconciliation());
        $this->assertDatabaseHas('payment_gateway_events', ['event_type' => 'reconciliation_required']);
        // Historical financial conflicts cannot be erased just by a later failure.
        $tx->update(['payload' => ['vnpay_reconciliation_required' => true, 'vnpay_reconciliation_reason' => 'financial_conflict']]);
        $this->reconcile($tx);
        $this->assertTrue($tx->refresh()->requiresReconciliation());
    }

    #[DataProvider('queryConflicts')]
    public function test_verified_query_conflict_never_marks_paid(array $overrides): void
    {
        [, $order, $tx, $product] = $this->paymentContext();
        $this->fakeQuery($overrides);
        $result = $this->reconcile($tx);
        $this->assertNotSame(PaymentEventOutcome::Processed, $result->outcome);
        $this->assertSame('pending', $order->refresh()->payment_status);
        $this->assertSame('pending', $tx->refresh()->payment_status);
        $this->assertTrue($tx->requiresReconciliation());
        $this->assertSame(3, $product->refresh()->stock);
    }

    public static function queryConflicts(): array
    {
        return [[['vnp_Amount' => '10000']], [['vnp_TxnRef' => 'OTHERATTEMPT']],
            [['vnp_TransactionStatus' => '01']], [['vnp_TransactionStatus' => '04']],
            [['vnp_TransactionStatus' => '07']], [['vnp_ResponseCode' => '91']]];
    }

    #[DataProvider('terminalStates')]
    public function test_terminal_success_is_durable_conflict_not_reopening(string $status, string $payment): void
    {
        [, $order, $tx, $product] = $this->paymentContext();
        $order->update(['status' => $status, 'payment_status' => $payment]);
        $this->fakeQuery();
        $this->assertSame(PaymentEventOutcome::ReconciliationRequired, $this->reconcile($tx)->outcome);
        $this->assertSame($status, $order->refresh()->status);
        $this->assertSame($payment, $order->payment_status);
        $this->assertSame('pending', $tx->refresh()->payment_status);
        $this->assertTrue($tx->requiresReconciliation());
        $this->assertSame(3, $product->refresh()->stock);
        $this->assertDatabaseHas('payment_gateway_events', ['event_type' => 'query_conflict', 'reconciliation_required' => true]);
    }

    public static function terminalStates(): array
    {
        return [['cancelled', 'failed'], ['refunded', 'refunded']];
    }

    public function test_external_identity_conflict_is_never_overwritten(): void
    {
        [, $order, $tx] = $this->paymentContext();
        $tx->update(['gateway_transaction_id' => '7654321']);
        $this->fakeQuery();
        $this->assertSame(PaymentEventOutcome::ReconciliationRequired, $this->reconcile($tx)->outcome);
        $this->assertSame('7654321', $tx->refresh()->gateway_transaction_id);
        $this->assertSame('pending', $order->refresh()->payment_status);
        $this->assertTrue($tx->requiresReconciliation());
    }

    public function test_second_external_success_remains_reconciliation_conflict(): void
    {
        [, $order, $first] = $this->paymentContext();
        $first->update(['payment_status' => 'failed']);
        $second = app(PaymentService::class)->createOrGetPendingTransaction($order);
        $this->ipn($first);
        $this->fakeQuery(['vnp_TransactionNo' => '7654321']);
        $this->assertSame(PaymentEventOutcome::ReconciliationRequired, $this->reconcile($second)->outcome);
        $this->assertSame(1, $order->paymentTransactions()->where('payment_status', 'paid')->count());
        $this->assertSame('paid', $first->refresh()->payment_status);
        $this->assertTrue($second->refresh()->requiresReconciliation());
    }

    public function test_return_only_adds_redacted_observation_and_cannot_settle(): void
    {
        [, $order, $tx, $product] = $this->paymentContext();
        $query = $this->ipnPayload($tx);
        $this->get(route('vnpay.return').'?'.http_build_query($query))->assertOk();
        $this->assertSame('pending', $order->refresh()->payment_status);
        $this->assertSame('pending', $tx->refresh()->payment_status);
        $this->assertSame(3, $product->refresh()->stock);
        $this->assertDatabaseCount('payment_transactions', 1);
        $this->assertDatabaseHas('payment_gateway_events', ['event_type' => 'return_received', 'signature_valid' => true]);
        $this->assertJournalRedacted();
    }

    public function test_initiation_and_ipn_replay_are_durable_and_fingerprint_is_not_unique(): void
    {
        [$user, $order, $tx] = $this->paymentContext();
        app(VnPayPaymentService::class)->paymentUrl($order, $user->id, '127.0.0.1');
        $url = route('vnpay.ipn').'?'.http_build_query($this->ipnPayload($tx));
        $this->get($url)->assertJsonPath('RspCode', '00');
        $this->get($url)->assertJsonPath('RspCode', '02');
        $this->assertDatabaseHas('payment_gateway_events', ['event_type' => 'payment_initiated']);
        $this->assertDatabaseHas('payment_gateway_events', ['event_type' => 'ipn_settled']);
        $this->assertDatabaseHas('payment_gateway_events', ['event_type' => 'ipn_duplicate']);
        $receipts = PaymentGatewayEvent::where('event_type', 'ipn_received')->get();
        $this->assertCount(2, $receipts);
        $this->assertSame($receipts[0]->event_fingerprint, $receipts[1]->event_fingerprint);
        $this->assertJournalRedacted();
    }

    private function assertJournalRedacted(): void
    {
        $json = PaymentGatewayEvent::all()->toJson();
        foreach (['phase6c-fixture-only', 'vnp_SecureHash', 'hash_secret', 'https://', 'Private Customer', 'Private address'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json);
        }
    }

    #[DataProvider('journalMutations')]
    public function test_journal_model_has_no_update_delete_flow(string $operation): void
    {
        $event = app(PaymentGatewayJournal::class)->append(GatewayEventType::IpnReceived);
        $this->expectException(\LogicException::class);
        $operation === 'delete' ? $event->delete() : $event->update(['signature_valid' => true]);
    }

    public static function journalMutations(): array
    {
        return [['update'], ['delete']];
    }

    public function test_settlement_audit_failure_rolls_back_order_and_transaction(): void
    {
        [, $order, $tx] = $this->paymentContext();
        PaymentGatewayEvent::creating(function ($event) {
            if ($event->event_type === GatewayEventType::IpnSettled) {
                throw new RuntimeException('fixture journal failure');
            }
        });
        try {
            $this->get(route('vnpay.ipn').'?'.http_build_query($this->ipnPayload($tx)))->assertJsonPath('RspCode', '99');
        } finally {
            PaymentGatewayEvent::getEventDispatcher()->forget('eloquent.creating: '.PaymentGatewayEvent::class);
        }
        $this->assertSame('pending', $order->refresh()->payment_status);
        $this->assertSame('pending', $tx->refresh()->payment_status);
        $this->assertNull($tx->gateway_transaction_id);
        $this->assertDatabaseMissing('payment_gateway_events', ['event_type' => 'ipn_settled']);
    }

    public function test_expired_verified_failed_order_cancels_once_through_lifecycle(): void
    {
        [, $order, $tx, $product] = $this->paymentContext();
        $this->makeExpired();
        $this->fakeQuery(['vnp_TransactionStatus' => '02']);
        $this->assertSame('cancelled', $this->expire($order));
        $this->assertSame('cancelled', $order->refresh()->status);
        $this->assertSame('failed', $order->payment_status);
        $this->assertSame(5, $product->refresh()->stock);
        $this->assertSame('skipped_conflict', $this->expire($order));
        $this->assertSame(5, $product->refresh()->stock);
        $this->assertSame(1, $order->statusHistory()->where('status', 'cancelled')->count());
        $this->assertDatabaseHas('payment_gateway_events', ['event_type' => 'expiry_cancelled']);
        Http::assertSentCount(1);
    }

    public function test_variant_expiry_restores_variant_only(): void
    {
        [, $order, , $product] = $this->paymentContext();
        $variant = ProductVariant::create(['product_id' => $product->id, 'sku' => 'VARIANT6C', 'price' => '105000.00', 'stock' => 7, 'is_active' => true]);
        $order->items()->first()->update(['product_variant_id' => $variant->id]);
        $this->makeExpired();
        $this->fakeQuery(['vnp_TransactionStatus' => '02']);
        $this->assertSame('cancelled', $this->expire($order));
        $this->assertSame(9, $variant->refresh()->stock);
        $this->assertSame(3, $product->refresh()->stock);
    }

    #[DataProvider('unusableExpiryStates')]
    public function test_ineligible_expiry_does_not_query_or_change_inventory(string $state): void
    {
        [, $order, $tx, $product] = $this->paymentContext();
        if ($state !== 'unexpired') {
            $this->makeExpired();
        }
        match ($state) {
            'cod' => $order->update(['payment_method' => 'cod']),
            'paid' => $this->ipn($tx),
            'cancelled' => app(OrderLifecycleService::class)->transition($order, 'cancelled'),
            'refunded' => $order->update(['status' => 'refunded', 'payment_status' => 'refunded']),
            default => null,
        };
        $before = $product->refresh()->stock;
        $this->assertNotSame('cancelled', $this->expire($order));
        $this->assertSame($before, $product->refresh()->stock);
        Http::assertNothingSent();
    }

    public static function unusableExpiryStates(): array
    {
        return [['unexpired'], ['cod'], ['paid'], ['cancelled'], ['refunded']];
    }

    #[DataProvider('uncertainExpiryStates')]
    public function test_ambiguous_query_never_allows_expiry(string $state): void
    {
        [, $order, $tx, $product] = $this->paymentContext();
        $this->makeExpired();
        if ($state === 'timeout') {
            Http::fake(fn () => throw new ConnectionException('fixture timeout'));
        } else {
            $this->fakeQuery(['vnp_TransactionStatus' => $state]);
        }
        $this->assertSame('skipped_conflict', $this->expire($order));
        $this->assertSame('pending', $order->refresh()->status);
        $this->assertSame(3, $product->refresh()->stock);
        $this->assertTrue($tx->refresh()->requiresReconciliation());
    }

    public static function uncertainExpiryStates(): array
    {
        return [['01'], ['04'], ['07'], ['timeout']];
    }

    public function test_paid_query_settles_instead_of_expiring(): void
    {
        [, $order, $tx, $product] = $this->paymentContext();
        $this->makeExpired();
        $this->fakeQuery();
        $this->assertSame('skipped_paid', $this->expire($order));
        $this->assertSame('paid', $order->refresh()->payment_status);
        $this->assertSame('pending', $order->status);
        $this->assertSame(3, $product->refresh()->stock);
    }

    public function test_old_attempt_paid_wins_during_expiry_with_newer_failed_attempt(): void
    {
        [, $order, $first, $product] = $this->paymentContext();
        $first->update(['payment_status' => 'failed']);
        $second = app(PaymentService::class)->createOrGetPendingTransaction($order);
        $this->makeExpired();
        Http::fake(fn ($request) => Http::response($this->queryResponse($request['vnp_TxnRef'],
            $request['vnp_TxnRef'] === $first->transaction_id ? [] : ['vnp_TransactionStatus' => '02', 'vnp_TransactionNo' => '7654321'])));
        $this->assertSame('skipped_paid', $this->expire($order));
        $this->assertSame('paid', $first->refresh()->payment_status);
        $this->assertSame('failed', $second->refresh()->payment_status);
        $this->assertSame(3, $product->refresh()->stock);
        Http::assertSentCount(2);
    }

    public function test_new_attempt_created_during_query_invalidates_expiry_snapshot(): void
    {
        [, $order, , $product] = $this->paymentContext();
        $this->makeExpired();
        Http::fake(function ($request) use ($order) {
            app(PaymentService::class)->createOrGetPendingTransaction($order);

            return Http::response($this->queryResponse($request['vnp_TxnRef'], ['vnp_TransactionStatus' => '02']));
        });
        $this->assertSame('skipped_conflict', $this->expire($order));
        $this->assertSame('pending', $order->refresh()->status);
        $this->assertSame(3, $product->refresh()->stock);
    }

    public function test_ipn_paid_during_query_prevents_expiry_of_stale_candidate(): void
    {
        [, $order, $tx, $product] = $this->paymentContext();
        $this->makeExpired();
        Http::fake(function ($request) use ($tx) {
            $this->ipn($tx);

            return Http::response($this->queryResponse($request['vnp_TxnRef'], ['vnp_TransactionStatus' => '02']));
        });
        $this->assertSame('skipped_paid', $this->expire($order));
        $this->assertSame('paid', $order->refresh()->payment_status);
        $this->assertSame(3, $product->refresh()->stock);
        $this->assertSame(0, $order->statusHistory()->where('status', 'cancelled')->count());
    }

    public function test_expiry_then_late_ipn_preserves_terminal_state_and_records_money_conflict(): void
    {
        [, $order, $tx, $product] = $this->paymentContext();
        $this->makeExpired();
        $this->fakeQuery(['vnp_TransactionStatus' => '02']);
        $this->assertSame('cancelled', $this->expire($order));
        $this->assertSame(PaymentEventOutcome::ReconciliationRequired, $this->ipn($tx));
        $this->assertSame('cancelled', $order->refresh()->status);
        $this->assertSame('failed', $order->payment_status);
        $this->assertSame(5, $product->refresh()->stock);
        $this->assertTrue($tx->refresh()->requiresReconciliation());
        $this->assertDatabaseHas('payment_gateway_events', ['event_type' => 'ipn_conflict', 'reconciliation_required' => true]);
    }

    public function test_customer_cancel_then_ipn_is_audited_without_second_restore(): void
    {
        [$user, $order, $tx, $product] = $this->paymentContext();
        $this->actingAs($user)->post(route('order.cancel', $order))->assertRedirect();
        $this->assertSame(PaymentEventOutcome::ReconciliationRequired, $this->ipn($tx));
        $this->assertSame('cancelled', $order->refresh()->status);
        $this->assertSame(5, $product->refresh()->stock);
        $this->assertTrue($tx->refresh()->requiresReconciliation());
    }

    public function test_ipn_paid_then_customer_cancel_does_not_restore_stock(): void
    {
        [$user, $order, $tx, $product] = $this->paymentContext();
        $this->ipn($tx);
        $this->actingAs($user)->post(route('order.cancel', $order))->assertSessionHasErrors('status');
        $this->assertSame('paid', $order->refresh()->payment_status);
        $this->assertSame(3, $product->refresh()->stock);
    }

    public function test_query_and_ipn_success_interleaving_remains_idempotent(): void
    {
        [, $order, $tx] = $this->paymentContext();
        Http::fake(function ($request) use ($tx) {
            $this->ipn($tx);

            return Http::response($this->queryResponse($request['vnp_TxnRef']));
        });
        $this->assertSame(PaymentEventOutcome::AlreadyProcessed, $this->reconcile($tx)->outcome);
        $this->assertSame(1, $order->paymentTransactions()->where('payment_status', 'paid')->count());
    }

    public function test_two_expiry_workers_interleaved_restore_once(): void
    {
        [, $order, , $product] = $this->paymentContext();
        $this->makeExpired();
        $nested = false;
        Http::fake(function ($request) use ($order, &$nested) {
            if (! $nested) {
                $nested = true;
                $this->assertSame('cancelled', $this->expire($order));
            }

            return Http::response($this->queryResponse($request['vnp_TxnRef'], ['vnp_TransactionStatus' => '02']));
        });
        $this->assertSame('skipped_conflict', $this->expire($order));
        $this->assertSame(5, $product->refresh()->stock);
        $this->assertSame(1, $order->statusHistory()->where('status', 'cancelled')->count());
    }

    public function test_expiry_audit_failure_rolls_back_cancellation_and_restore(): void
    {
        [, $order, , $product] = $this->paymentContext();
        $this->makeExpired();
        $this->fakeQuery(['vnp_TransactionStatus' => '02']);
        PaymentGatewayEvent::creating(function ($event) {
            if ($event->event_type === GatewayEventType::ExpiryCancelled) {
                throw new RuntimeException('fixture audit failure');
            }
        });
        try {
            $this->expire($order);
            $this->fail('Cancellation audit must be atomic.');
        } catch (RuntimeException) {
            $this->assertSame('pending', $order->refresh()->status);
            $this->assertSame(3, $product->refresh()->stock);
            $this->assertSame(0, $order->statusHistory()->count());
        } finally {
            PaymentGatewayEvent::getEventDispatcher()->forget('eloquent.creating: '.PaymentGatewayEvent::class);
        }
    }

    public function test_command_is_bounded_dry_run_and_repeated_safe(): void
    {
        [, $order] = $this->paymentContext();
        $this->makeExpired();
        $this->artisan('payments:expire-vnpay', ['--dry-run' => true, '--limit' => 1])->assertSuccessful();
        Http::assertNothingSent();
        $this->assertDatabaseCount('payment_gateway_events', 0);
        $this->assertSame('pending', $order->refresh()->status);
        $this->fakeQuery(['vnp_TransactionStatus' => '02']);
        $this->artisan('payments:expire-vnpay', ['--limit' => 1])->expectsOutputToContain('"cancelled":1')->assertSuccessful();
        $this->artisan('payments:expire-vnpay')->expectsOutputToContain('"scanned":0')->assertSuccessful();
        $this->artisan('payments:expire-vnpay', ['--limit' => 101])->assertExitCode(2);
        Http::assertSentCount(1);
    }

    public function test_admin_post_reconciles_only_persisted_target_and_ui_is_safe(): void
    {
        [, $order, $tx] = $this->paymentContext();
        $admin = $this->actor('admin');
        $this->actingAs($admin)->get(route('admin.orders.show', $order))->assertOk()
            ->assertSee('Đối soát VNPay')->assertDontSee('phase6c-fixture-only');
        $this->fakeQuery();
        $this->post(route('admin.orders.payments.reconcile', [$order, $tx]), [
            'amount' => '1', 'transaction_id' => 'OTHER', 'url' => 'https://evil.example.test',
        ])->assertRedirect(route('admin.orders.show', $order));
        $this->assertSame('paid', $order->refresh()->payment_status);
        Http::assertSent(fn ($request) => $request['vnp_TxnRef'] === $tx->transaction_id);
        $this->assertDatabaseHas('payment_gateway_events', ['event_type' => 'query_requested']);
        $route = app('router')->getRoutes()->getByName('admin.orders.payments.reconcile');
        $this->assertSame(['POST'], $route->methods());
        $this->assertContains('admin', $route->gatherMiddleware());
        $this->assertContains('permission:orders.update', $route->gatherMiddleware());
        $this->assertSame([], $route->excludedMiddleware());
    }

    #[DataProvider('forbiddenActors')]
    public function test_customer_vendor_staff_and_inactive_admin_cannot_query(string $role, bool $active): void
    {
        [, $order, $tx] = $this->paymentContext();
        $actor = $this->actor($role, $active);
        $response = $this->actingAs($actor)->post(route('admin.orders.payments.reconcile', [$order, $tx]));
        $active ? $response->assertForbidden() : $response->assertRedirect(route('login'));
        Http::assertNothingSent();
        $this->assertSame('pending', $order->refresh()->payment_status);
    }

    public static function forbiddenActors(): array
    {
        return [['customer', true], ['vendor', true], ['staff', true], ['admin', false]];
    }

    private function actor(string $role, bool $active = true): User
    {
        $user = User::factory()->create(['is_active' => $active]);
        $assigned = Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $permission = Permission::firstOrCreate(['name' => 'orders.update', 'guard_name' => 'web']);
        $assigned->permissions()->syncWithoutDetaching([$permission->id]);
        $user->roles()->attach($assigned);

        return $user;
    }

    public function test_cross_order_target_and_guest_are_rejected_before_http(): void
    {
        [, $order] = $this->paymentContext();
        [, , $other] = $this->paymentContext();
        $url = route('admin.orders.payments.reconcile', [$order, $other]);
        $this->post($url)->assertRedirect(route('login'));
        $this->actingAs($this->actor('admin'))->post($url)->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_known_financial_conflict_blocks_customer_and_admin_cancellation(): void
    {
        [$user, $order, $tx, $product] = $this->paymentContext();
        $this->fakeQuery(['vnp_Amount' => '10000']);
        $this->reconcile($tx);
        $this->actingAs($user)->post(route('order.cancel', $order))->assertSessionHasErrors('status');
        $this->actingAs($this->actor('admin'))->post(route('admin.orders.status', $order), ['status' => 'cancelled'])
            ->assertSessionHasErrors('status');
        $this->assertSame('pending', $order->refresh()->status);
        $this->assertSame(3, $product->refresh()->stock);
        $this->assertSame(0, $order->statusHistory()->count());
    }

    public function test_two_reconciliations_interleaved_have_one_paid_effect(): void
    {
        [, $order, $tx] = $this->paymentContext();
        $inside = false;
        Http::fake(function ($request) use (&$inside, $tx) {
            if (! $inside) {
                $inside = true;
                $this->assertSame(PaymentEventOutcome::Processed, $this->reconcile($tx)->outcome);
            }

            return Http::response($this->queryResponse($request['vnp_TxnRef']));
        });
        $this->assertSame(PaymentEventOutcome::AlreadyProcessed, $this->reconcile($tx)->outcome);
        $this->assertSame(1, $order->paymentTransactions()->where('payment_status', 'paid')->count());
        $queries = PaymentGatewayEvent::where('event_type', 'query_requested')->get();
        $this->assertCount(2, $queries);
        $this->assertNotSame($queries[0]->metadata['request_id'], $queries[1]->metadata['request_id']);
    }

    public function test_settlement_rechecks_current_order_amount_after_http(): void
    {
        [, $order, $tx] = $this->paymentContext();
        Http::fake(function ($request) use ($order) {
            $order->update(['total_amount' => '220000.00']);

            return Http::response($this->queryResponse($request['vnp_TxnRef']));
        });
        $this->assertSame(PaymentEventOutcome::AmountMismatch, $this->reconcile($tx)->outcome);
        $this->assertSame('pending', $tx->refresh()->payment_status);
        $this->assertSame('pending', $order->refresh()->payment_status);
        $this->assertTrue($tx->requiresReconciliation());
    }

    public function test_query_failure_after_ipn_paid_does_not_downgrade_local_paid(): void
    {
        [, $order, $tx] = $this->paymentContext();
        $this->ipn($tx);
        $this->fakeQuery(['vnp_TransactionStatus' => '02']);
        $this->assertSame(PaymentEventOutcome::ReconciliationRequired, $this->reconcile($tx)->outcome);
        $this->assertSame('paid', $order->refresh()->payment_status);
        $this->assertSame('paid', $tx->refresh()->payment_status);
        $this->assertTrue($tx->requiresReconciliation());
    }

    public function test_verified_failed_query_then_success_can_settle_normally(): void
    {
        [, $order, $tx] = $this->paymentContext();
        Http::fakeSequence()->push($this->queryResponse($tx->transaction_id, ['vnp_TransactionStatus' => '02']))
            ->push($this->queryResponse($tx->transaction_id));
        $this->assertTrue($this->reconcile($tx)->provesUnpaid());
        $this->assertSame(PaymentEventOutcome::Processed, $this->reconcile($tx)->outcome);
        $this->assertSame('paid', $order->refresh()->payment_status);
        $this->assertFalse($tx->refresh()->requiresReconciliation());
    }

    public function test_query_external_id_cannot_be_used_across_orders(): void
    {
        [, , $first] = $this->paymentContext();
        [, $order, $second] = $this->paymentContext();
        $this->ipn($first);
        $this->fakeQuery();
        $this->assertSame(PaymentEventOutcome::ReconciliationRequired, $this->reconcile($second)->outcome);
        $this->assertSame('pending', $order->refresh()->payment_status);
        $this->assertNull($second->refresh()->gateway_transaction_id);
    }

    public function test_query_audit_failure_rolls_back_settlement_but_keeps_verified_response_evidence(): void
    {
        [, $order, $tx] = $this->paymentContext();
        $this->fakeQuery();
        PaymentGatewayEvent::creating(function ($event) {
            if ($event->event_type === GatewayEventType::QuerySettled) {
                throw new RuntimeException('fixture failure');
            }
        });
        try {
            $this->reconcile($tx);
            $this->fail('Must rollback settlement.');
        } catch (RuntimeException) {
            $this->assertSame('pending', $tx->refresh()->payment_status);
            $this->assertSame('pending', $order->refresh()->payment_status);
            $this->assertDatabaseHas('payment_gateway_events', ['event_type' => 'query_response', 'signature_valid' => true]);
        } finally {
            PaymentGatewayEvent::getEventDispatcher()->forget('eloquent.creating: '.PaymentGatewayEvent::class);
        }
    }

    public function test_observation_audit_failure_does_not_block_required_settlement_audit(): void
    {
        [, $order, $tx] = $this->paymentContext();
        PaymentGatewayEvent::creating(function ($event) {
            if ($event->event_type === GatewayEventType::IpnReceived) {
                throw new RuntimeException('fixture observation failure');
            }
        });
        try {
            $this->get(route('vnpay.ipn').'?'.http_build_query($this->ipnPayload($tx)))->assertJsonPath('RspCode', '00');
        } finally {
            PaymentGatewayEvent::getEventDispatcher()->forget('eloquent.creating: '.PaymentGatewayEvent::class);
        }
        $this->assertSame('paid', $order->refresh()->payment_status);
        $this->assertDatabaseHas('payment_gateway_events', ['event_type' => 'ipn_settled']);
    }

    public function test_missing_attempt_or_expiry_never_blind_cancels(): void
    {
        [, $order, $tx, $product] = $this->paymentContext();
        $this->makeExpired();
        $tx->update(['expires_at' => null]);
        $this->assertSame('skipped_conflict', $this->expire($order));
        $this->assertSame('pending', $order->refresh()->status);
        $this->assertSame(3, $product->refresh()->stock);
        Http::assertNothingSent();
    }

    public function test_all_failed_attempts_must_be_queried_before_single_restore(): void
    {
        [, $order, $first, $product] = $this->paymentContext();
        $first->update(['payment_status' => 'failed']);
        $second = app(PaymentService::class)->createOrGetPendingTransaction($order);
        $this->makeExpired();
        Http::fake(fn ($request) => Http::response($this->queryResponse($request['vnp_TxnRef'],
            ['vnp_TransactionStatus' => '02', 'vnp_TransactionNo' => $request['vnp_TxnRef'] === $first->transaction_id ? '1234567' : '7654321'])));
        $this->assertSame('cancelled', $this->expire($order));
        Http::assertSentCount(2);
        $this->assertSame(5, $product->refresh()->stock);
        $this->assertSame(1, $order->statusHistory()->count());
        $this->assertSame('failed', $second->refresh()->payment_status);
    }

    #[DataProvider('badQueryConfiguration')]
    public function test_query_configuration_fails_closed_before_http(string $key, mixed $value): void
    {
        [, $order, $tx] = $this->paymentContext();
        config()->set($key, $value);
        $this->assertSame(PaymentEventOutcome::TemporaryFailure, $this->reconcile($tx)->outcome);
        Http::assertNothingSent();
        $this->assertSame('pending', $order->refresh()->payment_status);
    }

    public static function badQueryConfiguration(): array
    {
        return [['vnpay.query_url', null], ['vnpay.query_url', 'http://sandbox.vnpayment.vn/merchant_webapi/api/transaction'],
            ['vnpay.query_url', 'https://evil.example.test/transaction'], ['vnpay.query_server_ip', 'invalid'],
            ['vnpay.enabled', false]];
    }
}
