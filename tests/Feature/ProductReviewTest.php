<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Material;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $category = Category::create([
            'name' => 'Nhẫn bạc',
            'slug' => 'nhan-bac-review',
            'is_active' => true,
        ]);

        $material = Material::create([
            'name' => 'Bạc 925',
            'code' => 'silver-925-review',
            'is_active' => true,
        ]);

        $this->product = Product::create([
            'category_id' => $category->id,
            'material_id' => $material->id,
            'name' => 'Nhẫn bạc review',
            'slug' => 'nhan-bac-review-product',
            'sku' => 'SKU-REVIEW-001',
            'price' => 500000,
            'is_active' => true,
            'stock' => 10,
        ]);

        $this->user = User::factory()->create();
    }

    public function test_delivered_customer_can_create_verified_review(): void
    {
        $this->createDeliveredOrder($this->user, $this->product);

        $response = $this->actingAs($this->user)->post(
            route('product.reviews.store', $this->product),
            ['rating' => 5, 'comment' => 'Sản phẩm rất đẹp.']
        );

        $response->assertRedirect(route('product.show', $this->product->slug));
        $this->assertDatabaseHas('product_reviews', [
            'product_id' => $this->product->id,
            'user_id' => $this->user->id,
            'rating' => 5,
            'comment' => 'Sản phẩm rất đẹp.',
            'is_verified_purchase' => true,
        ]);
    }

    public function test_guest_cannot_create_review(): void
    {
        $response = $this->post(route('product.reviews.store', $this->product), [
            'rating' => 5,
            'comment' => 'Guest review',
        ]);

        $response->assertRedirect(route('login'));
        $this->assertDatabaseCount('product_reviews', 0);
    }

    public function test_rating_is_required_and_must_be_between_one_and_five(): void
    {
        $this->createDeliveredOrder($this->user, $this->product);

        $missingRatingResponse = $this->actingAs($this->user)
            ->from(route('product.show', $this->product->slug))
            ->post(route('product.reviews.store', $this->product), ['comment' => 'Missing rating'])
            ->assertRedirect(route('product.show', $this->product->slug));

        $missingRatingResponse->assertSessionHasErrors('rating');

        $invalidRatingResponse = $this->actingAs($this->user)
            ->from(route('product.show', $this->product->slug))
            ->post(route('product.reviews.store', $this->product), ['rating' => 6])
            ;
        $invalidRatingResponse->assertSessionHasErrors('rating');

        $this->assertDatabaseCount('product_reviews', 0);
    }

    public function test_comment_cannot_exceed_validation_limit(): void
    {
        $this->createDeliveredOrder($this->user, $this->product);

        $response = $this->actingAs($this->user)->post(route('product.reviews.store', $this->product), [
            'rating' => 4,
            'comment' => str_repeat('a', 2001),
        ]);

        $response->assertSessionHasErrors('comment');
        $this->assertDatabaseCount('product_reviews', 0);
    }

    public function test_customer_without_delivered_order_cannot_review(): void
    {
        $response = $this->actingAs($this->user)->post(route('product.reviews.store', $this->product), [
            'rating' => 4,
            'comment' => 'Not eligible',
        ]);

        $response->assertSessionHasErrors('review');
        $this->assertDatabaseCount('product_reviews', 0);
    }

    public function test_order_for_another_product_does_not_grant_review_access(): void
    {
        $otherProduct = Product::create([
            'name' => 'Sản phẩm khác',
            'slug' => 'san-pham-khac-review',
            'sku' => 'SKU-REVIEW-002',
            'price' => 300000,
            'is_active' => true,
            'stock' => 10,
        ]);
        $this->createDeliveredOrder($this->user, $otherProduct);

        $response = $this->actingAs($this->user)->post(route('product.reviews.store', $this->product), [
            'rating' => 4,
        ]);

        $response->assertSessionHasErrors('review');
    }

    public function test_duplicate_review_is_rejected(): void
    {
        $this->createDeliveredOrder($this->user, $this->product);
        ProductReview::create([
            'product_id' => $this->product->id,
            'user_id' => $this->user->id,
            'rating' => 3,
            'comment' => 'Initial review',
            'is_verified_purchase' => true,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->user)->post(route('product.reviews.store', $this->product), [
            'rating' => 5,
            'comment' => 'Duplicate review',
        ]);

        $response->assertSessionHasErrors('review');
        $this->assertDatabaseCount('product_reviews', 1);
    }

    public function test_nonexistent_product_cannot_receive_review(): void
    {
        $this->actingAs($this->user)
            ->post(route('product.reviews.store', ['product' => 999999]), ['rating' => 5])
            ->assertNotFound();
    }

    public function test_review_is_displayed_on_product_detail(): void
    {
        ProductReview::create([
            'product_id' => $this->product->id,
            'user_id' => $this->user->id,
            'rating' => 5,
            'comment' => 'Hiển thị review thật',
            'is_verified_purchase' => true,
            'is_active' => true,
        ]);

        $response = $this->get(route('product.show', $this->product->slug));

        $response->assertOk();
        $response->assertSeeText('Hiển thị review thật');
        $response->assertSeeText('Đã mua hàng');
        $response->assertSeeText($this->user->name);
    }

    private function createDeliveredOrder(User $user, Product $product): Order
    {
        $order = Order::create([
            'user_id' => $user->id,
            'order_number' => 'SA-REVIEW-' . uniqid(),
            'status' => 'delivered',
            'payment_status' => 'paid',
            'customer_name' => $user->name,
            'customer_phone' => '0900000000',
            'shipping_address' => 'Review address',
            'subtotal' => 500000,
            'shipping_fee' => 0,
            'discount_amount' => 0,
            'total_amount' => 500000,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'sku' => $product->sku,
            'quantity' => 1,
            'unit_price' => 500000,
            'total_price' => 500000,
        ]);

        return $order;
    }
}