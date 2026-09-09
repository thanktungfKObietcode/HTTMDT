<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\Category;
use App\Models\Material;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CartIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_add_product_to_cart_and_view_it(): void
    {
        $category = Category::create([
            'name' => 'Nhẫn bạc',
            'slug' => 'nhan-bac',
            'is_active' => true,
        ]);

        $material = Material::create([
            'name' => 'Bạc 925',
            'code' => 'bac-925',
            'purity' => '925',
            'is_active' => true,
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'material_id' => $material->id,
            'name' => 'Nhẫn bạc mạ vàng',
            'slug' => 'nhan-bac-ma-vang',
            'short_description' => 'Nhẫn bạc hiện đại',
            'description' => 'Thiết kế đẹp',
            'price' => 1200000,
            'sale_price' => 990000,
            'sku' => 'SKU-CART-001',
            'featured_image' => 'https://example.com/image.jpg',
            'featured' => true,
            'is_active' => true,
            'stock' => 10,
            'views' => 0,
            'average_rating' => 5,
        ]);

        $response = $this->post(route('cart.add'), [
            'product_id' => $product->id,
            'quantity' => 2,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('carts', ['session_id' => session()->getId()]);
        $cart = Cart::where('session_id', session()->getId())->firstOrFail();
        $this->assertDatabaseHas('cart_items', [
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 2,
        ]);

        $page = $this->get(route('cart.index'));
        $page->assertOk();
        $page->assertSeeText('Nhẫn bạc mạ vàng');
        $page->assertSeeText('1.980.000đ');
    }

    public function test_guest_can_update_and_remove_cart_item(): void
    {
        $category = Category::create([
            'name' => 'Dây chuyền',
            'slug' => 'day-chuyen',
            'is_active' => true,
        ]);

        $material = Material::create([
            'name' => 'Bạc 950',
            'code' => 'bac-950',
            'purity' => '950',
            'is_active' => true,
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'material_id' => $material->id,
            'name' => 'Dây chuyền bạc',
            'slug' => 'day-chuyen-bac',
            'short_description' => 'Dây chuyền bạc',
            'description' => 'Đẹp',
            'price' => 800000,
            'sale_price' => null,
            'sku' => 'SKU-CART-002',
            'featured_image' => 'https://example.com/chain.jpg',
            'featured' => false,
            'is_active' => true,
            'stock' => 7,
            'views' => 0,
            'average_rating' => 4,
        ]);

        $cart = Cart::create(['session_id' => session()->getId()]);
        session()->put('cart_id', $cart->id);
        session()->put('cart_guest_session_id', $cart->session_id);
        $item = $cart->items()->create([
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => 800000,
        ]);

        $this->put(route('cart.update', $item->id), [
            'quantity' => 3,
        ])->assertRedirect(route('cart.index'));

        $item->refresh();
        $this->assertDatabaseHas('cart_items', [
            'id' => $item->id,
            'quantity' => 3,
        ]);

        $this->delete(route('cart.remove', $item->id))->assertRedirect(route('cart.index'));
        $this->assertDatabaseMissing('cart_items', ['id' => $item->id]);
    }

    public function test_cart_item_variant_is_saved_with_variant_price(): void
    {
        $category = Category::create(['name' => 'Nhẫn', 'slug' => 'nhan-variant', 'is_active' => true]);
        $material = Material::create(['name' => 'Bạc', 'code' => 'bac-variant', 'is_active' => true]);
        $product = Product::create([
            'category_id' => $category->id,
            'material_id' => $material->id,
            'name' => 'Nhẫn có phiên bản',
            'slug' => 'nhan-co-phien-ban',
            'sku' => 'SKU-VARIANT-001',
            'price' => 100000,
            'is_active' => true,
            'stock' => 10,
        ]);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'SKU-VARIANT-001-S',
            'size' => 'S',
            'price' => 150000,
            'stock' => 4,
            'is_active' => true,
        ]);

        $this->post(route('cart.add'), [
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity' => 2,
        ])->assertRedirect(route('cart.index'));

        $this->assertDatabaseHas('cart_items', [
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity' => 2,
            'unit_price' => 150000,
        ]);
    }

    public function test_user_cannot_update_or_remove_another_users_cart_item(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();
        $cart = Cart::create(['user_id' => $owner->id, 'session_id' => 'owner-session']);
        $product = Product::create([
            'name' => 'Sản phẩm ownership',
            'slug' => 'san-pham-ownership',
            'sku' => 'SKU-OWNERSHIP-001',
            'price' => 100000,
            'is_active' => true,
            'stock' => 10,
        ]);
        $item = $cart->items()->create([
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => 100000,
        ]);

        $this->actingAs($attacker)->put(route('cart.update', $item), ['quantity' => 3])->assertNotFound();
        $this->actingAs($attacker)->delete(route('cart.remove', $item))->assertNotFound();
        $this->assertDatabaseHas('cart_items', ['id' => $item->id, 'quantity' => 1]);
    }
}
