<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Material;
use App\Models\Product;
use App\Models\User;
use App\Models\Wishlist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WishlistIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = Category::create([
            'name' => 'Nhẫn bạc',
            'slug' => 'nhan-bac',
            'is_active' => true,
        ]);

        $this->material = Material::create([
            'name' => 'Bạc 925',
            'code' => 'bac-925',
            'purity' => '925',
            'is_active' => true,
        ]);

        $this->product = Product::create([
            'category_id' => $this->category->id,
            'material_id' => $this->material->id,
            'name' => 'Nhẫn bạc đẹp',
            'slug' => 'nhan-bac-dep',
            'short_description' => 'Nhẫn bạc',
            'description' => 'Thiết kế đẹp',
            'price' => 1200000,
            'sale_price' => 990000,
            'sku' => 'SKU-WISH-001',
            'featured_image' => 'https://example.com/image.jpg',
            'featured' => true,
            'is_active' => true,
            'stock' => 10,
            'views' => 0,
            'average_rating' => 5,
        ]);

        $this->user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);
    }

    public function test_authenticated_user_can_add_product_to_wishlist(): void
    {
        $this->actingAs($this->user);

        $response = $this->post(route('wishlist.add'), [
            'product_id' => $this->product->id,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('wishlists', [
            'user_id' => $this->user->id,
            'product_id' => $this->product->id,
        ]);
    }

    public function test_duplicate_wishlist_item_not_created(): void
    {
        $this->actingAs($this->user);

        $this->post(route('wishlist.add'), [
            'product_id' => $this->product->id,
        ]);

        $count = Wishlist::where('user_id', $this->user->id)
            ->where('product_id', $this->product->id)
            ->count();

        $this->assertEquals(1, $count);

        $this->post(route('wishlist.add'), [
            'product_id' => $this->product->id,
        ]);

        $count = Wishlist::where('user_id', $this->user->id)
            ->where('product_id', $this->product->id)
            ->count();

        $this->assertEquals(1, $count);
    }

    public function test_authenticated_user_can_remove_product_from_wishlist(): void
    {
        $this->actingAs($this->user);

        Wishlist::create([
            'user_id' => $this->user->id,
            'product_id' => $this->product->id,
        ]);

        $this->assertDatabaseHas('wishlists', [
            'user_id' => $this->user->id,
            'product_id' => $this->product->id,
        ]);

        $response = $this->delete(route('wishlist.remove', $this->product->id));

        $response->assertRedirect();
        $this->assertDatabaseMissing('wishlists', [
            'user_id' => $this->user->id,
            'product_id' => $this->product->id,
        ]);
    }

    public function test_wishlist_returns_only_user_items(): void
    {
        $otherUser = User::create([
            'name' => 'Other User',
            'email' => 'other@example.com',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);

        Wishlist::create([
            'user_id' => $this->user->id,
            'product_id' => $this->product->id,
        ]);

        Wishlist::create([
            'user_id' => $otherUser->id,
            'product_id' => $this->product->id,
        ]);

        $this->actingAs($this->user);
        $response = $this->get(route('wishlist.index'));

        $response->assertOk();
        $this->assertEquals(1, $this->user->wishlist()->count());
    }

    public function test_product_displayed_on_wishlist_page(): void
    {
        $this->actingAs($this->user);

        Wishlist::create([
            'user_id' => $this->user->id,
            'product_id' => $this->product->id,
        ]);

        $response = $this->get(route('wishlist.index'));

        $response->assertOk();
        $response->assertSeeText($this->product->name);
        $response->assertSeeText(number_format(990000, 0, ',', '.'));
    }

    public function test_empty_wishlist_shows_proper_state(): void
    {
        $this->actingAs($this->user);

        $response = $this->get(route('wishlist.index'));

        $response->assertOk();
        $response->assertSeeText('Danh sách yêu thích của bạn trống');
    }

    public function test_guest_sees_login_prompt_on_wishlist_page(): void
    {
        $response = $this->get(route('wishlist.index'));

        $response->assertOk();
        $response->assertSeeText('Vui lòng đăng nhập');
    }

    public function test_guest_redirected_with_error_when_adding_to_wishlist(): void
    {
        $response = $this->post(route('wishlist.add'), [
            'product_id' => $this->product->id,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');
    }

    public function test_nonexistent_product_validation(): void
    {
        $this->actingAs($this->user);

        $response = $this->post(route('wishlist.add'), [
            'product_id' => 99999,
        ]);

        $response->assertSessionHasErrors();
    }

    public function test_wishlist_check_endpoint_returns_correct_status(): void
    {
        $this->actingAs($this->user);

        $response = $this->get(route('wishlist.check', $this->product->id));

        $response->assertJson(['in_wishlist' => false]);

        Wishlist::create([
            'user_id' => $this->user->id,
            'product_id' => $this->product->id,
        ]);

        $response = $this->get(route('wishlist.check', $this->product->id));

        $response->assertJson(['in_wishlist' => true]);
    }
}
