<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Cart;
use App\Models\Product;
use App\Models\ShippingMethod;
use App\Models\PaymentTransaction;
use App\Models\PaymentGatewayEvent;
use App\Models\Refund;
use App\Models\User;
use App\Payments\MoMoGateway;
use App\Payments\PaymentEventOutcome;
use App\Services\MoMoPaymentService;
use App\Services\MoMoReconciliationService;
use App\Services\MoMoRefundService;
use App\Services\PaymentDeploymentPreflight;
use App\Services\OrderLifecycleService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class MoMoPaymentTest extends TestCase
{
    use DatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (config('app.env') !== 'testing' || config('database.default') !== 'sqlite'
            || config('database.connections.sqlite.database') !== ':memory:') {
            throw new RuntimeException('MoMo tests require isolated SQLite memory.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config()->set([
            'app.url' => 'https://shop.example.test',
            'momo.enabled' => true,
            'momo.partner_code' => 'TESTPARTNER',
            'momo.access_key' => 'fixture-access',
            'momo.secret_key' => 'fixture-secret-only',
            'momo.redirect_url' => 'https://shop.example.test/thanh-toan/momo/return',
            'momo.ipn_url' => 'https://shop.example.test/thanh-toan/momo/ipn',
        ]);
    }

    public function test_create_uses_signed_authoritative_amount_and_http_outside_locks(): void
    {
        [$user, $order, $tx] = $this->context();
        Http::fake(function ($request) use ($tx) {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame(config('momo.create_url'), $request->url());
            $this->assertSame(210000, $request['amount']);
            $this->assertSame($tx->transaction_id, $request['orderId']);
            $this->assertSame($tx->payload['momo_request_id'], $request['requestId']);
            $this->assertSame($this->sign($request->data(), ['accessKey', 'amount', 'extraData',
                'ipnUrl', 'orderId', 'orderInfo', 'partnerCode', 'redirectUrl', 'requestId', 'requestType']),
                $request['signature']);
            $response = ['partnerCode' => $request['partnerCode'], 'orderId' => $request['orderId'],
                'requestId' => $request['requestId'], 'amount' => $request['amount'], 'resultCode' => 0,
                'message' => 'Successful.', 'responseTime' => 1789990000000,
                'payUrl' => 'https://test-payment.momo.vn/v2/gateway/pay?t=fixture'];
            $response['signature'] = $this->sign($response, ['accessKey', 'amount', 'message', 'orderId',
                'partnerCode', 'payUrl', 'requestId', 'responseTime', 'resultCode']);
            return Http::response($response);
        });
        $url = app(MoMoPaymentService::class)->paymentUrl($order, $user->id);
        $this->assertSame('test-payment.momo.vn', parse_url($url, PHP_URL_HOST));
        $this->assertSame('pending', $tx->refresh()->payment_status);
    }

    public function test_checkout_offers_momo_and_creates_pending_attempt_before_redirect(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $shipping = ShippingMethod::create(['name' => 'Fixture shipping', 'code' => Str::random(12),
            'base_fee' => '10000.00', 'is_active' => true]);
        $product = Product::create(['name' => 'Fixture ring', 'slug' => Str::random(16),
            'sku' => Str::random(16), 'price' => '100000.00', 'stock' => 5, 'is_active' => true]);
        $cart = Cart::create(['user_id' => $user->id]);
        $cart->items()->create(['product_id' => $product->id, 'quantity' => 2, 'unit_price' => '1.00']);
        Http::fake(function ($request) {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame(210000, $request['amount']);
            $response = ['partnerCode' => $request['partnerCode'], 'orderId' => $request['orderId'],
                'requestId' => $request['requestId'], 'amount' => $request['amount'], 'resultCode' => 0,
                'message' => 'Successful.', 'responseTime' => 1789990000000,
                'payUrl' => 'https://test-payment.momo.vn/v2/gateway/pay?t=fixture'];
            $response['signature'] = $this->sign($response, ['accessKey', 'amount', 'message', 'orderId',
                'partnerCode', 'payUrl', 'requestId', 'responseTime', 'resultCode']);
            return Http::response($response);
        });
        $this->actingAs($user)->get(route('checkout.index'))->assertSee('value="momo"', false);
        $response = $this->post(route('checkout.store'), [
            'customer_name' => 'Fixture', 'customer_phone' => '0900000000',
            'province' => 'HCM', 'district' => 'Q1', 'ward' => 'P1', 'address_line' => 'Fixture',
            'shipping_method_id' => $shipping->id, 'payment_method' => 'momo',
            'checkout_token' => (string) Str::uuid(),
        ])->assertRedirect();
        $this->assertSame('test-payment.momo.vn', parse_url($response->headers->get('Location'), PHP_URL_HOST));
        $this->assertSame('momo', Order::firstOrFail()->payment_method);
        $this->assertSame('pending', PaymentTransaction::firstOrFail()->payment_status);
        $this->assertSame(3, $product->refresh()->stock);
    }

    public function test_signed_ipn_settles_once_and_return_is_read_only(): void
    {
        [, $order, $tx] = $this->context();
        $payload = $this->ipn($tx);
        $this->assertSame($tx->transaction_id, app(MoMoGateway::class)->verifyResult($payload, 'ipn')->merchantReference);
        $this->get(route('momo.return', $payload))->assertOk();
        $this->assertSame('pending', $order->refresh()->payment_status);
        $this->postJson(route('momo.ipn'), $payload)->assertNoContent();
        $this->postJson(route('momo.ipn'), $payload)->assertNoContent();
        $this->assertSame('paid', $order->refresh()->payment_status);
        $this->assertSame('987654321', $tx->refresh()->gateway_transaction_id);
        $this->assertSame(1, $order->paymentTransactions()->where('payment_status', 'paid')->count());
        $this->get(route('momo.return', $payload))->assertOk();
        $this->assertSame('paid', $order->refresh()->payment_status);
    }

    public function test_bad_signature_amount_and_conflicting_transaction_fail_closed(): void
    {
        [, $order, $tx] = $this->context();
        $bad = $this->ipn($tx);
        $bad['signature'] = str_repeat('0', 64);
        $this->get(route('momo.return', $bad))->assertOk();
        $this->assertSame('pending', $order->refresh()->payment_status);
        $this->postJson(route('momo.ipn'), $bad)->assertStatus(400);
        $wrongAmount = $this->ipn($tx, ['amount' => 9999]);
        $this->postJson(route('momo.ipn'), $wrongAmount)->assertStatus(409);
        $this->assertSame('pending', $order->refresh()->payment_status);
        $this->postJson(route('momo.ipn'), $this->ipn($tx))->assertNoContent();
        $this->postJson(route('momo.ipn'), $this->ipn($tx, ['transId' => 111222333]))->assertStatus(409);
        $this->assertSame('987654321', $tx->refresh()->gateway_transaction_id);
    }

    public function test_query_reconciles_success_but_unknown_and_timeout_do_not_settle(): void
    {
        [, $order, $tx] = $this->context();
        Http::fake(function ($request) use ($tx) {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame(config('momo.query_url'), $request->url());
            $this->assertSame($this->sign($request->data(), ['accessKey', 'orderId', 'partnerCode', 'requestId']),
                $request['signature']);
            return Http::response(['partnerCode' => config('momo.partner_code'),
                'orderId' => $tx->transaction_id, 'requestId' => $request['requestId'],
                'amount' => 210000, 'transId' => 987654321, 'resultCode' => 0,
                'responseTime' => 1789990000000]);
        });
        $this->assertSame(PaymentEventOutcome::Processed, app(MoMoReconciliationService::class)->reconcile($tx));
        $this->assertSame('paid', $order->refresh()->payment_status);
    }

    public function test_momo_pending_cancellation_does_not_restore_inventory(): void
    {
        [, $order] = $this->context();
        $this->expectException(RuntimeException::class);
        app(OrderLifecycleService::class)->transition($order, 'cancelled');
    }

    public function test_create_failure_keeps_the_same_pending_attempt_for_safe_retry(): void
    {
        [$user, $order, $tx] = $this->context();
        Http::fake(fn () => Http::response(['resultCode' => 1000], 200));
        try {
            app(MoMoPaymentService::class)->paymentUrl($order, $user->id);
            $this->fail('Malformed create response must fail closed.');
        } catch (RuntimeException) {
            $this->assertSame('pending', $tx->refresh()->payment_status);
            $this->assertSame('pending', $order->refresh()->payment_status);
            $this->assertDatabaseCount('payment_transactions', 1);
        }
        $this->assertFalse(app(MoMoGateway::class)->payUrlAllowed('https://evil.example.test/v2/gateway/pay'));
        $this->assertFalse(app(MoMoGateway::class)->payUrlAllowed('http://test-payment.momo.vn/v2/gateway/pay'));
    }

    public function test_query_unknown_or_unavailable_does_not_settle(): void
    {
        [, $order, $tx] = $this->context();
        $calls = 0;
        Http::fake(function ($request) use ($tx, &$calls) {
            $calls++;
            return $calls === 1 ? Http::response(['partnerCode' => config('momo.partner_code'),
                'orderId' => $tx->transaction_id, 'requestId' => $request['requestId'],
                'amount' => 210000, 'transId' => 0, 'resultCode' => 1000,
                'responseTime' => 1789990000000]) : Http::response([], 503);
        });
        $this->assertSame(PaymentEventOutcome::ReconciliationRequired,
            app(MoMoReconciliationService::class)->reconcile($tx));
        $this->assertSame('pending', $order->refresh()->payment_status);
        $this->assertSame(PaymentEventOutcome::TemporaryFailure,
            app(MoMoReconciliationService::class)->reconcile($tx));
        $this->assertSame('pending', $order->refresh()->payment_status);
    }

    public function test_refund_unknown_stays_processing_until_verified_query(): void
    {
        [$user, $order, $tx] = $this->context();
        $this->postJson(route('momo.ipn'), $this->ipn($tx))->assertNoContent();
        $order->update(['status' => 'delivered']);
        $refund = app(PaymentService::class)->requestRefund($order->fresh(), '210000.00', 'fixture', $user->id);
        app(PaymentService::class)->approveRefund($refund, $user->id);
        $calls = 0;
        Http::fake(function ($request) use ($refund, &$calls) {
            $calls++;
            if ($calls <= 2) {
                return Http::response([], 503);
            }
            return Http::response(['partnerCode' => config('momo.partner_code'),
                'requestId' => $request['requestId'], 'resultCode' => 0,
                'refundTrans' => [['orderId' => 'MMREF'.$refund->id, 'amount' => 210000,
                    'resultCode' => 0, 'transId' => 777888999]]]);
        });
        $this->assertSame('processing', app(MoMoRefundService::class)->execute($refund, $user->id)->status);
        $this->assertSame('processing', app(MoMoRefundService::class)->execute($refund, $user->id)->status);
        $this->assertSame('paid', $order->refresh()->payment_status);
        $this->assertDatabaseCount('refunds', 1);
        Http::assertSentCount(2); // One refund POST, then refund/query; never two refund POSTs.
        $this->assertSame('completed', app(MoMoRefundService::class)->query($refund, $user->id)->status);
        $this->assertSame('refunded', $order->refresh()->payment_status);
    }

    public function test_refund_success_and_duplicate_do_not_execute_twice(): void
    {
        [$user, $order, $tx] = $this->context();
        $this->postJson(route('momo.ipn'), $this->ipn($tx))->assertNoContent();
        $order->update(['status' => 'delivered']);
        $refund = app(PaymentService::class)->requestRefund($order->fresh(), '100000.00', 'fixture', $user->id);
        app(PaymentService::class)->approveRefund($refund, $user->id);
        Http::fake(function ($request) use ($refund) {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame(config('momo.refund_url'), $request->url());
            $this->assertSame($this->sign($request->data(), ['accessKey', 'amount', 'description',
                'orderId', 'partnerCode', 'requestId', 'transId']), $request['signature']);
            return Http::response(['partnerCode' => config('momo.partner_code'),
                'orderId' => 'MMREF'.$refund->id, 'requestId' => 'MMREQ'.$refund->id,
                'amount' => 100000, 'transId' => 444555666, 'resultCode' => 0]);
        });
        $this->assertSame('completed', app(MoMoRefundService::class)->execute($refund, $user->id)->status);
        $this->assertSame('completed', app(MoMoRefundService::class)->execute($refund, $user->id)->status);
        Http::assertSentCount(1);
        $this->assertSame('444555666', PaymentGatewayEvent::where('event_type', 'refund_settled')->firstOrFail()->metadata['refund_trans_id']);
        $this->assertSame('paid', $order->refresh()->payment_status); // Partial refund.
    }

    public function test_preflight_fails_closed_for_unsafe_momo_endpoint_without_network(): void
    {
        config()->set('vnpay.enabled', false);
        $good = collect(app(PaymentDeploymentPreflight::class)->run()['checks'])->firstWhere('id', 'momo_configuration');
        $this->assertSame('pass', $good['severity']);
        config()->set('momo.query_url', 'https://other.example.test/v2/gateway/api/query');
        $bad = collect(app(PaymentDeploymentPreflight::class)->run()['checks'])->firstWhere('id', 'momo_configuration');
        $this->assertSame('fail', $bad['severity']);
        Http::assertNothingSent();
    }

    private function context(): array
    {
        $user = User::factory()->create(['is_active' => true]);
        $order = Order::create(['user_id' => $user->id, 'order_number' => 'SA-'.Str::random(16),
            'status' => 'pending', 'payment_method' => 'momo', 'payment_status' => 'pending',
            'customer_name' => 'Fixture', 'customer_phone' => '0000000000',
            'shipping_address' => 'Fixture', 'subtotal' => '210000.00', 'total_amount' => '210000.00']);
        $tx = app(PaymentService::class)->createOrGetPendingTransaction($order);
        return [$user, $order, $tx];
    }

    private function ipn(PaymentTransaction $tx, array $overrides = []): array
    {
        $data = array_merge(['partnerCode' => config('momo.partner_code'), 'orderId' => $tx->transaction_id,
            'requestId' => $tx->payload['momo_request_id'], 'amount' => 210000,
            'orderInfo' => 'Silver Atelier '.$tx->transaction_id, 'orderType' => 'momo_wallet',
            'extraData' => '', 'message' => 'Successful.', 'payType' => 'qr',
            'responseTime' => 1789990000000, 'resultCode' => 0, 'transId' => 987654321], $overrides);
        $data['signature'] = $this->sign($data, ['accessKey', 'amount', 'extraData', 'message',
            'orderId', 'orderInfo', 'orderType', 'partnerCode', 'payType', 'requestId',
            'responseTime', 'resultCode', 'transId']);
        return $data;
    }

    private function sign(array $data, array $keys): string
    {
        return hash_hmac('sha256', implode('&', array_map(
            fn ($key) => $key.'='.($key === 'accessKey' ? config('momo.access_key') : $data[$key]), $keys
        )), config('momo.secret_key'));
    }
}
