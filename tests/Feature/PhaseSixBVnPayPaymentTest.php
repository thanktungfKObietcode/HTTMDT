<?php

namespace Tests\Feature;

use App\Contracts\PaymentGateway;
use App\Models\Cart;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\Refund;
use App\Models\ShippingMethod;
use App\Models\User;
use App\Payments\PaymentEventOutcome;
use App\Payments\PaymentUrlRequest;
use App\Payments\VnPayGateway;
use App\Services\OrderLifecycleService;
use App\Services\PaymentService;
use App\Support\PaymentAttemptReference;
use App\Support\VnPayAmount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class PhaseSixBVnPayPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->travelTo(now()->setDate(2026, 9, 9)->setTime(2, 0, 0));
        config()->set([
            'vnpay.enabled' => true,
            'vnpay.tmn_code' => 'TEST1234',
            'vnpay.hash_secret' => 'phase6b-fixture-only',
            'vnpay.payment_url' => 'https://sandbox.vnpayment.vn/paymentv2/vpcpay.html',
            'vnpay.return_url' => 'https://shop.example.test/thanh-toan/vnpay/return',
            'vnpay.ipn_url' => 'https://shop.example.test/thanh-toan/vnpay/ipn',
            'vnpay.version' => '2.1.0',
            'vnpay.locale' => 'vn',
            'vnpay.currency' => 'VND',
            'vnpay.order_type' => 'other',
            'vnpay.timezone' => 'Asia/Ho_Chi_Minh',
            'vnpay.expire_minutes' => 15,
        ]);
    }

    public function test_checkout_commits_inventory_order_attempt_and_expiry_before_building_url(): void
    {
        [$user, $shipping, $product, $cart] = $this->checkoutContext();
        $level = DB::transactionLevel();
        $adapter = app(VnPayGateway::class);
        $mock = \Mockery::mock(PaymentGateway::class);
        $mock->shouldReceive('assertConfigured')->andReturnUsing(fn () => $adapter->assertConfigured());
        $mock->shouldReceive('buildPaymentUrl')->once()->andReturnUsing(function (PaymentUrlRequest $input) use ($level, $adapter, $cart, $product) {
            $this->assertSame($level, DB::transactionLevel(), 'Gateway must run outside checkout/payment transactions.');
            $this->assertSame(0, $cart->items()->count());
            $this->assertSame(3, $product->refresh()->stock);
            $this->assertDatabaseCount('orders', 1);
            $this->assertDatabaseCount('payment_transactions', 1);

            return $adapter->buildPaymentUrl($input);
        });
        $this->app->instance(PaymentGateway::class, $mock);

        $response = $this->actingAs($user)->post(route('checkout.store'), $this->checkoutPayload($shipping) + [
            'amount' => '1', 'return_url' => 'https://evil.example.test', 'transaction_id' => 'ATTACK',
        ])->assertRedirect();
        $order = Order::firstOrFail();
        $tx = PaymentTransaction::firstOrFail();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame('vnpay', $order->payment_method);
        $this->assertSame('pending', $order->payment_status);
        $this->assertSame('pending', $tx->payment_status);
        $this->assertSame('21000000', $query['vnp_Amount']);
        $this->assertSame($tx->transaction_id, $query['vnp_TxnRef']);
        $this->assertSame(config('vnpay.return_url'), $query['vnp_ReturnUrl']);
        $this->assertSame('20260909090000', $query['vnp_CreateDate']);
        $this->assertSame('20260909091500', $query['vnp_ExpireDate']);
        $this->assertTrue($order->payment_expires_at->equalTo($tx->expires_at));
        $this->assertSame('210000.00', $tx->amount);
        $this->assertTrue($adapter->verifySignature($query));
    }

    #[DataProvider('unavailableMethods')]
    public function test_unavailable_checkout_method_fails_before_creating_order(string $method, string $configKey, mixed $value): void
    {
        [$user, $shipping, $product, $cart] = $this->checkoutContext();
        config()->set($configKey, $value);
        $payload = $this->checkoutPayload($shipping);
        $payload['payment_method'] = $method;
        $this->actingAs($user)->post(route('checkout.store'), $payload)->assertSessionHasErrors('payment_method');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('payment_transactions', 0);
        $this->assertSame(5, $product->refresh()->stock);
        $this->assertSame(1, $cart->items()->count());
    }

    public static function unavailableMethods(): array
    {
        return [
            ['vnpay', 'vnpay.enabled', false],
            ['vnpay', 'vnpay.enabled', 'true'],
            ['vnpay', 'vnpay.hash_secret', ''],
            ['vnpay', 'vnpay.payment_url', ''],
            ['vnpay', 'vnpay.payment_url', 'https://non-sandbox.example.test/pay'],
            ['simulated-online', 'vnpay.enabled', true],
        ];
    }

    public function test_cod_checkout_is_unchanged_with_vnpay_enabled(): void
    {
        [$user, $shipping] = $this->checkoutContext();
        $payload = $this->checkoutPayload($shipping);
        $payload['payment_method'] = 'cod';
        $response = $this->actingAs($user)->post(route('checkout.store'), $payload);
        $order = Order::firstOrFail();
        $response->assertRedirect(route('order.show', $order));
        $this->assertSame('cod', PaymentTransaction::first()->gateway);
        $this->assertNull($order->payment_expires_at);
    }

    public function test_checkout_rolls_back_invalid_vnd_amount_without_losing_cart_or_stock(): void
    {
        [$user, $shipping, $product, $cart] = $this->checkoutContext();
        $product->update(['price' => '100000.01']);
        $this->actingAs($user)->post(route('checkout.store'), $this->checkoutPayload($shipping))->assertSessionHasErrors('checkout');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('payment_transactions', 0);
        $this->assertSame(5, $product->refresh()->stock);
        $this->assertSame(1, $cart->items()->count());
    }

    public function test_url_generation_failure_keeps_committed_order_and_same_retry_attempt(): void
    {
        [$user, $shipping, $product, $cart] = $this->checkoutContext();
        $adapter = app(VnPayGateway::class);
        $mock = \Mockery::mock(PaymentGateway::class);
        $mock->shouldReceive('assertConfigured')->andReturnNull();
        $mock->shouldReceive('buildPaymentUrl')->once()->andThrow(new RuntimeException('fixture failure'));
        $this->app->instance(PaymentGateway::class, $mock);
        $this->actingAs($user)->post(route('checkout.store'), $this->checkoutPayload($shipping))->assertSessionHasErrors('payment');
        $order = Order::firstOrFail();
        $tx = PaymentTransaction::firstOrFail();
        $this->assertSame('pending', $tx->payment_status);
        $this->assertSame(3, $product->refresh()->stock);
        $this->assertSame(0, $cart->items()->count());
        $this->app->instance(PaymentGateway::class, $adapter);
        $this->actingAs($user)->post(route('vnpay.initiate', $order))->assertRedirect();
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('payment_transactions', 1);
        $this->assertSame($tx->transaction_id, PaymentTransaction::first()->transaction_id);
    }

    public function test_checkout_token_replay_never_duplicates_vnpay_order(): void
    {
        [$user, $shipping, $product] = $this->checkoutContext();
        $payload = $this->checkoutPayload($shipping);
        $this->actingAs($user)->post(route('checkout.store'), $payload)->assertRedirect();
        $order = Order::firstOrFail();
        $this->post(route('checkout.store'), $payload)->assertRedirect(route('order.show', $order));
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('payment_transactions', 1);
        $this->assertSame(3, $product->refresh()->stock);
    }

    public function test_retry_reuses_pending_reference_and_persisted_dates(): void
    {
        [$user, $order, $tx, $product] = $this->orderContext();
        $first = $this->actingAs($user)->post(route('vnpay.initiate', $order))->assertRedirect();
        $this->travel(2)->minutes();
        $second = $this->post(route('vnpay.initiate', $order))->assertRedirect();
        $this->assertSame($first->headers->get('Location'), $second->headers->get('Location'));
        $this->assertDatabaseCount('payment_transactions', 1);
        $this->assertSame($tx->transaction_id, PaymentTransaction::first()->transaction_id);
        $this->assertSame(3, $product->refresh()->stock);
    }

    public function test_duplicate_pending_attempts_consolidate_under_order_lock(): void
    {
        [$user, $order, $first] = $this->orderContext();
        $second = $first->replicate();
        $second->transaction_id = PaymentAttemptReference::generate();
        $second->save();
        $this->actingAs($user)->post(route('vnpay.initiate', $order))->assertRedirect();
        $this->assertSame('pending', $first->refresh()->payment_status);
        $this->assertSame('failed', $second->refresh()->payment_status);
        $this->assertSame(1, $order->paymentTransactions()->where('payment_status', 'pending')->count());
    }

    public function test_retry_revalidates_paid_transaction_even_if_order_model_is_stale(): void
    {
        [, $order, $tx] = $this->orderContext();
        $this->assertIpn($this->signed($tx), '00', 'Confirm Success');
        $this->assertSame('pending', $order->payment_status);
        try {
            app(PaymentService::class)->createOrGetPendingTransaction($order);
            $this->fail('Locked paid state must prohibit a new VNPay attempt.');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('payment_transactions', 1);
            $this->assertSame('paid', $order->refresh()->payment_status);
        }
    }

    #[DataProvider('retryStates')]
    public function test_failed_or_expired_attempt_gets_new_reference(string $state): void
    {
        [$user, $order, $tx, $product] = $this->orderContext();
        if ($state === 'expired') {
            $this->travel(16)->minutes();
        } else {
            $tx->update(['payment_status' => 'failed']);
        }
        $this->actingAs($user)->post(route('vnpay.initiate', $order))->assertRedirect();
        $new = $order->paymentTransactions()->latest('id')->first();
        $this->assertNotSame($tx->transaction_id, $new->transaction_id);
        $this->assertSame('failed', $tx->refresh()->payment_status);
        $this->assertSame(1, $order->paymentTransactions()->where('payment_status', 'pending')->count());
        $this->assertTrue($order->refresh()->payment_expires_at->equalTo($new->expires_at));
        $this->assertSame(3, $product->refresh()->stock);
    }

    public static function retryStates(): array
    {
        return [['expired'], ['failed']];
    }

    public function test_retry_is_owner_only_and_active_authenticated_only(): void
    {
        [$owner, $order, $tx] = $this->orderContext();
        $this->post(route('vnpay.initiate', $order))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->post(route('vnpay.initiate', $order))->assertForbidden();
        $owner->update(['is_active' => false]);
        $this->actingAs($owner)->post(route('vnpay.initiate', $order))->assertRedirect(route('login'));
        $this->assertDatabaseCount('payment_transactions', 1);
        $this->assertSame('pending', $tx->refresh()->payment_status);
    }

    #[DataProvider('blockedInitiations')]
    public function test_terminal_paid_and_wrong_method_cannot_initiate(string $status, string $payment, string $method): void
    {
        [$user, $order, $tx] = $this->orderContext();
        $order->update(['status' => $status, 'payment_status' => $payment, 'payment_method' => $method]);
        $this->actingAs($user)->post(route('vnpay.initiate', $order))->assertSessionHasErrors('payment');
        $this->assertDatabaseCount('payment_transactions', 1);
        $this->assertSame('pending', $tx->refresh()->payment_status);
    }

    public static function blockedInitiations(): array
    {
        return [['pending', 'paid', 'vnpay'], ['cancelled', 'failed', 'vnpay'], ['refunded', 'refunded', 'vnpay'], ['pending', 'pending', 'cod']];
    }

    public function test_vnpay_payment_page_is_read_only_and_retry_visibility_matches_state(): void
    {
        [$user, $order] = $this->orderContext();
        $this->actingAs($user)->get(route('payment.show', $order))->assertOk()->assertSee(route('vnpay.initiate', $order), false);
        $this->get(route('order.show', $order))->assertOk()->assertSee(route('vnpay.initiate', $order), false);
        $this->assertDatabaseCount('payment_transactions', 1);
        $order->update(['payment_status' => 'paid']);
        $this->get(route('order.show', $order))->assertDontSee(route('vnpay.initiate', $order), false);
    }

    public function test_checkout_selector_hides_unavailable_vnpay(): void
    {
        [$user] = $this->checkoutContext();
        $this->actingAs($user)->get(route('checkout.index'))->assertSee('value="vnpay"', false);
        config()->set('vnpay.hash_secret', '');
        $this->get(route('checkout.index'))->assertDontSee('value="vnpay"', false);
    }

    public function test_return_before_ipn_and_after_ipn_remains_read_only(): void
    {
        [$user, $order, $tx, $product] = $this->orderContext();
        $query = $this->signed($tx);
        $before = $tx->refresh()->getAttributes();
        $orderBefore = $order->refresh()->getAttributes();
        for ($i = 0; $i < 2; $i++) {
            $this->actingAs($user)->get($this->returnUrl($query))->assertOk()->assertViewHas('state', 'pending')->assertSee($order->order_number);
        }
        $this->assertSame($before, $tx->refresh()->getAttributes());
        $this->assertSame($orderBefore, $order->refresh()->getAttributes());
        $this->assertIpn($query, '00', 'Confirm Success');
        $settled = $tx->refresh()->getAttributes();
        $this->get($this->returnUrl($query))->assertViewHas('state', 'paid');
        $this->assertSame($settled, $tx->refresh()->getAttributes());
        $this->assertSame(3, $product->refresh()->stock);
        $this->assertDatabaseCount('payment_transactions', 1);
    }

    public function test_sessionless_and_other_owner_return_exposes_no_order_details(): void
    {
        [, $order, $tx] = $this->orderContext();
        $query = $this->signed($tx);
        $this->get($this->returnUrl($query))->assertOk()->assertViewHas('order', null)->assertDontSee($order->order_number)->assertDontSee($order->customer_email);
        $this->actingAs(User::factory()->create())->get($this->returnUrl($query))->assertOk()->assertViewHas('order', null)->assertDontSee($order->shipping_address);
    }

    #[DataProvider('returnCases')]
    public function test_return_invalid_failed_and_unknown_results_are_read_only(array $overrides, string $state, bool $tamper): void
    {
        [, $order, $tx, $product] = $this->orderContext();
        $before = $tx->getAttributes();
        $query = $this->signed($tx, $overrides);
        if ($tamper) {
            $query['vnp_SecureHash'] = str_repeat('0', 128);
        }
        $this->get($this->returnUrl($query))->assertOk()->assertViewHas('state', $state);
        $this->assertSame($before, $tx->refresh()->getAttributes());
        $this->assertSame('pending', $order->refresh()->payment_status);
        $this->assertSame(3, $product->refresh()->stock);
        $this->assertDatabaseCount('payment_transactions', 1);
    }

    public static function returnCases(): array
    {
        return [
            [['vnp_ResponseCode' => '24', 'vnp_TransactionStatus' => '02'], 'failed', false],
            [[], 'invalid', true],
            [['vnp_TxnRef' => 'UNKNOWNREFERENCE'], 'unknown', false],
            [['vnp_Amount' => '100'], 'invalid', false],
        ];
    }

    public function test_service_rejects_verified_return_event_as_financial_input(): void
    {
        [, $order, $tx] = $this->orderContext();
        $event = app(VnPayGateway::class)->verifyReturn($this->signed($tx));
        $this->assertSame(PaymentEventOutcome::InvalidEvent, app(PaymentService::class)->settleVerifiedEvent($event));
        $this->assertSame('pending', $order->refresh()->payment_status);
    }

    public function test_valid_success_ipn_settles_exactly_once_and_preserves_fulfillment_and_inventory(): void
    {
        [, $order, $tx, $product] = $this->orderContext();
        $query = $this->signed($tx);
        $this->assertIpn($query, '00', 'Confirm Success');
        $this->assertSame('paid', $order->refresh()->payment_status);
        $this->assertSame('pending', $order->status);
        $this->assertSame('paid', $tx->refresh()->payment_status);
        $this->assertSame('14567890', $tx->gateway_transaction_id);
        $this->assertSame('00', $tx->gateway_response_code);
        $this->assertSame('00', $tx->gateway_transaction_status);
        $this->assertTrue($tx->paid_at->equalTo($order->paid_at));
        $before = $tx->getAttributes();
        $this->assertIpn($query, '02', 'Order already confirmed');
        $this->assertSame($before, $tx->refresh()->getAttributes());
        $this->assertSame(3, $product->refresh()->stock);
        $this->assertDatabaseCount('order_status_history', 0);
        $this->assertStringNotContainsString('vnp_SecureHash', json_encode($tx->payload));
        $this->assertStringNotContainsString(config('vnpay.hash_secret'), json_encode($tx->payload));
    }

    public function test_ipn_before_first_return_is_read_only_on_return(): void
    {
        [$user, $order, $tx] = $this->orderContext();
        $query = $this->signed($tx);
        $this->assertIpn($query, '00', 'Confirm Success');
        $before = $order->refresh()->getAttributes();
        $this->actingAs($user)->get($this->returnUrl($query))->assertViewHas('state', 'paid')->assertSee($order->order_number);
        $this->assertSame($before, $order->refresh()->getAttributes());
        $this->assertDatabaseCount('payment_transactions', 1);
    }

    public function test_success_requires_both_gateway_codes(): void
    {
        [, $order, $tx] = $this->orderContext();
        $this->assertIpn($this->signed($tx, ['vnp_TransactionStatus' => '02']), '00', 'Confirm Success');
        $this->assertSame('failed', $order->refresh()->payment_status);
        $this->assertSame('failed', $tx->refresh()->payment_status);
    }

    public function test_failure_replay_and_placeholder_zero_ids_are_safe_across_orders(): void
    {
        [, , $first] = $this->orderContext();
        [, , $second] = $this->orderContext();
        $overrides = ['vnp_ResponseCode' => '24', 'vnp_TransactionStatus' => '02', 'vnp_TransactionNo' => '0'];
        $firstQuery = $this->signed($first, $overrides);
        $this->assertIpn($firstQuery, '00', 'Confirm Success');
        $this->assertIpn($firstQuery, '02', 'Order already confirmed');
        $this->assertIpn($this->signed($second, $overrides), '00', 'Confirm Success');
        $this->assertSame('failed', $first->refresh()->payment_status);
        $this->assertSame('failed', $second->refresh()->payment_status);
        $this->assertNull($first->gateway_transaction_id);
        $this->assertNull($second->gateway_transaction_id);
    }

    #[DataProvider('invalidIpnCases')]
    public function test_invalid_ipn_has_deterministic_contract_and_no_financial_mutation(array $overrides, string $code, string $message, bool $tamper = false): void
    {
        [, $order, $tx, $product] = $this->orderContext();
        $query = $this->signed($tx, $overrides);
        if ($tamper) {
            $query['vnp_SecureHash'] = str_repeat('0', 128);
        }
        $this->assertIpn($query, $code, $message);
        $this->assertSame('pending', $order->refresh()->payment_status);
        $this->assertSame('pending', $tx->refresh()->payment_status);
        $this->assertNull($tx->gateway_transaction_id);
        $this->assertSame(3, $product->refresh()->stock);
    }

    public static function invalidIpnCases(): array
    {
        return [
            [[], '97', 'Invalid signature', true],
            [['vnp_TmnCode' => 'OTHER123'], '99', 'Unknown error'],
            [['vnp_Version' => '2.0.1'], '99', 'Unknown error'],
            [['vnp_TxnRef' => 'UNKNOWNREFERENCE'], '01', 'Order not found'],
            [['vnp_Amount' => '100'], '04', 'Invalid amount'],
            [['vnp_Amount' => 'garbage'], '04', 'Invalid amount'],
            [['vnp_CurrCode' => 'USD'], '99', 'Unknown error'],
            [['vnp_TransactionNo' => null], '99', 'Unknown error'],
            [['vnp_TransactionNo' => '0'], '99', 'Unknown error'],
            [['vnp_PayDate' => '20260231000000'], '99', 'Unknown error'],
            [['vnp_TransactionStatus' => 'bogus'], '99', 'Unknown error'],
        ];
    }

    #[DataProvider('storedMismatchCases')]
    public function test_ipn_checks_persisted_gateway_currency_and_both_amounts(string $target, string $field, string $value, string $code): void
    {
        [, $order, $tx] = $this->orderContext();
        $query = $this->signed($tx);
        ($target === 'order' ? $order : $tx)->update([$field => $value]);
        $this->assertIpn($query, $code, $code === '04' ? 'Invalid amount' : 'Unknown error');
        $this->assertSame('pending', $order->refresh()->payment_status);
        $this->assertSame('pending', $tx->refresh()->payment_status);
    }

    public static function storedMismatchCases(): array
    {
        return [['order', 'total_amount', '100.00', '04'], ['tx', 'amount', '100.00', '04'], ['tx', 'gateway', 'cod', '99'], ['order', 'payment_method', 'cod', '99'], ['tx', 'currency', 'USD', '99']];
    }

    public function test_failure_then_success_settles_and_stale_failure_cannot_downgrade_paid(): void
    {
        [, $order, $tx] = $this->orderContext();
        $failed = $this->signed($tx, ['vnp_ResponseCode' => '24', 'vnp_TransactionStatus' => '02', 'vnp_TransactionNo' => '0']);
        $this->assertIpn($failed, '00', 'Confirm Success');
        $this->assertSame('failed', $tx->refresh()->payment_status);
        $this->assertSame('failed', $order->refresh()->payment_status);
        $this->assertNull($tx->gateway_transaction_id, 'VNPay failure placeholder zero is not a globally unique transaction ID.');
        $this->assertIpn($this->signed($tx), '00', 'Confirm Success');
        $this->assertIpn($failed, '02', 'Order already confirmed');
        $this->assertSame('paid', $tx->refresh()->payment_status);
        $this->assertSame('paid', $order->refresh()->payment_status);
        $this->assertSame('00', $tx->gateway_response_code);
    }

    public function test_successful_old_attempt_wins_over_new_pending_attempt(): void
    {
        [$user, $order, $old] = $this->orderContext();
        $this->assertIpn($this->signed($old, ['vnp_ResponseCode' => '24', 'vnp_TransactionStatus' => '02', 'vnp_TransactionNo' => '0']), '00', 'Confirm Success');
        $this->actingAs($user)->post(route('vnpay.initiate', $order))->assertRedirect();
        $new = $order->paymentTransactions()->latest('id')->first();
        $this->assertIpn($this->signed($old), '00', 'Confirm Success');
        $this->assertSame('paid', $old->refresh()->payment_status);
        $this->assertSame('failed', $new->refresh()->payment_status);
        $this->assertSame('another_attempt_settled', $new->payload['closed_reason']);
        $this->assertIpn($this->signed($new, ['vnp_TransactionNo' => '14567891']), '99', 'Reconciliation required');
        $this->assertSame('failed', $new->refresh()->payment_status);
        $this->assertTrue($new->payload['vnpay_reconciliation_required']);
        $this->assertSame(1, $order->paymentTransactions()->where('payment_status', 'paid')->count());
    }

    public function test_late_failure_of_old_attempt_does_not_fail_new_pending_order(): void
    {
        [$user, $order, $old] = $this->orderContext();
        $this->travel(16)->minutes();
        $this->actingAs($user)->post(route('vnpay.initiate', $order));
        $this->assertIpn($this->signed($old, ['vnp_ResponseCode' => '24', 'vnp_TransactionStatus' => '02']), '02', 'Order already confirmed');
        $this->assertSame('pending', $order->refresh()->payment_status);
    }

    #[DataProvider('terminalStates')]
    public function test_terminal_order_success_is_recorded_for_reconciliation_without_reopening(string $status, string $payment): void
    {
        [, $order, $tx, $product] = $this->orderContext();
        $order->update(['status' => $status, 'payment_status' => $payment]);
        $this->assertIpn($this->signed($tx), '99', 'Reconciliation required');
        $this->assertSame($status, $order->refresh()->status);
        $this->assertSame($payment, $order->payment_status);
        $this->assertSame('pending', $tx->refresh()->payment_status);
        $this->assertTrue($tx->payload['vnpay_reconciliation_required']);
        $receipt = array_values($tx->payload['vnpay_events'])[0];
        $this->assertSame('14567890', $receipt['gateway_transaction_id']);
        $this->assertTrue($receipt['signature_verified']);
        $this->assertSame(3, $product->refresh()->stock);
    }

    public function test_unverified_cancellation_is_blocked_and_later_ipn_settles_once(): void
    {
        [$user, $order, $tx, $product] = $this->orderContext();
        $query = $this->signed($tx);
        $this->actingAs($user)->post(route('order.cancel', $order))->assertSessionHasErrors('status');
        $this->assertSame('pending', $order->refresh()->status);
        $this->assertSame('pending', $tx->refresh()->payment_status);
        $this->assertSame(3, $product->refresh()->stock);
        $this->assertIpn($query, '00', 'Confirm Success');
        $this->assertIpn($query, '02', 'Order already confirmed');
        $this->assertSame(3, $product->refresh()->stock);
        $this->assertSame('paid', $order->refresh()->payment_status);
        $this->assertCount(1, $tx->refresh()->payload['vnpay_events']);
    }

    public function test_vnpay_settlement_preserves_paid_cancellation_guard_and_fulfillment_rules(): void
    {
        [$user, $order, $tx, $product] = $this->orderContext();
        $lifecycle = app(OrderLifecycleService::class);
        try {
            $lifecycle->transition($order, 'confirmed');
            $this->fail('Unpaid online orders cannot enter fulfillment.');
        } catch (RuntimeException) {
            $this->assertSame('pending', $order->refresh()->status);
        }
        $this->assertIpn($this->signed($tx), '00', 'Confirm Success');
        $this->actingAs($user)->post(route('order.cancel', $order))->assertSessionHasErrors('status');
        $lifecycle->transition($order, 'confirmed');
        $lifecycle->transition($order, 'processing');
        $lifecycle->transition($order, 'shipped');
        $lifecycle->transition($order, 'delivered');
        $this->assertSame('delivered', $order->refresh()->status);
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame(3, $product->refresh()->stock);
    }

    public function test_vnpay_refund_can_be_requested_but_cannot_use_internal_simulated_executor(): void
    {
        [$user, $order, $tx, $product] = $this->orderContext();
        $this->assertIpn($this->signed($tx), '00', 'Confirm Success');
        $order->update(['status' => 'delivered']);
        $service = app(PaymentService::class);
        $refund = $service->requestRefund($order, '210000.00', 'Refund request', $user->id);
        $service->approveRefund($refund, $user->id);
        try {
            $service->executeRefund($refund, $user->id);
            $this->fail('VNPay cannot report a simulated gateway refund as completed.');
        } catch (RuntimeException) {
            $this->assertSame(Refund::STATUS_APPROVED, $refund->refresh()->status);
            $this->assertSame('paid', $order->refresh()->payment_status);
            $this->assertSame('paid', $tx->refresh()->payment_status);
            $this->assertSame(3, $product->refresh()->stock);
        }
    }

    public static function terminalStates(): array
    {
        return [['cancelled', 'failed'], ['refunded', 'refunded']];
    }

    public function test_conflicting_external_id_is_not_overwritten(): void
    {
        [, $order, $tx] = $this->orderContext();
        $tx->update(['gateway_transaction_id' => '11111111']);
        $this->assertIpn($this->signed($tx), '99', 'Reconciliation required');
        $this->assertSame('11111111', $tx->refresh()->gateway_transaction_id);
        $this->assertSame('pending', $order->refresh()->payment_status);
    }

    public function test_external_id_cannot_be_reused_for_another_order(): void
    {
        [, , $first] = $this->orderContext();
        [, $secondOrder, $second] = $this->orderContext();
        $this->assertIpn($this->signed($first), '00', 'Confirm Success');
        $this->assertIpn($this->signed($second, ['vnp_TransactionNo' => '0014567890']), '99', 'Reconciliation required');
        $this->assertSame('pending', $secondOrder->refresh()->payment_status);
        $this->assertNull($second->refresh()->gateway_transaction_id);
    }

    public function test_existing_same_gateway_id_can_settle_and_replay_with_leading_zeros(): void
    {
        [, $order, $tx] = $this->orderContext();
        $tx->update(['gateway_transaction_id' => '14567890']);
        $this->assertIpn($this->signed($tx), '00', 'Confirm Success');
        $this->assertIpn($this->signed($tx, ['vnp_TransactionNo' => '0014567890']), '02', 'Order already confirmed');
        $this->assertSame('paid', $order->refresh()->payment_status);
        $this->assertSame('14567890', $tx->refresh()->gateway_transaction_id);
    }

    public function test_legacy_callback_cannot_settle_vnpay_even_with_legacy_signature(): void
    {
        [, $order, $tx] = $this->orderContext();
        $payload = ['order_number' => $order->order_number, 'transaction_id' => $tx->transaction_id,
            'gateway' => 'vnpay', 'payment_status' => 'paid', 'amount' => (string) $tx->amount];
        $payload['signature'] = app(PaymentService::class)->callbackSignature($payload);
        $this->postJson(route('payment.callback'), $payload)->assertUnprocessable()->assertJsonValidationErrors('gateway');
        $this->assertSame('pending', $tx->refresh()->payment_status);
        $this->assertSame('pending', $order->refresh()->payment_status);
    }

    public function test_browser_order_number_cannot_redirect_ipn_to_different_order(): void
    {
        [, $firstOrder, $first] = $this->orderContext();
        [, $otherOrder] = $this->orderContext();
        $query = $this->signed($first);
        $query['order_number'] = $otherOrder->order_number;
        $this->assertIpn($query, '00', 'Confirm Success');
        $this->assertSame('paid', $firstOrder->refresh()->payment_status);
        $this->assertSame('pending', $otherOrder->refresh()->payment_status);
    }

    public function test_invalid_signature_performs_no_payment_database_queries(): void
    {
        [, , $tx] = $this->orderContext();
        $query = $this->signed($tx);
        $query['vnp_SecureHash'] = str_repeat('f', 128);
        DB::enableQueryLog();
        $this->assertIpn($query, '97', 'Invalid signature');
        $queries = collect(DB::getQueryLog())->pluck('query')->implode(' ');
        DB::disableQueryLog();
        $this->assertStringNotContainsString('payment_transactions', $queries);
        $this->assertStringNotContainsString('orders', $queries);
    }

    public function test_new_route_methods_and_middleware_are_narrow(): void
    {
        $routes = app('router')->getRoutes();
        $initiate = $routes->getByName('vnpay.initiate');
        $this->assertSame(['POST'], $initiate->methods());
        $this->assertContains('auth', $initiate->gatherMiddleware());
        $this->assertContains('active', $initiate->gatherMiddleware());
        foreach (['vnpay.return', 'vnpay.ipn'] as $name) {
            $route = $routes->getByName($name);
            $this->assertContains('GET', $route->methods());
            $this->assertNotContains('auth', $route->gatherMiddleware());
            $this->assertSame([], $route->excludedMiddleware());
        }
    }

    public function test_ipn_database_failure_rolls_back_settlement_and_returns_retryable_contract(): void
    {
        [, $order, $tx] = $this->orderContext();
        PaymentTransaction::saving(function (PaymentTransaction $transaction) {
            if ($transaction->payment_status === 'paid') {
                throw new RuntimeException('fixture write failure');
            }
        });
        try {
            $this->assertIpn($this->signed($tx), '99', 'Unknown error');
        } finally {
            PaymentTransaction::flushEventListeners();
        }
        $this->assertSame('pending', $order->refresh()->payment_status);
        $this->assertSame('pending', $tx->refresh()->payment_status);
        $this->assertNull($tx->paid_at);
    }

    private function assertIpn(array $query, string $code, string $message): void
    {
        $this->get(route('vnpay.ipn').'?'.http_build_query($query))->assertOk()->assertExactJson(['RspCode' => $code, 'Message' => $message]);
    }

    private function returnUrl(array $query): string
    {
        return route('vnpay.return').'?'.http_build_query($query);
    }

    private function signed(PaymentTransaction $transaction, array $overrides = []): array
    {
        // Response fixtures intentionally omit optional vnp_Version as real Return/IPN can do.
        $query = array_filter(array_replace([
            'vnp_TmnCode' => 'TEST1234',
            'vnp_TxnRef' => $transaction->transaction_id,
            'vnp_TransactionNo' => '14567890',
            'vnp_Amount' => VnPayAmount::toGatewayAmount((string) $transaction->amount),
            'vnp_ResponseCode' => '00',
            'vnp_TransactionStatus' => '00',
            'vnp_PayDate' => now('Asia/Ho_Chi_Minh')->format('YmdHis'),
            'vnp_BankCode' => 'NCB',
        ], $overrides), fn ($value) => $value !== null);
        $query['vnp_SecureHash'] = app(VnPayGateway::class)->sign($query);

        return $query;
    }

    private function checkoutContext(): array
    {
        $user = User::factory()->create(['is_active' => true]);
        $shipping = ShippingMethod::create(['name' => 'Test shipping', 'code' => Str::random(12), 'base_fee' => '10000.00', 'is_active' => true]);
        $product = Product::create(['name' => 'Silver ring', 'slug' => Str::random(16), 'sku' => Str::random(16), 'price' => '100000.00', 'stock' => 5, 'is_active' => true]);
        $cart = Cart::create(['user_id' => $user->id]);
        $cart->items()->create(['product_id' => $product->id, 'quantity' => 2, 'unit_price' => '1.00']);

        return [$user, $shipping, $product, $cart];
    }

    private function checkoutPayload(ShippingMethod $shipping): array
    {
        return ['customer_name' => 'Customer', 'customer_phone' => '0900000000', 'province' => 'HCM', 'district' => 'Q1', 'ward' => 'P1', 'address_line' => '1 Test Street', 'shipping_method_id' => $shipping->id, 'payment_method' => 'vnpay', 'checkout_token' => (string) Str::uuid()];
    }

    private function orderContext(): array
    {
        $user = User::factory()->create(['is_active' => true]);
        $order = Order::create(['user_id' => $user->id, 'order_number' => 'SA-'.Str::random(16), 'status' => 'pending', 'payment_method' => 'vnpay', 'payment_status' => 'pending', 'customer_name' => 'Private Customer', 'customer_phone' => '0900000000', 'customer_email' => 'private@example.test', 'shipping_address' => 'Secret address', 'subtotal' => '210000.00', 'total_amount' => '210000.00']);
        $product = Product::create(['name' => 'Sold ring', 'slug' => Str::random(16), 'sku' => Str::random(16), 'price' => '105000.00', 'stock' => 3, 'is_active' => true]);
        OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'product_name' => $product->name, 'quantity' => 2, 'unit_price' => '105000.00', 'total_price' => '210000.00']);
        $transaction = app(PaymentService::class)->createOrGetPendingTransaction($order);

        return [$user, $order->fresh(), $transaction->fresh(), $product];
    }
}
