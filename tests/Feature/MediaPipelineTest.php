<?php

namespace Tests\Feature;

use App\Models\Banner;
use App\Models\BlogPost;
use App\Models\Category;
use App\Models\Collection;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Role;
use App\Models\User;
use App\Support\MediaUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaPipelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_media_url_resolver_supports_public_disk_and_legacy_references(): void
    {
        $this->assertSame(Storage::disk('public')->url('banners/example.jpg'), MediaUrl::resolve('banners/example.jpg'));
        $this->assertSame('/storage/banners/example.jpg', MediaUrl::resolve('storage/banners/example.jpg'));
        $this->assertSame('/storage/banners/example.jpg', MediaUrl::resolve('/storage/banners/example.jpg'));
        $this->assertSame('https://example.com/image.jpg', MediaUrl::resolve('https://example.com/image.jpg'));
        $this->assertNull(MediaUrl::resolve('//example.com/image.jpg'));
        $this->assertNull(MediaUrl::resolve('javascript:alert(1)'));
        $this->assertNull(MediaUrl::resolve(null));
    }

    public function test_admin_stores_product_featured_and_gallery_uploads_as_relative_public_paths(): void
    {
        Storage::fake('public');
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.products.store'), [
            'name' => 'Silver Ring',
            'slug' => 'silver-ring-upload',
            'sku' => 'SILVER-RING-UPLOAD',
            'price' => '500000',
            'stock' => 3,
            'is_active' => 1,
            'featured_image_upload' => $this->imageFile('cover.jpg'),
        ])->assertRedirect(route('admin.products.index'));

        $product = Product::query()->where('slug', 'silver-ring-upload')->firstOrFail();
        $this->assertStringStartsWith('products/'.$product->id.'/', $product->featured_image);
        $this->assertStringNotContainsString('\\', $product->featured_image);
        Storage::disk('public')->assertExists($product->featured_image);

        $this->actingAs($admin)->post(route('admin.products.images.store', $product), [
            'image_upload' => $this->imageFile('gallery.webp'),
            'alt_text' => 'Silver ring side',
            'is_primary' => 1,
        ])->assertRedirect();

        $image = ProductImage::query()->where('product_id', $product->id)->firstOrFail();
        $this->assertStringStartsWith('products/'.$product->id.'/gallery/', $image->image_path);
        Storage::disk('public')->assertExists($image->image_path);
    }

    public function test_uploaded_replacement_keeps_existing_file_intact(): void
    {
        Storage::fake('public');
        $admin = $this->admin();
        $product = Product::create([
            'name' => 'Existing Ring',
            'slug' => 'existing-ring-upload',
            'sku' => 'EXISTING-RING-UPLOAD',
            'price' => '500000.00',
            'stock' => 2,
            'is_active' => true,
            'featured_image' => 'products/99/old.jpg',
        ]);
        Storage::disk('public')->put($product->featured_image, 'previous-media');

        $this->actingAs($admin)->put(route('admin.products.update', $product), [
            'name' => $product->name,
            'slug' => $product->slug,
            'sku' => $product->sku,
            'price' => '500000',
            'stock' => 2,
            'is_active' => 1,
            'featured_image_upload' => $this->imageFile('replacement.png'),
        ])->assertRedirect(route('admin.products.index'));

        $newPath = $product->fresh()->featured_image;
        $this->assertNotSame('products/99/old.jpg', $newPath);
        Storage::disk('public')->assertExists('products/99/old.jpg');
        Storage::disk('public')->assertExists($newPath);
    }

    public function test_admin_stores_banner_category_collection_and_blog_uploads_as_relative_public_paths(): void
    {
        Storage::fake('public');
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.banners.store'), [
            'title' => 'New Banner',
            'position' => 'home',
            'is_active' => 1,
            'image_upload' => $this->imageFile('banner.jpg'),
        ])->assertRedirect(route('admin.banners.index'));
        $banner = Banner::firstOrFail();
        $this->assertStringStartsWith('banners/', $banner->image);
        Storage::disk('public')->assertExists($banner->image);

        $this->actingAs($admin)->post(route('admin.categories.store'), [
            'name' => 'Rings',
            'slug' => 'rings-upload',
            'is_active' => 1,
            'image_upload' => $this->imageFile('category.png'),
        ])->assertRedirect(route('admin.categories.index'));
        $category = Category::query()->where('slug', 'rings-upload')->firstOrFail();
        $this->assertStringStartsWith('categories/', $category->image);
        Storage::disk('public')->assertExists($category->image);

        $this->actingAs($admin)->post(route('admin.collections.store'), [
            'name' => 'Aurora',
            'slug' => 'aurora-upload',
            'is_active' => 1,
            'image_upload' => $this->imageFile('collection.webp'),
        ])->assertRedirect(route('admin.collections.index'));
        $collection = Collection::query()->where('slug', 'aurora-upload')->firstOrFail();
        $this->assertStringStartsWith('collections/', $collection->image);
        Storage::disk('public')->assertExists($collection->image);

        $this->actingAs($admin)->post(route('admin.blog-posts.store'), [
            'title' => 'Jewelry Journal',
            'slug' => 'jewelry-journal-upload',
            'content' => 'Content',
            'is_published' => 1,
            'featured_image_upload' => $this->imageFile('journal.jpg'),
        ])->assertRedirect(route('admin.blog-posts.index'));
        $post = BlogPost::query()->where('slug', 'jewelry-journal-upload')->firstOrFail();
        $this->assertStringStartsWith('blog/', $post->featured_image);
        Storage::disk('public')->assertExists($post->featured_image);
    }

    public function test_non_image_upload_is_rejected(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin())->post(route('admin.banners.store'), [
            'title' => 'Invalid Banner',
            'position' => 'home',
            'is_active' => 1,
            'image_upload' => UploadedFile::fake()->create('payload.php', 10, 'application/x-httpd-php'),
        ])->assertSessionHasErrors('image_upload');

        $this->assertDatabaseCount('banners', 0);
    }

    private function admin(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::create(['name' => 'admin', 'guard_name' => 'web']));

        return $user;
    }

    private function imageFile(string $name): UploadedFile
    {
        return UploadedFile::fake()->create($name, 10, 'image/jpeg');
    }
}
