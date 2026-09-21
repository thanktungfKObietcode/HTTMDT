<?php

namespace Tests\Feature;

use App\Models\Banner;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\ContactMessage;
use App\Models\Collection;
use App\Models\NewsletterSubscriber;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\Showroom;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhaseSevenFastTrackTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_manages_product_gallery_and_specifications_without_cross_product_idor(): void
    {
        $admin = $this->admin();
        $product = $this->product('gallery-product');
        $other = $this->product('other-product');

        $this->actingAs($admin)->post(route('admin.products.images.store', $product), [
            'image_path' => '/images/ring-front.jpg', 'alt_text' => 'Mặt trước nhẫn', 'is_primary' => 1, 'sort_order' => 2,
        ])->assertRedirect();
        $image = ProductImage::firstOrFail();
        $this->assertTrue($image->is_primary);
        $this->actingAs($admin)->post(route('admin.products.images.store', $product), [
            'image_path' => '/images/ring-side.jpg', 'alt_text' => 'Cạnh nhẫn', 'sort_order' => 1,
        ])->assertRedirect();
        $secondImage = ProductImage::query()->where('image_path', '/images/ring-side.jpg')->firstOrFail();
        $this->actingAs($admin)->put(route('admin.products.images.update', [$product, $secondImage]), [
            'alt_text' => 'Cạnh nhẫn mới', 'sort_order' => 1, 'is_primary' => 1, 'is_active' => 1,
        ])->assertRedirect();
        $this->assertFalse($image->fresh()->is_primary);
        $this->assertTrue($secondImage->fresh()->is_primary);

        $this->actingAs($admin)->post(route('admin.products.specifications.store', $product), [
            'label' => 'Độ tinh khiết', 'value' => 'Bạc 925', 'sort_order' => 1,
        ])->assertRedirect();
        $this->assertDatabaseHas('product_specifications', ['product_id' => $product->id, 'label' => 'Độ tinh khiết']);
        $specification = $product->specifications()->firstOrFail();
        $this->actingAs($admin)->put(route('admin.products.specifications.update', [$product, $specification]), [
            'label' => 'Chất liệu', 'value' => 'Bạc 925', 'sort_order' => 2, 'is_active' => 1,
        ])->assertRedirect();
        $this->assertDatabaseHas('product_specifications', ['id' => $specification->id, 'label' => 'Chất liệu', 'sort_order' => 2]);

        $this->actingAs($admin)->put(route('admin.products.images.update', [$other, $image]), [
            'alt_text' => 'IDOR', 'sort_order' => 0,
        ])->assertNotFound();
        $this->actingAs($admin)->put(route('admin.products.specifications.update', [$other, $specification]), [
            'label' => 'IDOR', 'value' => 'IDOR', 'sort_order' => 0,
        ])->assertNotFound();
        $this->actingAs($admin)->delete(route('admin.products.images.destroy', [$product, $secondImage]))->assertRedirect();
        $this->assertTrue($image->fresh()->is_primary);
        $this->actingAs($admin)->delete(route('admin.products.images.destroy', [$product, $image]))->assertRedirect();
        $this->assertDatabaseHas('product_images', ['id' => $image->id, 'is_active' => false]);
        $this->actingAs($admin)->delete(route('admin.products.specifications.destroy', [$product, $specification]))->assertRedirect();
        $this->assertDatabaseHas('product_specifications', ['id' => $specification->id, 'is_active' => false]);
    }

    public function test_product_merchandising_and_active_media_render_on_storefront(): void
    {
        $product = $this->product('silver-ring', ['is_new_arrival' => true, 'is_bestseller' => true]);
        $product->images()->create(['image_path' => '/images/active.jpg', 'alt_text' => 'Nhẫn bạc thủ công', 'is_primary' => true, 'is_active' => true]);
        ProductImage::create(['product_id' => $product->id, 'image_path' => '/images/hidden.jpg', 'is_active' => false]);
        $product->specifications()->create(['label' => 'Trọng lượng', 'value' => '3.2 gram', 'sort_order' => 1]);

        $this->get(route('product.show', $product->slug))->assertOk()
            ->assertSee('/images/active.jpg')->assertDontSee('/images/hidden.jpg')
            ->assertSee('alt="Nhẫn bạc thủ công"', false)->assertSeeText('Trọng lượng: 3.2 gram')
            ->assertSeeText('Hàng mới')->assertSee('name="product_id"', false);
        $this->get(route('products.index'))->assertOk()->assertSee('/images/active.jpg')->assertSeeText('Còn hàng');
    }

    public function test_product_media_rejects_executable_url_schemes(): void
    {
        $this->actingAs($this->admin())->post(route('admin.products.images.store', $this->product('unsafe-image')), [
            'image_path' => '  javascript:alert(1)',
        ])->assertSessionHasErrors('image_path');
    }

    public function test_admin_can_manage_blog_categories_posts_and_publication_schedule(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('admin.blog-categories.store'), ['name' => 'Cẩm nang', 'slug' => 'cam-nang'])->assertRedirect();
        $category = BlogCategory::firstOrFail();
        $this->actingAs($admin)->post(route('admin.blog-posts.store'), [
            'blog_category_id' => $category->id, 'title' => 'Chọn nhẫn bạc', 'slug' => 'chon-nhan-bac',
            'content' => 'Nội dung tư vấn', 'is_published' => 1, 'published_at' => now()->subMinute()->format('Y-m-d H:i:s'),
        ])->assertRedirect(route('admin.blog-posts.index'));
        $this->get(route('blog.show', 'chon-nhan-bac'))->assertOk()->assertSeeText('Nội dung tư vấn');

        $this->actingAs($admin)->put(route('admin.blog-categories.update', $category), [
            'name' => $category->name, 'slug' => $category->slug, 'is_active' => 0,
        ])->assertRedirect();
        $this->get(route('blog.show', 'chon-nhan-bac'))->assertNotFound();

        $future = BlogPost::create(['title' => 'Future', 'slug' => 'future-post', 'content' => 'Later', 'is_published' => true, 'published_at' => now()->addDay()]);
        $this->get(route('blog.show', $future->slug))->assertNotFound();
    }

    public function test_admin_manages_scheduled_banners_and_showrooms_non_destructively(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('admin.banners.store'), [
            'title' => 'Autumn Silver', 'image' => '/banners/autumn.jpg', 'position' => 'home',
            'is_active' => 1, 'starts_at' => now()->subHour(), 'ends_at' => now()->addHour(),
        ])->assertRedirect(route('admin.banners.index'));
        $this->get(route('home'))->assertOk()->assertSeeText('Autumn Silver');
        Banner::create(['title' => 'Future Banner', 'image' => '/future.jpg', 'position' => 'home', 'is_active' => true, 'starts_at' => now()->addDay()]);
        $this->get(route('home'))->assertDontSeeText('Future Banner');
        $banner = Banner::firstOrFail();
        $this->actingAs($admin)->delete(route('admin.banners.destroy', $banner))->assertRedirect();
        $this->assertDatabaseHas('banners', ['id' => $banner->id, 'is_active' => false]);

        $this->actingAs($admin)->post(route('admin.showrooms.store'), [
            'name' => 'Silver Atelier Central', 'city' => 'Hà Nội', 'address' => '1 Phố Bạc',
            'business_hours_text' => '09:00–20:00', 'is_active' => 1,
        ])->assertRedirect(route('admin.showrooms.index'));
        $showroom = Showroom::firstOrFail();
        $this->get(route('showrooms'))->assertOk()->assertSeeText('09:00–20:00');
        $this->actingAs($admin)->delete(route('admin.showrooms.destroy', $showroom))->assertRedirect();
        $this->assertDatabaseHas('showrooms', ['id' => $showroom->id, 'is_active' => false]);
    }

    public function test_contact_and_newsletter_admin_workflows_preserve_records(): void
    {
        $admin = $this->admin();
        $message = ContactMessage::create(['name' => 'Lan', 'email' => 'lan@example.com', 'message' => 'Tư vấn nhẫn', 'status' => 'new']);
        $subscriber = NewsletterSubscriber::create(['email' => 'news@example.com', 'is_active' => true]);

        $this->actingAs($admin)->put(route('admin.contact-messages.update', $message), ['status' => 'resolved'])->assertRedirect();
        $this->actingAs($admin)->put(route('admin.newsletter-subscribers.update', $subscriber), ['is_active' => 0])->assertRedirect();
        $this->assertDatabaseHas('contact_messages', ['id' => $message->id, 'status' => 'resolved']);
        $this->assertDatabaseHas('newsletter_subscribers', ['id' => $subscriber->id, 'is_active' => false]);
    }

    public function test_admin_cms_forms_render_and_existing_content_can_be_updated(): void
    {
        $admin = $this->admin();
        $category = BlogCategory::create(['name' => 'Kiến thức', 'slug' => 'kien-thuc', 'is_active' => true]);
        $post = BlogPost::create(['blog_category_id' => $category->id, 'title' => 'Bài cũ', 'slug' => 'bai-cu', 'content' => 'Nội dung', 'is_published' => true]);
        $banner = Banner::create(['title' => 'Banner cũ', 'image' => '/old.jpg', 'position' => 'home', 'is_active' => true]);
        $showroom = Showroom::create(['name' => 'Cửa hàng cũ', 'city' => 'Huế', 'address' => 'Địa chỉ cũ', 'is_active' => true]);

        $this->actingAs($admin)->get(route('admin.blog-posts.create'))->assertOk();
        $this->actingAs($admin)->get(route('admin.blog-posts.edit', $post))->assertOk();
        $this->actingAs($admin)->get(route('admin.banners.edit', $banner))->assertOk();
        $this->actingAs($admin)->get(route('admin.showrooms.edit', $showroom))->assertOk();

        $this->actingAs($admin)->put(route('admin.blog-posts.update', $post), [
            'blog_category_id' => $category->id, 'title' => 'Bài mới', 'slug' => 'bai-moi',
            'content' => 'Nội dung mới', 'is_published' => 0,
        ])->assertRedirect(route('admin.blog-posts.index'));
        $this->actingAs($admin)->put(route('admin.banners.update', $banner), [
            'title' => 'Banner mới', 'image' => 'https://example.com/banner.jpg', 'position' => 'catalog',
            'sort_order' => 3, 'is_active' => 1,
        ])->assertRedirect(route('admin.banners.index'));
        $this->actingAs($admin)->put(route('admin.showrooms.update', $showroom), [
            'name' => 'Cửa hàng mới', 'city' => 'Huế', 'address' => 'Địa chỉ mới', 'is_active' => 1,
        ])->assertRedirect(route('admin.showrooms.index'));

        $this->assertDatabaseHas('blog_posts', ['id' => $post->id, 'slug' => 'bai-moi', 'is_published' => false]);
        $this->assertDatabaseHas('banners', ['id' => $banner->id, 'position' => 'catalog', 'sort_order' => 3]);
        $this->assertDatabaseHas('showrooms', ['id' => $showroom->id, 'address' => 'Địa chỉ mới']);
    }

    public function test_content_management_requires_explicit_staff_permission_and_admin_bypasses_it(): void
    {
        $admin = $this->admin();
        $staff = $this->staff();
        $this->actingAs($admin)->get(route('admin.blog-posts.index'))->assertOk();
        $this->actingAs($staff)->get(route('admin.blog-posts.index'))->assertForbidden();
        $this->actingAs($staff)->post(route('admin.banners.store'), [])->assertForbidden();

        $permission = Permission::firstOrCreate(['name' => 'content.manage'], ['guard_name' => 'web']);
        $staff->roles()->firstOrFail()->permissions()->attach($permission);
        $staff->unsetRelation('roles');
        $this->actingAs($staff)->get(route('admin.blog-posts.index'))->assertOk();
        $this->actingAs($staff)->get(route('admin.products.index'))->assertForbidden();
    }

    public function test_catalog_keeps_variant_selection_authoritative_without_quick_add(): void
    {
        $product = $this->product('sized-ring', ['stock' => 99]);
        ProductVariant::create([
            'product_id' => $product->id, 'sku' => 'SIZED-RING-12', 'size' => '12',
            'price' => '550000.00', 'stock' => 2, 'is_active' => true,
        ]);

        $this->get(route('products.index'))->assertOk()
            ->assertSeeText('Còn hàng')->assertDontSee('action="'.route('cart.add').'"', false);
        $this->get(route('product.show', $product->slug))->assertOk()
            ->assertSee('name="product_variant_id"', false)->assertSee('data-stock="2"', false);
    }

    public function test_homepage_does_not_render_the_removed_legacy_collection_block(): void
    {
        $collection = Collection::create(['name' => 'Aurora', 'slug' => 'aurora', 'description' => 'Bộ sưu tập ánh sáng', 'image' => '/collections/aurora.jpg', 'is_active' => true]);
        $product = $this->product('aurora-ring', ['featured' => true]);
        $collection->products()->attach($product);

        $this->get(route('home'))->assertOk()
            ->assertSeeText('A LOVE STORY THAT STAYS')
            ->assertDontSeeText('Aurora')
            ->assertDontSee('/collections/aurora.jpg')
            ->assertDontSee('collection=aurora');
    }

    private function admin(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::create(['name' => 'admin', 'guard_name' => 'web']));
        return $user;
    }

    private function staff(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::create(['name' => 'staff', 'guard_name' => 'web']));
        return $user;
    }

    private function product(string $slug, array $attributes = []): Product
    {
        return Product::create([...[
            'name' => str_replace('-', ' ', $slug), 'slug' => $slug, 'sku' => strtoupper($slug),
            'price' => '500000.00', 'stock' => 3, 'is_active' => true,
        ], ...$attributes]);
    }
}
