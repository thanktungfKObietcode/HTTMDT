<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\ShippingMethod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class PhaseFourCartCorrectnessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cart_is_bound_to_server_side_session_identity(): void
    {
        $product = $this->product();

        $this->post(route('cart.add'), ['product_id' => $product->id, 'quantity' => 2])
            ->assertRedirect(route('cart.index'));

        $cart = Cart::query()->firstOrFail();
        $this->assertNull($cart->user_id);
        $this->assertSame(session('cart_guest_session_id'), $cart->session_id);
        $this->assertSame($cart->id, session('cart_id'));
        $this->assertSame(10, (int) $product->refresh()->stock);
    }

    public function test_foreign_or_stale_session_cart_id_is_ignored(): void
    {
        $foreignProduct = $this->product(['name' => 'Foreign cart product']);
        $foreignCart = Cart::create(['session_id' => 'another-session']);
        $foreignCart->items()->create([
            'product_id' => $foreignProduct->id,
            'quantity' => 1,
            'unit_price' => '100000.00',
        ]);

        $response = $this->withSession([
            'cart_id' => $foreignCart->id,
            'cart_guest_session_id' => 'current-session-identity',
        ])->get(route('cart.index'));

        $response->assertOk()->assertDontSeeText($foreignProduct->name);
        $this->assertDatabaseHas('carts', [
            'user_id' => null,
            'session_id' => 'current-session-identity',
        ]);
        $this->assertDatabaseHas('cart_items', ['cart_id' => $foreignCart->id]);
        $this->assertNotSame($foreignCart->id, session('cart_id'));
    }

    public function test_authenticated_cart_page_and_checkout_use_same_canonical_cart(): void
    {
        $user = User::factory()->create();
        $product = $this->product(['name' => 'Canonical cart product']);
        $cart = $this->userCart($user, $product, 2);
        $this->shippingMethod();

        $this->actingAs($user)->get(route('cart.index'))
            ->assertOk()
            ->assertSeeText($product->name);
        $this->assertSame($cart->id, session('cart_id'));

        $this->get(route('checkout.index'))
            ->assertOk()
            ->assertSeeText($product->name);
        $this->assertSame($cart->id, session('cart_id'));
    }

    public function test_legacy_user_carts_and_null_variant_duplicates_are_consolidated(): void
    {
        $user = User::factory()->create();
        $product = $this->product(['stock' => 5]);
        $firstCart = $this->userCart($user, $product, 2);
        $firstCart->items()->create([
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => '1.00',
        ]);
        $secondCart = $this->userCart($user, $product, 4);

        $this->actingAs($user)->get(route('cart.index'))->assertOk();

        $this->assertDatabaseCount('carts', 1);
        $this->assertDatabaseCount('cart_items', 1);
        $this->assertDatabaseHas('cart_items', [
            'cart_id' => $firstCart->id,
            'product_id' => $product->id,
            'product_variant_id' => null,
            'quantity' => 5,
            'unit_price' => '100000.00',
        ]);
        $this->assertDatabaseMissing('carts', ['id' => $secondCart->id]);
        $this->assertSame(5, (int) $product->refresh()->stock);
    }

    public function test_login_merges_guest_and_existing_user_cart_by_logical_identity(): void
    {
        $productA = $this->product(['name' => 'Product A']);
        $productB = $this->product(['name' => 'Product B']);
        $productC = $this->product(['name' => 'Product C']);
        $this->post(route('cart.add'), ['product_id' => $productA->id, 'quantity' => 2]);
        $this->post(route('cart.add'), ['product_id' => $productB->id, 'quantity' => 1]);

        $user = User::factory()->create([
            'email' => 'merge@example.com',
            'password' => Hash::make('password123'),
        ]);
        $userCart = $this->userCart($user, $productA, 1);
        $userCart->items()->create([
            'product_id' => $productC->id,
            'quantity' => 1,
            'unit_price' => '100000.00',
        ]);

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password123',
        ])->assertRedirect(route('home'));

        $cart = Cart::query()->where('user_id', $user->id)->sole();
        $this->assertDatabaseCount('carts', 1);
        $this->assertDatabaseHas('cart_items', ['cart_id' => $cart->id, 'product_id' => $productA->id, 'quantity' => 3]);
        $this->assertDatabaseHas('cart_items', ['cart_id' => $cart->id, 'product_id' => $productB->id, 'quantity' => 1]);
        $this->assertDatabaseHas('cart_items', ['cart_id' => $cart->id, 'product_id' => $productC->id, 'quantity' => 1]);
        $this->assertSame(3, $cart->items()->count());
    }

    public function test_login_claims_guest_cart_when_user_has_no_existing_cart(): void
    {
        $product = $this->product();
        $this->post(route('cart.add'), ['product_id' => $product->id, 'quantity' => 2]);
        $user = User::factory()->create([
            'email' => 'guest-only@example.com',
            'password' => Hash::make('password123'),
        ]);

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password123',
        ])->assertRedirect(route('home'));

        $cart = Cart::query()->where('user_id', $user->id)->sole();
        $this->assertDatabaseCount('carts', 1);
        $this->assertDatabaseHas('cart_items', [
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 2,
        ]);
        $this->assertSame(10, (int) $product->refresh()->stock);
    }

    public function test_login_merge_keeps_different_variants_separate_and_merges_same_variant(): void
    {
        $product = $this->product(['stock' => 99]);
        $small = $this->variant($product, ['size' => 'S', 'stock' => 5]);
        $large = $this->variant($product, ['size' => 'L', 'stock' => 5]);
        $this->post(route('cart.add'), [
            'product_id' => $product->id,
            'product_variant_id' => $small->id,
            'quantity' => 2,
        ]);

        $user = User::factory()->create([
            'email' => 'variant-merge@example.com',
            'password' => Hash::make('password123'),
        ]);
        $cart = Cart::create(['user_id' => $user->id, 'session_id' => 'old-user-session']);
        $cart->items()->create(['product_id' => $product->id, 'product_variant_id' => $small->id, 'quantity' => 1, 'unit_price' => '100000.00']);
        $cart->items()->create(['product_id' => $product->id, 'product_variant_id' => $large->id, 'quantity' => 1, 'unit_price' => '100000.00']);

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password123'])
            ->assertRedirect(route('home'));

        $canonical = Cart::query()->where('user_id', $user->id)->sole();
        $this->assertDatabaseCount('cart_items', 2);
        $this->assertDatabaseHas('cart_items', ['cart_id' => $canonical->id, 'product_variant_id' => $small->id, 'quantity' => 3]);
        $this->assertDatabaseHas('cart_items', ['cart_id' => $canonical->id, 'product_variant_id' => $large->id, 'quantity' => 1]);
        $this->assertSame(5, (int) $small->refresh()->stock);
        $this->assertSame(5, (int) $large->refresh()->stock);
        $this->assertSame(99, (int) $product->refresh()->stock);
    }

    public function test_login_merge_caps_quantity_to_stock_and_exposes_notice_without_mutating_inventory(): void
    {
        $product = $this->product(['stock' => 5]);
        $this->post(route('cart.add'), ['product_id' => $product->id, 'quantity' => 4]);
        $user = User::factory()->create([
            'email' => 'stock-merge@example.com',
            'password' => Hash::make('password123'),
        ]);
        $this->userCart($user, $product, 3);

        $response = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password123',
        ]);

        $response->assertSessionHas('cart_notices', fn (array $notices): bool => str_contains(implode(' ', $notices), 'điều chỉnh'));
        $cart = Cart::query()->where('user_id', $user->id)->sole();
        $this->assertDatabaseHas('cart_items', ['cart_id' => $cart->id, 'product_id' => $product->id, 'quantity' => 5]);
        $this->assertSame(5, (int) $product->refresh()->stock);
    }

    public function test_login_merge_removes_unavailable_guest_item_with_visible_notice(): void
    {
        $product = $this->product();
        $this->post(route('cart.add'), ['product_id' => $product->id]);
        $product->update(['is_active' => false]);
        $user = User::factory()->create([
            'email' => 'unavailable-merge@example.com',
            'password' => Hash::make('password123'),
        ]);

        $response = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password123',
        ]);

        $response->assertSessionHas('cart_notices', fn (array $notices): bool => str_contains(implode(' ', $notices), 'không còn khả dụng'));
        $cart = Cart::query()->where('user_id', $user->id)->sole();
        $this->assertSame(0, $cart->items()->count());
        $this->assertSame(10, (int) $product->refresh()->stock);
    }

    public function test_registration_claims_guest_cart_without_allowing_role_injection(): void
    {
        $customerRole = Role::create(['name' => 'customer', 'guard_name' => 'web']);
        $adminRole = Role::create(['name' => 'admin', 'guard_name' => 'web']);
        $product = $this->product();
        $this->post(route('cart.add'), ['product_id' => $product->id, 'quantity' => 2]);

        $this->post(route('register.store'), [
            'name' => 'Cart Customer',
            'email' => 'cart-customer@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'roles' => [$adminRole->id],
        ])->assertRedirect(route('home'));

        $user = User::query()->where('email', 'cart-customer@example.com')->firstOrFail();
        $cart = Cart::query()->where('user_id', $user->id)->sole();
        $this->assertDatabaseHas('cart_items', ['cart_id' => $cart->id, 'product_id' => $product->id, 'quantity' => 2]);
        $this->assertEquals([$customerRole->id], $user->roles()->pluck('roles.id')->all());
    }

    public function test_add_rejects_inactive_and_zero_stock_products(): void
    {
        $inactive = $this->product(['is_active' => false]);
        $outOfStock = $this->product(['stock' => 0]);

        $this->post(route('cart.add'), ['product_id' => $inactive->id])->assertSessionHasErrors('product_id');
        $this->post(route('cart.add'), ['product_id' => $outOfStock->id])->assertSessionHasErrors('quantity');
        $this->assertDatabaseCount('cart_items', 0);
        $this->assertSame(0, (int) $outOfStock->refresh()->stock);
    }

    public function test_add_requires_valid_active_in_stock_variant(): void
    {
        $product = $this->product();
        $active = $this->variant($product);
        $otherProduct = $this->product();
        $foreign = $this->variant($otherProduct);
        $inactive = $this->variant($product, ['is_active' => false]);
        $outOfStock = $this->variant($product, ['stock' => 0]);

        $this->post(route('cart.add'), ['product_id' => $product->id])->assertSessionHasErrors('product_variant_id');
        $this->post(route('cart.add'), ['product_id' => $product->id, 'product_variant_id' => $foreign->id])->assertSessionHasErrors('product_variant_id');
        $this->post(route('cart.add'), ['product_id' => $product->id, 'product_variant_id' => $inactive->id])->assertSessionHasErrors('product_variant_id');
        $this->post(route('cart.add'), ['product_id' => $product->id, 'product_variant_id' => $outOfStock->id])->assertSessionHasErrors('quantity');
        $this->post(route('cart.add'), ['product_id' => $product->id, 'product_variant_id' => $active->id])->assertRedirect(route('cart.index'));

        $this->assertDatabaseCount('cart_items', 1);
        $this->assertDatabaseHas('cart_items', ['product_variant_id' => $active->id]);
    }

    public function test_sequential_duplicate_add_merges_and_ignores_client_price_without_changing_stock(): void
    {
        $product = $this->product(['price' => '125000.00', 'stock' => 5]);

        $this->post(route('cart.add'), ['product_id' => $product->id, 'quantity' => 1, 'price' => '1.00']);
        $this->post(route('cart.add'), ['product_id' => $product->id, 'quantity' => 2, 'price' => '2.00']);

        $this->assertDatabaseCount('cart_items', 1);
        $this->assertDatabaseHas('cart_items', [
            'product_id' => $product->id,
            'quantity' => 3,
            'unit_price' => '125000.00',
        ]);
        $this->assertSame(5, (int) $product->refresh()->stock);
    }

    public function test_update_rejects_quantity_above_product_or_variant_stock(): void
    {
        $user = User::factory()->create();
        $product = $this->product(['stock' => 2]);
        $productItem = $this->userCart($user, $product, 1)->items()->firstOrFail();

        $this->actingAs($user)->put(route('cart.update', $productItem), ['quantity' => 3])
            ->assertSessionHasErrors('quantity');
        $this->assertSame(1, (int) $productItem->refresh()->quantity);

        $variantProduct = $this->product(['stock' => 99]);
        $variant = $this->variant($variantProduct, ['stock' => 2]);
        $cart = Cart::query()->where('user_id', $user->id)->firstOrFail();
        $variantItem = $cart->items()->create([
            'product_id' => $variantProduct->id,
            'product_variant_id' => $variant->id,
            'quantity' => 1,
            'unit_price' => '100000.00',
        ]);

        $this->put(route('cart.update', $variantItem), ['quantity' => 3])->assertSessionHasErrors('quantity');
        $this->assertSame(1, (int) $variantItem->refresh()->quantity);
        $this->assertSame(99, (int) $variantProduct->refresh()->stock);
        $this->assertSame(2, (int) $variant->refresh()->stock);
    }

    public function test_update_rejects_inactive_inventory_but_allows_valid_reduction_after_stock_change(): void
    {
        $user = User::factory()->create();
        $product = $this->product(['stock' => 5]);
        $item = $this->userCart($user, $product, 5)->items()->firstOrFail();
        $product->update(['stock' => 2]);

        $this->actingAs($user)->put(route('cart.update', $item), ['quantity' => 2])->assertRedirect(route('cart.index'));
        $this->assertSame(2, (int) $item->refresh()->quantity);

        $product->update(['is_active' => false]);
        $this->put(route('cart.update', $item), ['quantity' => 1])->assertSessionHasErrors('product_id');
        $this->assertSame(2, (int) $item->refresh()->quantity);

        $variantProduct = $this->product();
        $variant = $this->variant($variantProduct);
        $cart = Cart::query()->where('user_id', $user->id)->firstOrFail();
        $variantItem = $cart->items()->create([
            'product_id' => $variantProduct->id,
            'product_variant_id' => $variant->id,
            'quantity' => 1,
            'unit_price' => '100000.00',
        ]);
        $variant->update(['is_active' => false]);

        $this->put(route('cart.update', $variantItem), ['quantity' => 1])->assertSessionHasErrors('product_variant_id');
        $this->assertSame(1, (int) $variantItem->refresh()->quantity);
    }

    public function test_cart_uses_current_product_and_variant_prices_and_decimal_safe_line_totals(): void
    {
        $user = User::factory()->create();
        $product = $this->product(['price' => '100000.00']);
        $cart = $this->userCart($user, $product, 2, '1.00');
        $product->update(['price' => '125000.50']);

        $variantProduct = $this->product();
        $variant = $this->variant($variantProduct, ['price' => '200000.25']);
        $cart->items()->create([
            'product_id' => $variantProduct->id,
            'product_variant_id' => $variant->id,
            'quantity' => 2,
            'unit_price' => '2.00',
        ]);
        $variant->update(['price' => '210000.25']);

        $this->actingAs($user)->get(route('cart.index'))
            ->assertOk()
            ->assertSeeText('125.000,50đ')
            ->assertSeeText('250.001đ')
            ->assertSeeText('210.000,25đ')
            ->assertSeeText('420.000,50đ')
            ->assertSeeText('670.001,50đ');
    }

    public function test_checkout_preview_and_order_use_same_current_server_price(): void
    {
        $user = User::factory()->create();
        $product = $this->product(['price' => '125000.00']);
        $this->userCart($user, $product, 2, '1.00');
        $shipping = $this->shippingMethod(['base_fee' => '0.00']);

        $this->actingAs($user)->get(route('checkout.index'))
            ->assertOk()
            ->assertSeeText('250.000đ');

        $this->post(route('checkout.store'), $this->checkoutPayload($shipping->id))
            ->assertRedirect();

        $order = Order::query()->firstOrFail();
        $this->assertSame('250000.00', (string) $order->subtotal);
        $this->assertDatabaseHas('order_items', ['order_id' => $order->id, 'unit_price' => '125000.00', 'total_price' => '250000.00']);
        $this->assertSame(8, (int) $product->refresh()->stock);
    }

    public function test_direct_checkout_requires_review_when_legacy_cart_merge_adjusts_quantity(): void
    {
        $user = User::factory()->create();
        $product = $this->product(['stock' => 3]);
        $this->userCart($user, $product, 2);
        $this->userCart($user, $product, 2);
        $shipping = $this->shippingMethod();

        $response = $this->actingAs($user)->post(
            route('checkout.store'),
            $this->checkoutPayload($shipping->id)
        );

        $response->assertRedirect(route('cart.index'));
        $response->assertSessionHas('error');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('carts', 1);
        $this->assertDatabaseHas('cart_items', [
            'product_id' => $product->id,
            'quantity' => 3,
        ]);
        $this->assertSame(3, (int) $product->refresh()->stock);
    }

    public function test_inactive_and_excess_quantity_items_render_safely_and_can_be_removed(): void
    {
        $user = User::factory()->create();
        $inactive = $this->product(['name' => 'Inactive item', 'is_active' => false]);
        $cart = $this->userCart($user, $inactive, 1);
        $stale = $this->product(['name' => 'Stale quantity', 'stock' => 1]);
        $staleItem = $cart->items()->create(['product_id' => $stale->id, 'quantity' => 3, 'unit_price' => '100000.00']);
        $inactiveItem = $cart->items()->where('product_id', $inactive->id)->firstOrFail();

        $this->actingAs($user)->get(route('cart.index'))
            ->assertOk()
            ->assertSeeText('Sản phẩm không còn khả dụng.')
            ->assertSeeText('Số lượng hiện có đã thay đổi (còn 1).');

        $this->delete(route('cart.remove', $inactiveItem))->assertRedirect(route('cart.index'));
        $this->delete(route('cart.remove', $staleItem))->assertRedirect(route('cart.index'));
        $this->assertDatabaseCount('cart_items', 0);
        $this->assertSame(1, (int) $stale->refresh()->stock);
    }

    public function test_nullified_variant_is_not_silently_checked_out_as_parent_product(): void
    {
        $user = User::factory()->create();
        $product = $this->product(['stock' => 20]);
        $deletedVariant = $this->variant($product, ['stock' => 2]);
        $this->variant($product, ['stock' => 3]);
        $cart = Cart::create(['user_id' => $user->id, 'session_id' => 'legacy']);
        $item = $cart->items()->create([
            'product_id' => $product->id,
            'product_variant_id' => $deletedVariant->id,
            'quantity' => 1,
            'unit_price' => '100000.00',
        ]);
        $shipping = $this->shippingMethod();
        $deletedVariant->delete();
        $this->assertNull($item->refresh()->product_variant_id);

        $this->actingAs($user)->get(route('cart.index'))
            ->assertOk()
            ->assertSeeText('Sản phẩm này cần chọn lại phiên bản.');

        $this->post(route('checkout.store'), $this->checkoutPayload($shipping->id))
            ->assertSessionHasErrors('checkout');
        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(20, (int) $product->refresh()->stock);
    }

    public function test_product_detail_initializes_quantity_and_price_from_selected_variant_not_max_variant_stock(): void
    {
        $product = $this->product(['price' => '90000.00']);
        $selected = $this->variant($product, ['size' => 'S', 'price' => '150000.00', 'stock' => 2]);
        $this->variant($product, ['size' => 'L', 'price' => '175000.00', 'stock' => 8]);
        $soldOut = $this->variant($product, ['size' => 'XL', 'price' => '180000.00', 'stock' => 0]);

        $this->get(route('product.show', $product->slug))
            ->assertOk()
            ->assertSeeText('150.000đ')
            ->assertSee('value="'.$selected->id.'"', false)
            ->assertSee('data-stock="2"', false)
            ->assertSee('id="quantity" type="number" name="quantity" value="1" min="1" max="2"', false)
            ->assertSee('value="'.$soldOut->id.'"', false)
            ->assertSeeText('XL — Hết hàng')
            ->assertSee('quantity.max = String(Math.max(1, stock))', false);
    }

    public function test_foreign_user_cart_item_cannot_be_updated_or_removed_even_with_stale_session_pointer(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();
        $product = $this->product();
        $foreignCart = $this->userCart($owner, $product, 1);
        $item = $foreignCart->items()->firstOrFail();

        $this->actingAs($attacker)->withSession(['cart_id' => $foreignCart->id]);
        $this->put(route('cart.update', $item), ['quantity' => 2])->assertNotFound();
        $this->delete(route('cart.remove', $item))->assertNotFound();

        $this->assertDatabaseHas('cart_items', ['id' => $item->id, 'quantity' => 1]);
        $this->assertDatabaseHas('carts', ['id' => $foreignCart->id, 'user_id' => $owner->id]);
    }

    private function product(array $attributes = []): Product
    {
        $id = Str::lower(Str::random(10));

        return Product::create(array_merge([
            'name' => 'Cart product '.$id,
            'slug' => 'cart-product-'.$id,
            'sku' => 'CART-'.Str::upper($id),
            'price' => '100000.00',
            'sale_price' => null,
            'is_active' => true,
            'stock' => 10,
        ], $attributes));
    }

    private function variant(Product $product, array $attributes = []): ProductVariant
    {
        $id = Str::upper(Str::random(10));

        return ProductVariant::create(array_merge([
            'product_id' => $product->id,
            'sku' => 'VAR-'.$id,
            'size' => 'M',
            'price' => '100000.00',
            'sale_price' => null,
            'stock' => 5,
            'is_active' => true,
        ], $attributes));
    }

    private function userCart(
        User $user,
        Product $product,
        int $quantity,
        string $unitPrice = '100000.00'
    ): Cart {
        $cart = Cart::create([
            'user_id' => $user->id,
            'session_id' => 'user-session-'.Str::random(8),
        ]);
        $cart->items()->create([
            'product_id' => $product->id,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
        ]);

        return $cart;
    }

    private function shippingMethod(array $attributes = []): ShippingMethod
    {
        return ShippingMethod::create(array_merge([
            'name' => 'Standard',
            'code' => 'standard-'.Str::lower(Str::random(8)),
            'base_fee' => '30000.00',
            'fee_per_km' => '0.00',
            'estimated_days_min' => 2,
            'estimated_days_max' => 4,
            'is_active' => true,
        ], $attributes));
    }

    private function checkoutPayload(int $shippingMethodId): array
    {
        return [
            'customer_name' => 'Cart Customer',
            'customer_phone' => '0900000000',
            'customer_email' => 'cart@example.com',
            'province' => 'Ha Noi',
            'district' => 'Hoan Kiem',
            'ward' => 'Trang Tien',
            'address_line' => '1 Test Street',
            'shipping_method_id' => $shippingMethodId,
            'payment_method' => 'cod',
            'checkout_token' => (string) Str::uuid(),
        ];
    }
}
