<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Material;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\ShippingMethod;
use App\Models\User;
use App\Services\OrderLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentOrderLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_transaction_is_created_from_checkout_amount_server_side(): void
    {
        [$user, $shipping, $product] = $this->baseData();
        $cart = $this->createCart($user, $product, 2, 90000);

        $this->actingAs($user)->post(route('checkout.store'), $this->checkoutPayload($shipping->id));

        $order = Order::query()->firstOrFail();
        $transaction = PaymentTransaction::query()->firstOrFail();

        $this->assertEquals((float) $order->total_amount, (float) $transaction->amount);
        $this->assertEquals('pending', $transaction->payment_status);
        $this->assertEquals($order->id, $transaction->order_id);
        $this->assertDatabaseMissing('cart_items', ['cart_id' => $cart->id]);
    }

    public function test_payment_success_callback_updates_order_and_transaction(): void
    {
        [$user, $order, $transaction] = $this->createOrderWithPendingTransaction();

        $payload = $this->callbackPayload($order->order_number, $transaction->transaction_id, 'paid', (float) $order->total_amount);

        $response = $this->post(route('payment.callback'), $payload);

        $response->assertOk();
        $this->assertDatabaseHas('payment_transactions', [
            'id' => $transaction->id,
            'payment_status' => 'paid',
        ]);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'payment_status' => 'paid',
            'status' => 'confirmed',
        ]);
        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $order->id,
            'status' => 'confirmed',
        ]);
    }

    public function test_payment_failure_callback_updates_status_to_failed(): void
    {
        [$user, $order, $transaction] = $this->createOrderWithPendingTransaction();

        $payload = $this->callbackPayload($order->order_number, $transaction->transaction_id, 'failed', (float) $order->total_amount);

        $response = $this->post(route('payment.callback'), $payload);

        $response->assertOk();
        $this->assertDatabaseHas('payment_transactions', [
            'id' => $transaction->id,
            'payment_status' => 'failed',
        ]);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'payment_status' => 'failed',
        ]);
    }

    public function test_payment_callback_rejects_client_modified_amount(): void
    {
        [$user, $order, $transaction] = $this->createOrderWithPendingTransaction();

        $payload = $this->callbackPayload($order->order_number, $transaction->transaction_id, 'paid', (float) $order->total_amount + 1000);

        $response = $this->post(route('payment.callback'), $payload);

        $response->assertStatus(422);
        $this->assertDatabaseHas('payment_transactions', [
            'id' => $transaction->id,
            'payment_status' => 'pending',
        ]);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'payment_status' => 'pending',
            'status' => 'pending',
        ]);
    }

    public function test_duplicate_paid_callback_is_idempotent(): void
    {
        [$user, $order, $transaction] = $this->createOrderWithPendingTransaction();
        $payload = $this->callbackPayload($order->order_number, $transaction->transaction_id, 'paid', (float) $order->total_amount);

        $this->post(route('payment.callback'), $payload)->assertOk();
        $this->post(route('payment.callback'), $payload)->assertOk();

        $historyCount = $order->statusHistory()->where('status', 'confirmed')->count();
        $this->assertEquals(1, $historyCount);
    }

    public function test_payment_callback_requires_valid_signature(): void
    {
        [$user, $order, $transaction] = $this->createOrderWithPendingTransaction();

        $payload = [
            'order_number' => $order->order_number,
            'transaction_id' => $transaction->transaction_id,
            'payment_status' => 'paid',
            'amount' => (float) $order->total_amount,
            'signature' => 'invalid-signature',
        ];

        $response = $this->post(route('payment.callback'), $payload);

        $response->assertStatus(403);
    }

    public function test_duplicate_payment_page_attempt_does_not_create_multiple_pending_transactions(): void
    {
        [$user, $order, $transaction] = $this->createOrderWithPendingTransaction();

        $this->actingAs($user)->get(route('payment.show', $order))->assertOk();
        $this->actingAs($user)->get(route('payment.show', $order))->assertOk();

        $pendingCount = PaymentTransaction::where('order_id', $order->id)
            ->where('payment_status', 'pending')
            ->count();

        $this->assertEquals(1, $pendingCount);
    }

    public function test_user_cannot_access_other_user_payment_page_or_refund(): void
    {
        [$owner, $order] = $this->createOrderWithPendingTransaction();
        $other = User::factory()->create();

        $this->actingAs($other)->get(route('payment.show', $order))->assertForbidden();
        $this->actingAs($other)->post(route('payment.refund', $order), ['amount' => 1000])->assertForbidden();
    }

    public function test_invalid_order_status_transition_is_blocked(): void
    {
        [$user, $order] = $this->createOrderWithPendingTransaction();

        $response = $this->actingAs($user)->post(route('order.delivered', $order));

        $response->assertSessionHasErrors('status');
        $order->refresh();
        $this->assertEquals(OrderLifecycleService::STATUS_PENDING, $order->status);
    }

    public function test_refund_amount_cannot_exceed_total(): void
    {
        [$user, $order, $transaction] = $this->createOrderWithPendingTransaction();
        $this->markOrderPaidViaCallback($order, $transaction);

        $response = $this->actingAs($user)->post(route('payment.refund', $order), [
            'amount' => (float) $order->total_amount + 1,
            'reason' => 'Over refund',
        ]);

        $response->assertSessionHasErrors('refund');
    }

    public function test_refund_success_updates_payment_and_order_lifecycle(): void
    {
        [$user, $order, $transaction] = $this->createOrderWithPendingTransaction();
        $this->markOrderPaidViaCallback($order, $transaction);

        $order->status = OrderLifecycleService::STATUS_DELIVERED;
        $order->save();

        $response = $this->actingAs($user)->post(route('payment.refund', $order), [
            'amount' => (float) $order->total_amount,
            'reason' => 'Customer request',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('refunds', [
            'order_id' => $order->id,
            'amount' => (float) $order->total_amount,
            'status' => 'completed',
        ]);

        $order->refresh();
        $this->assertEquals('refunded', $order->payment_status);
        $this->assertEquals(OrderLifecycleService::STATUS_REFUNDED, $order->status);
    }

    public function test_order_detail_and_payment_pages_display_payment_info(): void
    {
        [$user, $order, $transaction] = $this->createOrderWithPendingTransaction();

        $orderDetail = $this->actingAs($user)->get(route('order.show', $order));
        $orderDetail->assertOk();
        $orderDetail->assertSeeText($order->order_number);
        $orderDetail->assertSeeText(strtoupper($order->payment_status));

        $paymentPage = $this->actingAs($user)->get(route('payment.show', $order));
        $paymentPage->assertOk();
        $paymentPage->assertSeeText($transaction->transaction_id);
    }

    private function baseData(): array
    {
        $category = Category::create([
            'name' => 'Nhan bac',
            'slug' => 'nhan-bac-' . uniqid(),
            'is_active' => true,
        ]);

        $material = Material::create([
            'name' => 'Bac 925',
            'code' => 'bac-925-' . uniqid(),
            'purity' => '925',
            'is_active' => true,
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'material_id' => $material->id,
            'name' => 'Nhan 925 ' . uniqid(),
            'slug' => 'nhan-925-' . uniqid(),
            'short_description' => 'Mo ta ngan',
            'description' => 'Mo ta',
            'price' => 100000,
            'sale_price' => 90000,
            'sku' => 'SKU-' . uniqid(),
            'featured_image' => 'demo.jpg',
            'featured' => true,
            'is_active' => true,
            'stock' => 10,
            'views' => 0,
            'average_rating' => 5,
        ]);

        $shipping = ShippingMethod::create([
            'name' => 'Tieu chuan',
            'code' => 'standard-' . uniqid(),
            'description' => 'Giao hang tieu chuan',
            'base_fee' => 30000,
            'fee_per_km' => 0,
            'estimated_days_min' => 3,
            'estimated_days_max' => 5,
            'is_active' => true,
        ]);

        $user = User::factory()->create();

        return [$user, $shipping, $product];
    }

    private function checkoutPayload(int $shippingMethodId): array
    {
        return [
            'customer_name' => 'Nguyen Van A',
            'customer_phone' => '0912345678',
            'customer_email' => 'user@example.com',
            'province' => 'Ha Noi',
            'district' => 'Hoan Kiem',
            'ward' => 'Trang Tien',
            'address_line' => '123 Duong ABC',
            'shipping_method_id' => $shippingMethodId,
            'payment_method' => 'cod',
        ];
    }

    private function createCart(User $user, Product $product, int $quantity, float $unitPrice): \App\Models\Cart
    {
        $cart = \App\Models\Cart::create([
            'user_id' => $user->id,
            'session_id' => session()->getId(),
        ]);

        $cart->items()->create([
            'product_id' => $product->id,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
        ]);

        return $cart;
    }

    private function createOrderWithPendingTransaction(): array
    {
        [$user, $shipping, $product] = $this->baseData();
        $this->createCart($user, $product, 1, 90000);

        $this->actingAs($user)->post(route('checkout.store'), $this->checkoutPayload($shipping->id));

        $order = Order::query()->latest('id')->firstOrFail();
        $transaction = PaymentTransaction::where('order_id', $order->id)->latest('id')->firstOrFail();

        return [$user, $order, $transaction];
    }

    private function callbackPayload(string $orderNumber, string $transactionId, string $status, float $amount): array
    {
        $plain = implode('|', [
            $orderNumber,
            $transactionId,
            strtolower($status),
            number_format($amount, 2, '.', ''),
        ]);

        return [
            'order_number' => $orderNumber,
            'transaction_id' => $transactionId,
            'payment_status' => $status,
            'amount' => $amount,
            'signature' => hash_hmac('sha256', $plain, (string) config('app.key')),
        ];
    }

    private function markOrderPaidViaCallback(Order $order, PaymentTransaction $transaction): void
    {
        $payload = $this->callbackPayload($order->order_number, $transaction->transaction_id, 'paid', (float) $order->total_amount);
        $this->post(route('payment.callback'), $payload)->assertOk();
        $order->refresh();
    }
}
