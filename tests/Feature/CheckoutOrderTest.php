<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Material;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShippingMethod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckoutOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_open_checkout_when_cart_has_items(): void
    {
        [$user, $shipping, $product] = $this->createBaseData();
        $this->createCartWithItem($user, $product, 1, 90000);

        $response = $this->actingAs($user)->get(route('checkout.index'));

        $response->assertOk();
        $response->assertSeeText('Xác nhận đơn hàng');
        $response->assertSeeText('Nhẫn bạc 925');
    }

    public function test_checkout_redirects_when_cart_is_empty(): void
    {
        [$user] = $this->createBaseData();

        $response = $this->actingAs($user)->get(route('checkout.index'));

        $response->assertRedirect(route('cart.index'));
        $response->assertSessionHas('error');
    }

    public function test_guest_cannot_access_checkout(): void
    {
        $response = $this->get(route('checkout.index'));

        $response->assertRedirect(route('login'));
    }

    public function test_user_can_create_order_successfully(): void
    {
        [$user, $shipping, $product] = $this->createBaseData();
        $cart = $this->createCartWithItem($user, $product, 2, 90000);

        $response = $this->actingAs($user)->post(route('checkout.store'), $this->payload($shipping->id));

        $response->assertRedirect();
        $this->assertDatabaseHas('orders', [
            'user_id' => $user->id,
            'shipping_method_id' => $shipping->id,
            'status' => 'pending',
            'payment_status' => 'pending',
            'customer_name' => 'Nguyen Van A',
        ]);
        $this->assertDatabaseMissing('cart_items', ['cart_id' => $cart->id]);
    }

    public function test_order_totals_are_calculated_from_server_data(): void
    {
        [$user, $shipping, $product] = $this->createBaseData();
        $this->createCartWithItem($user, $product, 2, 90000);

        $this->actingAs($user)->post(route('checkout.store'), $this->payload($shipping->id));

        $order = Order::query()->firstOrFail();

        $this->assertEquals(180000.0, (float) $order->subtotal);
        $this->assertEquals(30000.0, (float) $order->shipping_fee);
        $this->assertEquals(0.0, (float) $order->discount_amount);
        $this->assertEquals(210000.0, (float) $order->total_amount);
    }

    public function test_order_items_are_created_correctly(): void
    {
        [$user, $shipping, $product] = $this->createBaseData();
        $this->createCartWithItem($user, $product, 2, 90000);

        $this->actingAs($user)->post(route('checkout.store'), $this->payload($shipping->id));

        $order = Order::query()->with('items')->firstOrFail();

        $this->assertCount(1, $order->items);
        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'unit_price' => 90000,
            'total_price' => 180000,
        ]);
    }

    public function test_stock_is_updated_after_successful_checkout(): void
    {
        [$user, $shipping, $product] = $this->createBaseData();
        $this->createCartWithItem($user, $product, 2, 90000);

        $this->actingAs($user)->post(route('checkout.store'), $this->payload($shipping->id));

        $product->refresh();
        $this->assertEquals(3, (int) $product->stock);
    }

    public function test_checkout_with_multiple_items_updates_each_inventory_row(): void
    {
        [$user, $shipping, $product] = $this->createBaseData();
        $cart = $this->createCartWithItem($user, $product, 2, 90000);
        $secondProduct = Product::create([
            'name' => 'Day chuyen bac 925',
            'slug' => 'day-chuyen-bac-925-' . uniqid(),
            'sku' => 'SKU-CHECKOUT-SECOND-' . uniqid(),
            'price' => 200000,
            'is_active' => true,
            'stock' => 4,
        ]);
        $cart->items()->create([
            'product_id' => $secondProduct->id,
            'quantity' => 3,
            'unit_price' => 200000,
        ]);

        $this->actingAs($user)->post(route('checkout.store'), $this->payload($shipping->id));

        $this->assertSame(3, (int) $product->refresh()->stock);
        $this->assertSame(1, (int) $secondProduct->refresh()->stock);
        $this->assertDatabaseCount('order_items', 2);
    }

    public function test_checkout_decrements_variant_stock_without_changing_parent_stock(): void
    {
        [$user, $shipping, $product] = $this->createBaseData();
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'SKU-CHECKOUT-VARIANT-' . uniqid(),
            'size' => 'M',
            'price' => 110000,
            'stock' => 4,
            'is_active' => true,
        ]);
        $this->createCartWithVariant($user, $product, $variant, 2, 110000);

        $this->actingAs($user)->post(route('checkout.store'), $this->payload($shipping->id));

        $this->assertSame(5, (int) $product->refresh()->stock);
        $this->assertSame(2, (int) $variant->refresh()->stock);
        $this->assertDatabaseHas('order_items', [
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity' => 2,
        ]);
    }

    public function test_cancel_restores_product_and_variant_inventory(): void
    {
        [$user, $shipping, $product] = $this->createBaseData();
        $simpleProduct = Product::create([
            'name' => 'San pham khong bien the',
            'slug' => 'san-pham-khong-bien-the-'.uniqid(),
            'sku' => 'SKU-CANCEL-SIMPLE-'.uniqid(),
            'price' => 90000,
            'is_active' => true,
            'stock' => 5,
        ]);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'SKU-CANCEL-VARIANT-' . uniqid(),
            'size' => 'L',
            'price' => 120000,
            'stock' => 4,
            'is_active' => true,
        ]);
        $cart = $this->createCartWithItem($user, $simpleProduct, 2, 90000);
        $cart->items()->create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity' => 2,
            'unit_price' => 120000,
        ]);

        $this->actingAs($user)->post(route('checkout.store'), $this->payload($shipping->id));
        $order = Order::query()->firstOrFail();
        $this->assertSame(3, (int) $simpleProduct->refresh()->stock);
        $this->assertSame(5, (int) $product->refresh()->stock);
        $this->assertSame(2, (int) $variant->refresh()->stock);

        $this->actingAs($user)->post(route('order.cancel', $order))
            ->assertRedirect(route('order.show', $order));

        $this->assertSame(5, (int) $simpleProduct->refresh()->stock);
        $this->assertSame(5, (int) $product->refresh()->stock);
        $this->assertSame(4, (int) $variant->refresh()->stock);
    }

    public function test_duplicate_checkout_token_returns_original_order_without_double_decrement(): void
    {
        [$user, $shipping, $product] = $this->createBaseData();
        $this->createCartWithItem($user, $product, 2, 90000);
        $payload = $this->payload($shipping->id);

        $firstResponse = $this->actingAs($user)->post(route('checkout.store'), $payload);
        $order = Order::query()->firstOrFail();

        $firstResponse->assertRedirect(route('order.show', $order));

        $secondResponse = $this->actingAs($user)->post(route('checkout.store'), $payload);

        $secondResponse->assertRedirect(route('order.show', $order));
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('payment_transactions', 1);
        $this->assertSame(3, (int) $product->refresh()->stock);
    }

    public function test_checkout_token_cannot_be_reused_by_another_user(): void
    {
        [$user, $shipping, $product] = $this->createBaseData();
        $this->createCartWithItem($user, $product, 2, 90000);
        $payload = $this->payload($shipping->id);

        $this->actingAs($user)->post(route('checkout.store'), $payload);
        $order = Order::query()->firstOrFail();

        $otherUser = User::factory()->create();
        $this->createCartWithItem($otherUser, $product, 1, 90000);

        $response = $this->actingAs($otherUser)
            ->from(route('checkout.index'))
            ->post(route('checkout.store'), $payload);

        $response->assertRedirect(route('checkout.index'));
        $response->assertSessionHasErrors('checkout');
        $this->assertDatabaseCount('orders', 1);
        $this->assertSame(3, (int) $product->refresh()->stock);
        $this->assertNotSame($otherUser->id, $order->refresh()->user_id);
    }

    public function test_stock_guard_rolls_back_and_never_goes_negative(): void
    {
        [$user, $shipping, $product] = $this->createBaseData();
        $product->update(['stock' => 1]);
        $cart = $this->createCartWithItem($user, $product, 1, 90000);
        $cart->items()->create([
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => 90000,
        ]);

        $response = $this->actingAs($user)
            ->from(route('checkout.index'))
            ->post(route('checkout.store'), $this->payload($shipping->id));

        $response->assertRedirect(route('checkout.index'));
        $response->assertSessionHasErrors('checkout');
        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(1, (int) $product->refresh()->stock);
    }

    public function test_valid_cancel_restores_stock_exactly_once(): void
    {
        [$user, $shipping, $product] = $this->createBaseData();
        $this->createCartWithItem($user, $product, 2, 90000);

        $this->actingAs($user)->post(route('checkout.store'), $this->payload($shipping->id));
        $order = Order::query()->firstOrFail();
        $this->assertSame(3, (int) $product->refresh()->stock);

        $this->actingAs($user)->post(route('order.cancel', $order))
            ->assertRedirect(route('order.show', $order));
        $this->assertSame('cancelled', $order->refresh()->status);
        $this->assertSame(5, (int) $product->refresh()->stock);

        $this->actingAs($user)->post(route('order.cancel', $order))
            ->assertRedirect(route('order.show', $order));
        $this->assertSame(5, (int) $product->refresh()->stock);
        $this->assertDatabaseCount('order_status_history', 2);
    }

    public function test_invalid_cancel_transition_does_not_restore_stock(): void
    {
        [$user, $shipping, $product] = $this->createBaseData();
        $this->createCartWithItem($user, $product, 2, 90000);

        $this->actingAs($user)->post(route('checkout.store'), $this->payload($shipping->id));
        $order = Order::query()->firstOrFail();
        $order->update(['status' => 'shipped']);

        $response = $this->actingAs($user)
            ->from(route('order.show', $order))
            ->post(route('order.cancel', $order));

        $response->assertRedirect(route('order.show', $order));
        $response->assertSessionHasErrors('status');
        $this->assertSame('shipped', $order->refresh()->status);
        $this->assertSame(3, (int) $product->refresh()->stock);
        $this->assertDatabaseMissing('order_status_history', [
            'order_id' => $order->id,
            'status' => 'cancelled',
        ]);
    }

    public function test_order_status_history_is_created(): void
    {
        [$user, $shipping, $product] = $this->createBaseData();
        $this->createCartWithItem($user, $product, 1, 90000);

        $this->actingAs($user)->post(route('checkout.store'), $this->payload($shipping->id));

        $order = Order::query()->firstOrFail();

        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $order->id,
            'status' => 'pending',
        ]);
    }

    public function test_shipping_method_is_applied_to_order(): void
    {
        [$user, $shipping, $product] = $this->createBaseData();
        $this->createCartWithItem($user, $product, 1, 90000);

        $this->actingAs($user)->post(route('checkout.store'), $this->payload($shipping->id));

        $this->assertDatabaseHas('orders', [
            'shipping_method_id' => $shipping->id,
            'shipping_fee' => 30000,
        ]);
    }

    public function test_valid_coupon_is_applied_and_usage_is_recorded(): void
    {
        [$user, $shipping, $product] = $this->createBaseData();
        $this->createCartWithItem($user, $product, 2, 90000);

        $coupon = Coupon::create([
            'code' => 'SAVE10',
            'type' => 'percent',
            'value' => 10,
            'minimum_order_amount' => 100000,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
            'usage_limit' => 10,
            'is_active' => true,
        ]);

        $payload = $this->payload($shipping->id);
        $payload['coupon_code'] = $coupon->code;

        $this->actingAs($user)->post(route('checkout.store'), $payload);

        $order = Order::query()->firstOrFail();

        $this->assertEquals(18000.0, (float) $order->discount_amount);
        $this->assertEquals(192000.0, (float) $order->total_amount);
        $this->assertDatabaseHas('coupon_usages', [
            'coupon_id' => $coupon->id,
            'user_id' => $user->id,
            'order_number' => $order->order_number,
            'discount_amount' => 18000,
        ]);
    }

    public function test_invalid_coupon_is_rejected(): void
    {
        [$user, $shipping, $product] = $this->createBaseData();
        $this->createCartWithItem($user, $product, 1, 90000);

        $payload = $this->payload($shipping->id);
        $payload['coupon_code'] = 'INVALID-CODE';

        $response = $this->actingAs($user)->from(route('checkout.index'))->post(route('checkout.store'), $payload);

        $response->assertRedirect(route('checkout.index'));
        $response->assertSessionHasErrors('checkout');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_user_cannot_view_other_user_order(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $order = Order::create([
            'user_id' => $userA->id,
            'order_number' => 'SA-TEST-001',
            'status' => 'pending',
            'payment_status' => 'pending',
            'customer_name' => 'Owner A',
            'customer_phone' => '0900000000',
            'shipping_address' => 'Address A',
            'subtotal' => 100000,
            'shipping_fee' => 30000,
            'discount_amount' => 0,
            'total_amount' => 130000,
        ]);

        $response = $this->actingAs($userB)->get(route('order.show', $order));

        $response->assertForbidden();
    }

    public function test_unavailable_product_in_cart_is_handled(): void
    {
        [$user, $shipping, $product] = $this->createBaseData();
        $cart = $this->createCartWithItem($user, $product, 1, 90000);

        $product->update(['is_active' => false]);

        $response = $this->actingAs($user)
            ->from(route('checkout.index'))
            ->post(route('checkout.store'), $this->payload($shipping->id));

        $response->assertRedirect(route('checkout.index'));
        $response->assertSessionHasErrors('checkout');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseHas('cart_items', ['cart_id' => $cart->id]);
    }

    public function test_insufficient_stock_is_handled(): void
    {
        [$user, $shipping, $product] = $this->createBaseData();
        $product->update(['stock' => 1]);
        $this->createCartWithItem($user, $product, 2, 90000);

        $response = $this->actingAs($user)
            ->from(route('checkout.index'))
            ->post(route('checkout.store'), $this->payload($shipping->id));

        $response->assertRedirect(route('checkout.index'));
        $response->assertSessionHasErrors('checkout');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_checkout_validation_works(): void
    {
        [$user, $shipping, $product] = $this->createBaseData();
        $this->createCartWithItem($user, $product, 1, 90000);

        $response = $this->actingAs($user)->post(route('checkout.store'), [
            'customer_name' => '',
            'customer_phone' => '',
            'province' => '',
            'district' => '',
            'ward' => '',
            'address_line' => '',
            'shipping_method_id' => '',
            'payment_method' => '',
        ]);

        $response->assertSessionHasErrors([
            'customer_name',
            'customer_phone',
            'province',
            'district',
            'ward',
            'address_line',
            'shipping_method_id',
            'payment_method',
        ]);
    }

    public function test_transaction_rolls_back_and_cart_remains_when_checkout_fails(): void
    {
        [$user, $shipping, $product] = $this->createBaseData();
        $cart = $this->createCartWithItem($user, $product, 3, 90000);
        $product->update(['stock' => 1]);

        $response = $this->actingAs($user)
            ->from(route('checkout.index'))
            ->post(route('checkout.store'), $this->payload($shipping->id));

        $response->assertRedirect(route('checkout.index'));
        $response->assertSessionHasErrors('checkout');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseHas('cart_items', ['cart_id' => $cart->id, 'quantity' => 3]);
    }

    public function test_order_history_page_displays_user_orders_only(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        Order::create([
            'user_id' => $user->id,
            'order_number' => 'SA-MINE-001',
            'status' => 'pending',
            'payment_status' => 'pending',
            'customer_name' => 'Mine',
            'customer_phone' => '0900000000',
            'shipping_address' => 'Address',
            'subtotal' => 100000,
            'shipping_fee' => 30000,
            'discount_amount' => 0,
            'total_amount' => 130000,
        ]);

        Order::create([
            'user_id' => $otherUser->id,
            'order_number' => 'SA-OTHER-001',
            'status' => 'pending',
            'payment_status' => 'pending',
            'customer_name' => 'Other',
            'customer_phone' => '0900000001',
            'shipping_address' => 'Address',
            'subtotal' => 200000,
            'shipping_fee' => 30000,
            'discount_amount' => 0,
            'total_amount' => 230000,
        ]);

        $response = $this->actingAs($user)->get(route('account.orders'));

        $response->assertOk();
        $response->assertSeeText('SA-MINE-001');
        $response->assertDontSeeText('SA-OTHER-001');
    }

    private function createBaseData(): array
    {
        $category = Category::create([
            'name' => 'Nhan bac',
            'slug' => 'nhan-bac',
            'is_active' => true,
        ]);

        $material = Material::create([
            'name' => 'Bac 925',
            'code' => 'bac-925',
            'purity' => '925',
            'is_active' => true,
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'material_id' => $material->id,
            'name' => 'Nhẫn bạc 925',
            'slug' => 'nhan-bac-925-' . uniqid(),
            'short_description' => 'Mau dep',
            'description' => 'Mo ta',
            'price' => 100000,
            'sale_price' => 90000,
            'sku' => 'SKU-CHECKOUT-' . uniqid(),
            'featured_image' => 'demo.jpg',
            'featured' => true,
            'is_active' => true,
            'stock' => 5,
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

    private function createCartWithItem(User $user, Product $product, int $quantity, float $unitPrice): Cart
    {
        $cart = Cart::create([
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

    private function createCartWithVariant(User $user, Product $product, ProductVariant $variant, int $quantity, float $unitPrice): Cart
    {
        $cart = Cart::create([
            'user_id' => $user->id,
            'session_id' => session()->getId(),
        ]);

        $cart->items()->create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
        ]);

        return $cart;
    }

    private function payload(int $shippingMethodId): array
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
            'checkout_token' => (string) \Illuminate\Support\Str::uuid(),
        ];
    }
}
