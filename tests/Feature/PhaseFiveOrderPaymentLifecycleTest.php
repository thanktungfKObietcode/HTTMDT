<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\Role;
use App\Models\ShippingMethod;
use App\Models\User;
use App\Services\OrderLifecycleService;
use App\Services\PaymentService;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PhaseFiveOrderPaymentLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_cannot_cancel_paid_order_or_restore_its_stock(): void
    {
        [$customer, $order, $transaction, $product] = $this->createOrderContext(
            OrderLifecycleService::STATUS_CONFIRMED,
            PaymentService::PAYMENT_PAID
        );

        $response = $this->actingAs($customer)
            ->from(route('order.show', $order))
            ->post(route('order.cancel', $order));

        $response->assertRedirect(route('order.show', $order));
        $response->assertSessionHasErrors('status');
        $this->assertSame(OrderLifecycleService::STATUS_CONFIRMED, $order->refresh()->status);
        $this->assertSame(PaymentService::PAYMENT_PAID, $order->payment_status);
        $this->assertSame(PaymentService::PAYMENT_PAID, $transaction->refresh()->payment_status);
        $this->assertSame(3, (int) $product->refresh()->stock);
        $this->assertDatabaseMissing('order_status_history', [
            'order_id' => $order->id,
            'status' => OrderLifecycleService::STATUS_CANCELLED,
        ]);
    }

    public function test_paid_callback_cannot_change_cancelled_order_or_pending_transaction(): void
    {
        [, $order, $transaction] = $this->createOrderContext(
            OrderLifecycleService::STATUS_CANCELLED,
            PaymentService::PAYMENT_PENDING
        );
        $payload = $this->callbackPayload($order, $transaction, PaymentService::PAYMENT_PAID);

        $this->post(route('payment.callback'), $payload)->assertStatus(422);

        $this->assertSame(OrderLifecycleService::STATUS_CANCELLED, $order->refresh()->status);
        $this->assertSame(PaymentService::PAYMENT_PENDING, $order->payment_status);
        $this->assertSame(PaymentService::PAYMENT_PENDING, $transaction->refresh()->payment_status);
        $this->assertDatabaseMissing('order_status_history', [
            'order_id' => $order->id,
            'status' => OrderLifecycleService::STATUS_CONFIRMED,
        ]);
    }

    public function test_stale_transition_cannot_overwrite_cancelled_order_after_inventory_restore(): void
    {
        [, $order, , $product] = $this->createOrderContext(
            OrderLifecycleService::STATUS_CONFIRMED,
            PaymentService::PAYMENT_PENDING
        );
        $staleOrder = $order->fresh();
        $service = app(OrderLifecycleService::class);

        $service->transition($order, OrderLifecycleService::STATUS_CANCELLED, 'Cancel first');

        try {
            $service->transition($staleOrder, OrderLifecycleService::STATUS_PROCESSING, 'Stale transition');
            $this->fail('A stale transition must re-read the cancelled order and be rejected.');
        } catch (\RuntimeException) {
            // Expected: persisted state, not the stale model, authorizes the transition.
        }

        $this->assertSame(OrderLifecycleService::STATUS_CANCELLED, $order->refresh()->status);
        $this->assertSame(5, (int) $product->refresh()->stock);
        $this->assertSame(1, $order->statusHistory()->where('status', OrderLifecycleService::STATUS_CANCELLED)->count());
        $this->assertSame(0, $order->statusHistory()->where('status', OrderLifecycleService::STATUS_PROCESSING)->count());
    }

    public function test_admin_cannot_cancel_paid_order_or_restore_stock(): void
    {
        [, $order, $transaction, $product] = $this->createOrderContext(
            OrderLifecycleService::STATUS_PROCESSING,
            PaymentService::PAYMENT_PAID
        );
        $admin = $this->createAdmin();

        $this->actingAs($admin)
            ->from(route('admin.orders.show', $order))
            ->post(route('admin.orders.status', $order), [
                'status' => OrderLifecycleService::STATUS_CANCELLED,
            ])
            ->assertRedirect(route('admin.orders.show', $order))
            ->assertSessionHasErrors('status');

        $this->assertSame(OrderLifecycleService::STATUS_PROCESSING, $order->refresh()->status);
        $this->assertSame(PaymentService::PAYMENT_PAID, $order->payment_status);
        $this->assertSame(PaymentService::PAYMENT_PAID, $transaction->refresh()->payment_status);
        $this->assertSame(3, (int) $product->refresh()->stock);
        $this->assertSame(0, $order->statusHistory()->where('status', OrderLifecycleService::STATUS_CANCELLED)->count());
    }

    public function test_unpaid_cancellation_closes_pending_attempt_and_restores_stock_once(): void
    {
        [$customer, $order, $transaction, $product] = $this->createOrderContext(
            OrderLifecycleService::STATUS_CONFIRMED,
            PaymentService::PAYMENT_PENDING
        );

        $this->actingAs($customer)->post(route('order.cancel', $order))->assertRedirect();
        $this->actingAs($customer)->post(route('order.cancel', $order))->assertRedirect();

        $this->assertSame(OrderLifecycleService::STATUS_CANCELLED, $order->refresh()->status);
        $this->assertSame(PaymentService::PAYMENT_FAILED, $order->payment_status);
        $this->assertSame(PaymentService::PAYMENT_FAILED, $transaction->refresh()->payment_status);
        $this->assertSame('order_cancelled', $transaction->payload['closed_reason'] ?? null);
        $this->assertSame(5, (int) $product->refresh()->stock);
        $this->assertSame(1, $order->statusHistory()->where('status', OrderLifecycleService::STATUS_CANCELLED)->count());
    }

    public function test_customer_cannot_cancel_processing_unpaid_order(): void
    {
        [$customer, $order, $transaction, $product] = $this->createOrderContext(
            OrderLifecycleService::STATUS_PROCESSING,
            PaymentService::PAYMENT_PENDING
        );

        $this->actingAs($customer)
            ->from(route('order.show', $order))
            ->post(route('order.cancel', $order))
            ->assertRedirect(route('order.show', $order))
            ->assertSessionHasErrors('status');

        $this->assertSame(OrderLifecycleService::STATUS_PROCESSING, $order->refresh()->status);
        $this->assertSame(PaymentService::PAYMENT_PENDING, $transaction->refresh()->payment_status);
        $this->assertSame(3, (int) $product->refresh()->stock);
        $this->assertSame(0, $order->statusHistory()->count());
    }

    public function test_cod_delivery_collects_payment_atomically_and_enables_refund_eligibility(): void
    {
        [$customer, $order, $transaction, $product] = $this->createOrderContext(
            OrderLifecycleService::STATUS_SHIPPED,
            PaymentService::PAYMENT_PENDING,
            PaymentService::PAYMENT_METHOD_COD
        );

        $this->actingAs($customer)
            ->post(route('order.delivered', $order))
            ->assertRedirect(route('order.show', $order))
            ->assertSessionHasNoErrors();

        $order->refresh();
        $transaction->refresh();
        $eligibility = app(PaymentService::class)->refundEligibility($order);

        $this->assertSame(OrderLifecycleService::STATUS_DELIVERED, $order->status);
        $this->assertSame(PaymentService::PAYMENT_PAID, $order->payment_status);
        $this->assertNotNull($order->paid_at);
        $this->assertNotNull($order->delivered_at);
        $this->assertSame(PaymentService::PAYMENT_PAID, $transaction->payment_status);
        $this->assertSame('delivery_confirmation', $transaction->payload['source'] ?? null);
        $this->assertTrue($eligibility['eligible']);
        $this->assertSame('200000.00', $eligibility['refundable_amount']);
        $this->assertSame(3, (int) $product->refresh()->stock);
        $this->assertSame(1, $order->statusHistory()->where('status', OrderLifecycleService::STATUS_DELIVERED)->count());
    }

    public function test_cod_delivery_rolls_back_when_no_matching_pending_transaction_exists(): void
    {
        [, $order, $transaction] = $this->createOrderContext(
            OrderLifecycleService::STATUS_SHIPPED,
            PaymentService::PAYMENT_PENDING,
            PaymentService::PAYMENT_METHOD_COD
        );
        $transaction->delete();

        try {
            app(OrderLifecycleService::class)->transition(
                $order,
                OrderLifecycleService::STATUS_DELIVERED,
                'Delivery without collection transaction'
            );
            $this->fail('COD delivery must fail when no matching pending transaction exists.');
        } catch (\RuntimeException) {
            $this->assertTrue(true);
        }

        $this->assertSame(OrderLifecycleService::STATUS_SHIPPED, $order->refresh()->status);
        $this->assertSame(PaymentService::PAYMENT_PENDING, $order->payment_status);
        $this->assertNull($order->delivered_at);
        $this->assertSame(0, $order->statusHistory()->count());
    }

    public function test_cod_delivery_rejects_inconsistent_hidden_paid_transaction(): void
    {
        [, $order, $transaction] = $this->createOrderContext(
            OrderLifecycleService::STATUS_SHIPPED,
            PaymentService::PAYMENT_PENDING,
            PaymentService::PAYMENT_METHOD_COD
        );
        $transaction->update(['payment_status' => PaymentService::PAYMENT_PAID]);

        try {
            app(OrderLifecycleService::class)->transition(
                $order,
                OrderLifecycleService::STATUS_DELIVERED,
                'Must not create a second paid state'
            );
            $this->fail('Inconsistent COD payment records must block delivery settlement.');
        } catch (\RuntimeException) {
            $this->assertTrue(true);
        }

        $this->assertSame(OrderLifecycleService::STATUS_SHIPPED, $order->refresh()->status);
        $this->assertSame(PaymentService::PAYMENT_PENDING, $order->payment_status);
        $this->assertSame(PaymentService::PAYMENT_PAID, $transaction->refresh()->payment_status);
        $this->assertSame(0, $order->statusHistory()->count());
    }

    public function test_cod_can_be_cancelled_safely_before_collection(): void
    {
        [$customer, $order, $transaction, $product] = $this->createOrderContext(
            OrderLifecycleService::STATUS_CONFIRMED,
            PaymentService::PAYMENT_PENDING,
            PaymentService::PAYMENT_METHOD_COD
        );

        $this->actingAs($customer)->post(route('order.cancel', $order))->assertRedirect();

        $this->assertSame(OrderLifecycleService::STATUS_CANCELLED, $order->refresh()->status);
        $this->assertSame(PaymentService::PAYMENT_FAILED, $order->payment_status);
        $this->assertSame(PaymentService::PAYMENT_FAILED, $transaction->refresh()->payment_status);
        $this->assertSame(5, (int) $product->refresh()->stock);
    }

    public function test_unpaid_or_failed_simulated_online_order_cannot_enter_fulfillment(): void
    {
        [, $order, $transaction] = $this->createOrderContext(
            OrderLifecycleService::STATUS_PENDING,
            PaymentService::PAYMENT_PENDING
        );
        $service = app(OrderLifecycleService::class);

        foreach ([PaymentService::PAYMENT_PENDING, PaymentService::PAYMENT_FAILED] as $paymentStatus) {
            $order->update(['payment_status' => $paymentStatus]);
            $transaction->update(['payment_status' => $paymentStatus]);

            try {
                $service->transition($order, OrderLifecycleService::STATUS_CONFIRMED, 'Unsafe fulfillment');
                $this->fail('An unpaid simulated-online order must not enter fulfillment.');
            } catch (\RuntimeException) {
                $this->assertTrue(true);
            }

            $this->assertSame(OrderLifecycleService::STATUS_PENDING, $order->refresh()->status);
        }

        $this->assertSame(0, $order->statusHistory()->count());
    }

    public function test_paid_simulated_online_order_can_progress_through_fulfillment(): void
    {
        [, $order] = $this->createOrderContext(
            OrderLifecycleService::STATUS_PENDING,
            PaymentService::PAYMENT_PAID
        );
        $service = app(OrderLifecycleService::class);

        foreach ([
            OrderLifecycleService::STATUS_CONFIRMED,
            OrderLifecycleService::STATUS_PROCESSING,
            OrderLifecycleService::STATUS_SHIPPED,
            OrderLifecycleService::STATUS_DELIVERED,
        ] as $status) {
            $service->transition($order, $status, 'Valid paid fulfillment');
        }

        $this->assertSame(OrderLifecycleService::STATUS_DELIVERED, $order->refresh()->status);
        $this->assertSame(PaymentService::PAYMENT_PAID, $order->payment_status);
        $this->assertNotNull($order->shipped_at);
        $this->assertNotNull($order->delivered_at);
        $this->assertSame(4, $order->statusHistory()->count());
    }

    public function test_callback_rejects_transaction_amount_that_differs_from_order(): void
    {
        [, $order, $transaction] = $this->createOrderContext(
            OrderLifecycleService::STATUS_PENDING,
            PaymentService::PAYMENT_PENDING
        );
        $transaction->update(['amount' => '199999.99']);
        $payload = $this->callbackPayload(
            $order,
            $transaction,
            PaymentService::PAYMENT_PAID,
            (string) $order->total_amount
        );

        $this->post(route('payment.callback'), $payload)->assertStatus(422);

        $this->assertSame(PaymentService::PAYMENT_PENDING, $order->refresh()->payment_status);
        $this->assertSame(PaymentService::PAYMENT_PENDING, $transaction->refresh()->payment_status);
        $this->assertSame(OrderLifecycleService::STATUS_PENDING, $order->status);
    }

    public function test_callback_rejects_gateway_or_order_method_mismatch(): void
    {
        [, $order, $transaction] = $this->createOrderContext(
            OrderLifecycleService::STATUS_PENDING,
            PaymentService::PAYMENT_PENDING
        );
        $order->update(['payment_method' => PaymentService::PAYMENT_METHOD_COD]);
        $payload = $this->callbackPayload($order, $transaction, PaymentService::PAYMENT_PAID);

        $this->post(route('payment.callback'), $payload)->assertStatus(422);

        $this->assertSame(PaymentService::PAYMENT_PENDING, $order->refresh()->payment_status);
        $this->assertSame(PaymentService::PAYMENT_PENDING, $transaction->refresh()->payment_status);
    }

    public function test_success_then_failure_callback_cannot_downgrade_paid_state(): void
    {
        [, $order, $transaction] = $this->createOrderContext(
            OrderLifecycleService::STATUS_PENDING,
            PaymentService::PAYMENT_PENDING
        );

        $this->post(
            route('payment.callback'),
            $this->callbackPayload($order, $transaction, PaymentService::PAYMENT_PAID)
        )->assertOk();
        $this->post(
            route('payment.callback'),
            $this->callbackPayload($order, $transaction, PaymentService::PAYMENT_FAILED)
        )->assertStatus(422);

        $this->assertSame(PaymentService::PAYMENT_PAID, $order->refresh()->payment_status);
        $this->assertSame(OrderLifecycleService::STATUS_CONFIRMED, $order->status);
        $this->assertSame(PaymentService::PAYMENT_PAID, $transaction->refresh()->payment_status);
        $this->assertSame(1, $order->statusHistory()->where('status', OrderLifecycleService::STATUS_CONFIRMED)->count());
    }

    public function test_callback_transaction_must_belong_to_signed_order(): void
    {
        [, $firstOrder, $firstTransaction] = $this->createOrderContext(
            OrderLifecycleService::STATUS_PENDING,
            PaymentService::PAYMENT_PENDING
        );
        [, $secondOrder, $secondTransaction] = $this->createOrderContext(
            OrderLifecycleService::STATUS_PENDING,
            PaymentService::PAYMENT_PENDING
        );
        $payload = $this->callbackPayload($firstOrder, $secondTransaction, PaymentService::PAYMENT_PAID);

        $this->post(route('payment.callback'), $payload)->assertStatus(422);

        $this->assertSame(PaymentService::PAYMENT_PENDING, $firstOrder->refresh()->payment_status);
        $this->assertSame(PaymentService::PAYMENT_PENDING, $firstTransaction->refresh()->payment_status);
        $this->assertSame(PaymentService::PAYMENT_PENDING, $secondOrder->refresh()->payment_status);
        $this->assertSame(PaymentService::PAYMENT_PENDING, $secondTransaction->refresh()->payment_status);
    }

    public function test_success_callback_closes_other_pending_attempts_and_blocks_them_from_becoming_paid(): void
    {
        [, $order, $transaction] = $this->createOrderContext(
            OrderLifecycleService::STATUS_PENDING,
            PaymentService::PAYMENT_PENDING
        );
        $otherAttempt = PaymentTransaction::create([
            'order_id' => $order->id,
            'gateway' => PaymentService::PAYMENT_METHOD_SIMULATED_ONLINE,
            'transaction_id' => 'TXN-PHASE5-OTHER-'.uniqid(),
            'payment_status' => PaymentService::PAYMENT_PENDING,
            'amount' => (string) $order->total_amount,
        ]);

        $this->post(
            route('payment.callback'),
            $this->callbackPayload($order, $transaction, PaymentService::PAYMENT_PAID)
        )->assertOk();

        $this->assertSame(PaymentService::PAYMENT_FAILED, $otherAttempt->refresh()->payment_status);
        $this->assertSame('another_attempt_settled', $otherAttempt->payload['closed_reason'] ?? null);

        $this->post(
            route('payment.callback'),
            $this->callbackPayload($order, $otherAttempt, PaymentService::PAYMENT_PAID)
        )->assertStatus(422);

        $this->assertSame(PaymentService::PAYMENT_PAID, $transaction->refresh()->payment_status);
        $this->assertSame(PaymentService::PAYMENT_FAILED, $otherAttempt->refresh()->payment_status);
    }

    public function test_create_or_get_pending_transaction_is_locked_deterministic_and_consolidates_duplicates(): void
    {
        [, $order, $transaction] = $this->createOrderContext(
            OrderLifecycleService::STATUS_PENDING,
            PaymentService::PAYMENT_PENDING
        );
        $duplicate = PaymentTransaction::create([
            'order_id' => $order->id,
            'gateway' => PaymentService::PAYMENT_METHOD_SIMULATED_ONLINE,
            'transaction_id' => 'TXN-PHASE5-DUPLICATE-'.uniqid(),
            'payment_status' => PaymentService::PAYMENT_PENDING,
            'amount' => (string) $order->total_amount,
        ]);

        $resolved = app(PaymentService::class)->createOrGetPendingTransaction($order);

        $this->assertSame($transaction->id, $resolved->id);
        $this->assertSame(PaymentService::PAYMENT_PENDING, $transaction->refresh()->payment_status);
        $this->assertSame(PaymentService::PAYMENT_FAILED, $duplicate->refresh()->payment_status);
        $this->assertSame('duplicate_pending_attempt', $duplicate->payload['closed_reason'] ?? null);
        $this->assertSame(1, $order->paymentTransactions()->where('payment_status', PaymentService::PAYMENT_PENDING)->count());
    }

    public function test_terminal_order_does_not_create_or_return_pending_transaction(): void
    {
        [, $order, $transaction] = $this->createOrderContext(
            OrderLifecycleService::STATUS_CANCELLED,
            PaymentService::PAYMENT_PENDING
        );
        $transaction->delete();

        try {
            app(PaymentService::class)->createOrGetPendingTransaction($order);
            $this->fail('A cancelled order must not create a new payment attempt.');
        } catch (\RuntimeException) {
            $this->assertTrue(true);
        }

        $this->assertSame(0, PaymentTransaction::where('order_id', $order->id)->count());
    }

    public function test_same_order_transition_is_an_idempotent_noop_without_history(): void
    {
        [, $order] = $this->createOrderContext(
            OrderLifecycleService::STATUS_CONFIRMED,
            PaymentService::PAYMENT_PENDING,
            PaymentService::PAYMENT_METHOD_COD
        );

        app(OrderLifecycleService::class)->transition(
            $order,
            OrderLifecycleService::STATUS_CONFIRMED,
            'Must not create history'
        );

        $this->assertSame(OrderLifecycleService::STATUS_CONFIRMED, $order->refresh()->status);
        $this->assertSame(0, $order->statusHistory()->count());
    }

    public function test_history_write_failure_rolls_back_order_transition(): void
    {
        [, $order] = $this->createOrderContext(
            OrderLifecycleService::STATUS_PENDING,
            PaymentService::PAYMENT_PENDING,
            PaymentService::PAYMENT_METHOD_COD
        );

        try {
            app(OrderLifecycleService::class)->transition(
                $order,
                OrderLifecycleService::STATUS_CONFIRMED,
                'History must be atomic',
                999999999
            );
            $this->fail('A failed history write must roll back the order status update.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }

        $this->assertSame(OrderLifecycleService::STATUS_PENDING, $order->refresh()->status);
        $this->assertSame(0, $order->statusHistory()->count());
    }

    public function test_checkout_accepts_cod_only_and_creates_matching_pending_transaction(): void
    {
        [$customer, $shipping, $product, $cart] = $this->createCheckoutContext();

        $this->actingAs($customer)
            ->post(route('checkout.store'), $this->checkoutPayload($shipping->id, PaymentService::PAYMENT_METHOD_COD))
            ->assertRedirect();

        $order = Order::query()->sole();
        $transaction = PaymentTransaction::query()->sole();

        $this->assertSame(PaymentService::PAYMENT_METHOD_COD, $order->payment_method);
        $this->assertSame(PaymentService::PAYMENT_PENDING, $order->payment_status);
        $this->assertSame(PaymentService::PAYMENT_METHOD_COD, $transaction->gateway);
        $this->assertSame(PaymentService::PAYMENT_PENDING, $transaction->payment_status);
        $this->assertSame((string) $order->total_amount, (string) $transaction->amount);
        $this->assertSame(3, (int) $product->refresh()->stock);
        $this->assertSame(0, $cart->items()->count());
    }

    public function test_checkout_rejects_arbitrary_payment_method_without_mutating_cart_or_inventory(): void
    {
        [$customer, $shipping, $product, $cart] = $this->createCheckoutContext();

        $this->actingAs($customer)
            ->post(route('checkout.store'), $this->checkoutPayload($shipping->id, 'vnpay-unconfigured'))
            ->assertSessionHasErrors('payment_method');

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, PaymentTransaction::query()->count());
        $this->assertSame(5, (int) $product->refresh()->stock);
        $this->assertSame(1, $cart->items()->count());
    }

    public function test_checkout_creates_a_pending_vnpay_attempt_and_redirects_to_the_sandbox(): void
    {
        [$customer, $shipping, $product, $cart] = $this->createCheckoutContext();
        config()->set([
            'vnpay.enabled' => true,
            'vnpay.tmn_code' => 'TEST1234',
            'vnpay.hash_secret' => 'phase-5-fixture-only',
            'vnpay.payment_url' => 'https://sandbox.vnpayment.vn/paymentv2/vpcpay.html',
            'vnpay.return_url' => 'https://shop.example.test/thanh-toan/vnpay/return',
            'vnpay.ipn_url' => 'https://shop.example.test/thanh-toan/vnpay/ipn',
            'vnpay.version' => '2.1.0',
            'vnpay.locale' => 'vn',
            'vnpay.currency' => 'VND',
            'vnpay.order_type' => 'other',
            'vnpay.timezone' => 'Asia/Ho_Chi_Minh',
        ]);

        $response = $this->actingAs($customer)
            ->post(route('checkout.store'), $this->checkoutPayload($shipping->id, PaymentService::PAYMENT_METHOD_VNPAY));
        $response->assertRedirect();

        $order = Order::query()->sole();
        $transaction = PaymentTransaction::query()->sole();
        $this->assertSame(PaymentService::PAYMENT_METHOD_VNPAY, $order->payment_method);
        $this->assertSame(PaymentService::PAYMENT_PENDING, $order->payment_status);
        $this->assertSame(PaymentService::PAYMENT_METHOD_VNPAY, $transaction->gateway);
        $this->assertSame(PaymentService::PAYMENT_PENDING, $transaction->payment_status);
        $this->assertSame('sandbox.vnpayment.vn', parse_url((string) $response->headers->get('Location'), PHP_URL_HOST));
        $this->assertSame(3, (int) $product->refresh()->stock);
        $this->assertSame(0, $cart->items()->count());
    }

    private function createAdmin(): User
    {
        $admin = User::factory()->create(['is_active' => true]);
        $role = Role::create(['name' => 'admin', 'guard_name' => 'web']);
        $admin->roles()->attach($role->id);

        return $admin;
    }

    /** @return array{0: User, 1: ShippingMethod, 2: Product, 3: Cart} */
    private function createCheckoutContext(): array
    {
        $customer = User::factory()->create(['is_active' => true]);
        $shipping = ShippingMethod::create([
            'name' => 'Phase 5 shipping',
            'code' => 'phase-5-'.uniqid(),
            'base_fee' => '10000.00',
            'fee_per_km' => '0.00',
            'estimated_days_min' => 1,
            'estimated_days_max' => 2,
            'is_active' => true,
        ]);
        $product = Product::create([
            'name' => 'Phase 5 checkout product',
            'slug' => 'phase-5-checkout-product-'.uniqid(),
            'sku' => 'PHASE5-CHECKOUT-'.uniqid(),
            'price' => '100000.00',
            'stock' => 5,
            'is_active' => true,
        ]);
        $cart = Cart::create([
            'user_id' => $customer->id,
            'session_id' => null,
        ]);
        $cart->items()->create([
            'product_id' => $product->id,
            'quantity' => 2,
            'unit_price' => '1.00',
        ]);

        return [$customer, $shipping, $product, $cart];
    }

    /** @return array<string, mixed> */
    private function checkoutPayload(int $shippingMethodId, string $paymentMethod): array
    {
        return [
            'customer_name' => 'Phase 5 Customer',
            'customer_phone' => '0900000000',
            'customer_email' => 'phase5@example.com',
            'province' => 'Ha Noi',
            'district' => 'Hoan Kiem',
            'ward' => 'Trang Tien',
            'address_line' => '1 Phase 5 Street',
            'shipping_method_id' => $shippingMethodId,
            'payment_method' => $paymentMethod,
            'checkout_token' => (string) Str::uuid(),
        ];
    }

    /** @return array{0: User, 1: Order, 2: PaymentTransaction, 3: Product} */
    private function createOrderContext(
        string $orderStatus,
        string $paymentStatus,
        string $paymentMethod = PaymentService::PAYMENT_METHOD_SIMULATED_ONLINE
    ): array
    {
        $customer = User::factory()->create(['is_active' => true]);
        $product = Product::create([
            'name' => 'Phase 5 inventory product',
            'slug' => 'phase-5-inventory-product-'.uniqid(),
            'sku' => 'PHASE5-'.uniqid(),
            'price' => '100000.00',
            'stock' => 3,
            'is_active' => true,
        ]);
        $order = Order::create([
            'user_id' => $customer->id,
            'order_number' => 'SA-PHASE5-'.uniqid(),
            'status' => $orderStatus,
            'payment_method' => $paymentMethod,
            'payment_status' => $paymentStatus,
            'customer_name' => $customer->name,
            'customer_phone' => '0900000000',
            'customer_email' => $customer->email,
            'shipping_address' => 'Phase 5 address',
            'subtotal' => '200000.00',
            'shipping_fee' => '0.00',
            'discount_amount' => '0.00',
            'total_amount' => '200000.00',
            'paid_at' => $paymentStatus === PaymentService::PAYMENT_PAID ? now() : null,
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'sku' => $product->sku,
            'quantity' => 2,
            'unit_price' => '100000.00',
            'total_price' => '200000.00',
        ]);
        $transaction = PaymentTransaction::create([
            'order_id' => $order->id,
            'gateway' => $paymentMethod,
            'transaction_id' => 'TXN-PHASE5-'.uniqid(),
            'payment_status' => $paymentStatus,
            'amount' => '200000.00',
        ]);

        return [$customer, $order, $transaction, $product];
    }

    /** @return array<string, mixed> */
    private function callbackPayload(
        Order $order,
        PaymentTransaction $transaction,
        string $status,
        ?string $amount = null,
        ?string $gateway = null
    ): array
    {
        $amount ??= (string) $transaction->amount;
        $gateway ??= (string) $transaction->gateway;
        $plain = implode('|', [
            $order->order_number,
            $transaction->transaction_id,
            strtolower($gateway),
            strtolower($status),
            Money::fromMinorUnits(Money::toMinorUnits($amount)),
        ]);

        return [
            'order_number' => $order->order_number,
            'transaction_id' => $transaction->transaction_id,
            'payment_status' => $status,
            'amount' => $amount,
            'gateway' => $gateway,
            'signature' => hash_hmac('sha256', $plain, (string) config('app.key')),
        ];
    }
}
