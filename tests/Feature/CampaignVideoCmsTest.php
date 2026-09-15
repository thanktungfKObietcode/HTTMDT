<?php

namespace Tests\Feature;

use App\Models\Banner;
use App\Models\Category;
use App\Models\Collection;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\User;
use App\Support\VimeoVideo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CampaignVideoCmsTest extends TestCase
{
    use RefreshDatabase;

    public function test_vimeo_video_ids_are_extracted_only_from_safe_https_vimeo_urls(): void
    {
        $this->assertSame('123456789', VimeoVideo::extractId('https://vimeo.com/123456789'));
        $this->assertSame('123456789', VimeoVideo::extractId('https://player.vimeo.com/video/123456789'));
        $this->assertSame('123456789', VimeoVideo::extractId('https://vimeo.com/channels/atelier/123456789'));
        $this->assertSame('https://vimeo.com/123456789', VimeoVideo::canonicalUrl('123456789'));
        $this->assertSame('https://player.vimeo.com/video/123456789?playsinline=1', VimeoVideo::embedUrl('123456789'));

        foreach (['http://vimeo.com/123456789', '//vimeo.com/123456789', 'javascript:alert(1)', 'data:text/html,test', 'https://youtube.com/watch?v=123456789', 'https://vimeo.com/not-a-video'] as $reference) {
            $this->assertNull(VimeoVideo::extractId($reference));
        }
    }

    public function test_admin_can_create_a_scheduled_home_story_campaign_from_a_vimeo_url(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.banners.store'), [
            'title' => 'A LOVE STORY THAT STAYS',
            'image' => 'https://player.vimeo.com/video/123456789',
            'position' => 'home_story',
            'sort_order' => 3,
            'is_active' => 1,
            'starts_at' => now()->subMinute()->format('Y-m-d H:i:s'),
            'ends_at' => now()->addDay()->format('Y-m-d H:i:s'),
        ])->assertRedirect(route('admin.banners.index'));

        $this->assertDatabaseHas('banners', [
            'title' => 'A LOVE STORY THAT STAYS',
            'image' => 'https://vimeo.com/123456789',
            'position' => 'home_story',
            'is_active' => true,
            'sort_order' => 3,
        ]);

        $this->actingAs($admin)->get(route('admin.banners.create'))
            ->assertOk()
            ->assertSeeText('Vimeo video URL')
            ->assertSee('https://vimeo.com/123456789');
    }

    public function test_campaign_video_rejects_non_vimeo_unsafe_and_embed_html_references(): void
    {
        $admin = $this->admin();

        foreach (['https://youtube.com/watch?v=123456789', 'javascript:alert(1)', 'data:text/html,test', '<iframe src="https://player.vimeo.com/video/123456789"></iframe>'] as $reference) {
            $this->actingAs($admin)->post(route('admin.banners.store'), [
                'title' => 'Unsafe campaign',
                'image' => $reference,
                'position' => 'home_story',
                'is_active' => 1,
            ])->assertSessionHasErrors('image');
        }

        $this->assertDatabaseCount('banners', 0);
    }

    public function test_admin_can_create_an_engagement_video_from_a_safe_vimeo_url(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.banners.store'), [
            'title' => 'Engagement story',
            'image' => 'https://player.vimeo.com/video/123456789',
            'position' => 'home_engagement_video',
            'is_active' => 1,
        ])->assertRedirect(route('admin.banners.index'));

        $this->assertDatabaseHas('banners', [
            'image' => 'https://vimeo.com/123456789',
            'position' => 'home_engagement_video',
        ]);

        $this->actingAs($admin)->get(route('admin.banners.create'))
            ->assertOk()
            ->assertSee('home_engagement_video', false)
            ->assertSeeText('Engagement Video');
    }

    public function test_admin_can_create_a_new_arrivals_image_campaign(): void
    {
        Storage::fake('public');
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.banners.store'), [
            'title' => null,
            'position' => 'home_new_arrivals_campaign',
            'is_active' => 1,
            'image_upload' => UploadedFile::fake()->create('new-arrivals.jpg', 10, 'image/jpeg'),
        ])->assertRedirect(route('admin.banners.index'));

        $banner = Banner::firstOrFail();
        $this->assertSame('home_new_arrivals_campaign', $banner->position);
        $this->assertStringStartsWith('banners/', $banner->image);
        Storage::disk('public')->assertExists($banner->image);

        $this->actingAs($admin)->get(route('admin.banners.create'))
            ->assertOk()
            ->assertSee('home_new_arrivals_campaign', false)
            ->assertSeeText('New Arrivals Campaign');
    }

    public function test_new_arrivals_campaign_rejects_vimeo_and_file_video_references(): void
    {
        $admin = $this->admin();

        foreach (['https://vimeo.com/123456789', 'https://example.test/campaign.mp4'] as $reference) {
            $this->actingAs($admin)->post(route('admin.banners.store'), [
                'position' => 'home_new_arrivals_campaign',
                'image' => $reference,
                'is_active' => 1,
            ])->assertSessionHasErrors('image');
        }

        $this->assertDatabaseCount('banners', 0);
    }

    public function test_engagement_video_rejects_unsafe_references_and_image_uploads(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.banners.store'), [
            'image' => 'https://youtube.com/watch?v=123456789',
            'position' => 'home_engagement_video',
            'is_active' => 1,
        ])->assertSessionHasErrors('image');

        $this->actingAs($admin)->post(route('admin.banners.store'), [
            'position' => 'home_engagement_video',
            'is_active' => 1,
            'image_upload' => UploadedFile::fake()->create('engagement.jpg', 10, 'image/jpeg'),
        ])->assertSessionHasErrors('image_upload');

        $this->assertDatabaseCount('banners', 0);
    }

    public function test_image_banner_can_be_created_without_a_title(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin())->post(route('admin.banners.store'), [
            'title' => '   ',
            'image_upload' => UploadedFile::fake()->create('wedding-campaign.jpg', 10, 'image/jpeg'),
            'position' => 'home_wedding_campaign',
            'is_active' => 1,
        ])->assertRedirect(route('admin.banners.index'));

        $banner = Banner::firstOrFail();

        $this->assertNull($banner->title);
        $this->assertStringStartsWith('banners/', $banner->image);
        Storage::disk('public')->assertExists($banner->image);
    }

    public function test_invalid_image_upload_returns_visible_banner_form_errors(): void
    {
        $response = $this->actingAs($this->admin())
            ->from(route('admin.banners.create'))
            ->followingRedirects()
            ->post(route('admin.banners.store'), [
                'title' => null,
                'position' => 'home_wedding_campaign',
                'is_active' => 1,
                'image_upload' => UploadedFile::fake()->create('payload.php', 10, 'application/x-httpd-php'),
            ]);

        $response->assertOk()
            ->assertSee('data-banner-validation-errors', false)
            ->assertSee('data-banner-error="image_upload"', false);

        $this->assertDatabaseCount('banners', 0);
    }

    public function test_php_upload_size_failures_show_a_safe_actionable_message(): void
    {
        $temporaryFile = tempnam(sys_get_temp_dir(), 'banner-upload-');
        file_put_contents($temporaryFile, 'test');

        try {
            $response = $this->actingAs($this->admin())
                ->from(route('admin.banners.create'))
                ->followingRedirects()
                ->post(route('admin.banners.store'), [
                    'position' => 'home_wedding_campaign',
                    'is_active' => 1,
                    'image_upload' => new UploadedFile(
                        $temporaryFile,
                        'campaign.png',
                        'image/png',
                        UPLOAD_ERR_INI_SIZE,
                        true,
                    ),
                ]);

            $response->assertOk()
                ->assertSee('Tệp vượt giới hạn tải lên của PHP.', false)
                ->assertDontSee('The image upload failed to upload.', false);

            $this->assertDatabaseCount('banners', 0);
        } finally {
            @unlink($temporaryFile);
        }
    }

    public function test_campaign_video_rejects_an_image_upload(): void
    {
        $this->actingAs($this->admin())
            ->from(route('admin.banners.create'))
            ->post(route('admin.banners.store'), [
                'position' => 'home_story',
                'is_active' => 1,
                'image_upload' => UploadedFile::fake()->create('campaign.jpg', 10, 'image/jpeg'),
            ])
            ->assertRedirect(route('admin.banners.create'))
            ->assertSessionHasErrors('image_upload');

        $this->assertDatabaseCount('banners', 0);
    }

    public function test_video_banner_can_be_created_without_a_title(): void
    {
        $this->actingAs($this->admin())->post(route('admin.banners.store'), [
            'title' => null,
            'image' => 'https://vimeo.com/123456789',
            'position' => 'home_story',
            'is_active' => 1,
        ])->assertRedirect(route('admin.banners.index'));

        $this->assertDatabaseHas('banners', [
            'title' => null,
            'image' => 'https://vimeo.com/123456789',
            'position' => 'home_story',
        ]);
    }

    public function test_admin_can_create_a_wedding_campaign_banner_with_the_existing_image_pipeline(): void
    {
        Storage::fake('public');
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.banners.store'), [
            'title' => 'Wedding Campaign',
            'image_upload' => UploadedFile::fake()->create('wedding-campaign.jpg', 10, 'image/jpeg'),
            'position' => 'home_wedding_campaign',
            'sort_order' => 1,
            'is_active' => 1,
        ])->assertRedirect(route('admin.banners.index'));

        $banner = Banner::firstOrFail();

        $this->assertSame('home_wedding_campaign', $banner->position);
        $this->assertStringStartsWith('banners/', $banner->image);
        Storage::disk('public')->assertExists($banner->image);

        $this->actingAs($admin)->get(route('admin.banners.create'))
            ->assertOk()
            ->assertSee('home_wedding_campaign', false)
            ->assertSeeText('Wedding Campaign');
    }

    public function test_homepage_renders_only_the_active_scheduled_wedding_campaign_banner(): void
    {
        Banner::create([
            'title' => 'Wedding Campaign Live',
            'image' => 'banners/wedding-live.jpg',
            'position' => 'home_wedding_campaign',
            'is_active' => true,
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addDay(),
        ]);
        Banner::create([
            'title' => 'Wedding Campaign Future',
            'image' => 'banners/wedding-future.jpg',
            'position' => 'home_wedding_campaign',
            'is_active' => true,
            'starts_at' => now()->addDay(),
        ]);

        $this->get(route('home'))->assertOk()
            ->assertSee('/storage/banners/wedding-live.jpg', false)
            ->assertDontSee('/storage/banners/wedding-future.jpg', false);
    }

    public function test_homepage_renders_only_an_active_scheduled_home_story_campaign_with_a_safe_embed(): void
    {
        Banner::create([
            'title' => 'Hero video must stay separate',
            'image' => '/banners/hero.mp4',
            'position' => 'home',
            'is_active' => true,
        ]);
        Banner::create([
            'title' => 'A LOVE STORY THAT STAYS',
            'image' => 'https://vimeo.com/123456789',
            'position' => 'home_story',
            'is_active' => true,
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addDay(),
        ]);
        Banner::create([
            'title' => 'Future story',
            'image' => 'https://vimeo.com/987654321',
            'position' => 'home_story',
            'is_active' => true,
            'starts_at' => now()->addDay(),
        ]);

        $this->get(route('home'))->assertOk()
            ->assertSee('https://player.vimeo.com/video/123456789?playsinline=1', false)
            ->assertSeeText('A LOVE STORY THAT STAYS')
            ->assertDontSee('https://player.vimeo.com/video/987654321', false);
    }

    public function test_homepage_renders_only_the_active_scheduled_engagement_video(): void
    {
        Banner::create([
            'title' => 'Engagement live',
            'image' => 'https://vimeo.com/123456789',
            'position' => 'home_engagement_video',
            'is_active' => true,
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addDay(),
        ]);
        Banner::create([
            'title' => 'Engagement future',
            'image' => 'https://vimeo.com/987654321',
            'position' => 'home_engagement_video',
            'is_active' => true,
            'starts_at' => now()->addDay(),
        ]);
        Banner::create([
            'title' => 'Engagement expired',
            'image' => 'https://vimeo.com/111111111',
            'position' => 'home_engagement_video',
            'is_active' => true,
            'ends_at' => now()->subMinute(),
        ]);
        Banner::create([
            'title' => 'Engagement inactive',
            'image' => 'https://vimeo.com/222222222',
            'position' => 'home_engagement_video',
            'is_active' => false,
        ]);

        $this->get(route('home'))->assertOk()
            ->assertSee('https://player.vimeo.com/video/123456789?playsinline=1', false)
            ->assertDontSee('https://player.vimeo.com/video/987654321', false)
            ->assertDontSee('https://player.vimeo.com/video/111111111', false)
            ->assertDontSee('https://player.vimeo.com/video/222222222', false);
    }

    public function test_homepage_renders_only_the_active_scheduled_new_arrivals_campaign(): void
    {
        Banner::create([
            'title' => 'New arrivals live',
            'image' => 'banners/new-arrivals-live.jpg',
            'position' => 'home_new_arrivals_campaign',
            'is_active' => true,
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addDay(),
        ]);
        foreach ([
            ['image' => 'banners/new-arrivals-future.jpg', 'starts_at' => now()->addDay(), 'ends_at' => null, 'is_active' => true],
            ['image' => 'banners/new-arrivals-expired.jpg', 'starts_at' => null, 'ends_at' => now()->subMinute(), 'is_active' => true],
            ['image' => 'banners/new-arrivals-inactive.jpg', 'starts_at' => null, 'ends_at' => null, 'is_active' => false],
        ] as $attributes) {
            Banner::create($attributes + ['position' => 'home_new_arrivals_campaign', 'title' => null]);
        }

        $this->get(route('home'))->assertOk()
            ->assertSee('/storage/banners/new-arrivals-live.jpg', false)
            ->assertDontSee('/storage/banners/new-arrivals-future.jpg', false)
            ->assertDontSee('/storage/banners/new-arrivals-expired.jpg', false)
            ->assertDontSee('/storage/banners/new-arrivals-inactive.jpg', false);
    }

    public function test_engagement_showcase_limits_the_diamond_ring_block_to_unique_products(): void
    {
        $category = Category::create([
            'name' => 'Nhẫn kim cương',
            'slug' => 'nhan-kim-cuong',
            'is_active' => true,
        ]);

        $products = collect(range(1, 5))->map(fn (int $number) => Product::create([
            'category_id' => $category->id,
            'name' => 'Engagement ring '.$number,
            'slug' => 'engagement-ring-'.$number,
            'sku' => 'ENG-RING-'.$number,
            'price' => 10000000 + $number,
            'stock' => 1,
            'is_active' => true,
            'sort_order' => $number,
        ]));

        $response = $this->get(route('home'))->assertOk();
        $content = $response->getContent();

        $this->assertSame(4, substr_count($content, 'data-engagement-product-id='));
        foreach ($products->take(4) as $product) {
            $this->assertSame(1, substr_count($content, 'data-engagement-product-id="'.$product->id.'"'));
        }
        $this->assertSame(0, substr_count($content, 'data-engagement-product-id="'.$products->last()->id.'"'));
    }

    public function test_engagement_showcase_requires_option_selection_when_a_product_has_active_variants(): void
    {
        $category = Category::create([
            'name' => 'Nhẫn kim cương',
            'slug' => 'nhan-kim-cuong',
            'is_active' => true,
        ]);
        $simpleProduct = Product::create([
            'category_id' => $category->id,
            'name' => 'Simple engagement ring',
            'slug' => 'simple-engagement-ring',
            'sku' => 'SIMPLE-ENG-RING',
            'price' => 10000000,
            'stock' => 1,
            'is_active' => true,
        ]);
        $variantProduct = Product::create([
            'category_id' => $category->id,
            'name' => 'Sized engagement ring',
            'slug' => 'sized-engagement-ring',
            'sku' => 'SIZED-ENG-RING',
            'price' => 12000000,
            'stock' => 1,
            'is_active' => true,
        ]);
        ProductVariant::create([
            'product_id' => $variantProduct->id,
            'sku' => 'SIZED-ENG-RING-12',
            'size' => '12',
            'price' => 12000000,
            'stock' => 1,
            'is_active' => true,
        ]);

        $content = $this->get(route('home'))->assertOk()->getContent();

        $this->assertStringContainsString('data-engagement-product-id="'.$simpleProduct->id.'"', $content);
        $this->assertStringContainsString('data-engagement-action="add-cart"', $content);
        $this->assertStringContainsString('data-engagement-product-id="'.$variantProduct->id.'"', $content);
        $this->assertStringContainsString('data-engagement-action="select-options"', $content);
        $this->assertStringContainsString(route('product.show', $variantProduct->slug), $content);
    }

    public function test_new_arrivals_section_uses_only_active_new_arrival_products_without_duplicates(): void
    {
        $category = Category::create([
            'name' => 'New arrivals',
            'slug' => 'new-arrivals-category',
            'is_active' => true,
        ]);

        $activeProducts = collect(range(1, 9))->map(fn (int $number) => Product::create([
            'category_id' => $category->id,
            'name' => 'New arrival '.$number,
            'slug' => 'new-arrival-'.$number,
            'sku' => 'NEW-'.$number,
            'price' => 10000000 + $number,
            'stock' => 1,
            'is_active' => true,
            'is_new_arrival' => true,
            'sort_order' => $number,
        ]));
        $inactiveProduct = Product::create([
            'category_id' => $category->id,
            'name' => 'Inactive new arrival',
            'slug' => 'inactive-new-arrival',
            'sku' => 'NEW-INACTIVE',
            'price' => 10000000,
            'stock' => 1,
            'is_active' => false,
            'is_new_arrival' => true,
        ]);
        $notNewProduct = Product::create([
            'category_id' => $category->id,
            'name' => 'Regular product',
            'slug' => 'regular-product',
            'sku' => 'REGULAR-1',
            'price' => 10000000,
            'stock' => 1,
            'is_active' => true,
            'is_new_arrival' => false,
        ]);

        $content = $this->get(route('home'))->assertOk()->getContent();

        $this->assertSame(8, substr_count($content, 'data-new-arrival-product-id='));
        preg_match_all('/data-new-arrival-product-id="(\d+)"/', $content, $matches);
        $renderedProductIds = $matches[1];
        $activeProductIds = $activeProducts->pluck('id')->map(fn (int $id) => (string) $id)->all();

        $this->assertCount(8, array_unique($renderedProductIds));
        foreach ($renderedProductIds as $productId) {
            $this->assertContains($productId, $activeProductIds);
        }
        $this->assertSame(0, substr_count($content, 'data-new-arrival-product-id="'.$inactiveProduct->id.'"'));
        $this->assertSame(0, substr_count($content, 'data-new-arrival-product-id="'.$notNewProduct->id.'"'));
    }

    public function test_admin_can_create_an_editorial_gallery_image(): void
    {
        Storage::fake('public');
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.banners.store'), [
            'title' => null,
            'position' => 'home_editorial_gallery',
            'sort_order' => 1,
            'is_active' => 1,
            'image_upload' => UploadedFile::fake()->create('editorial.jpg', 10, 'image/jpeg'),
        ])->assertRedirect(route('admin.banners.index'));

        $banner = Banner::firstOrFail();

        $this->assertSame('home_editorial_gallery', $banner->position);
        $this->assertStringStartsWith('banners/', $banner->image);
        Storage::disk('public')->assertExists($banner->image);

        $this->actingAs($admin)->get(route('admin.banners.create'))
            ->assertOk()
            ->assertSee('home_editorial_gallery', false)
            ->assertSeeText('Editorial Gallery');
    }

    public function test_editorial_gallery_rejects_vimeo_and_file_video_references(): void
    {
        $admin = $this->admin();

        foreach (['https://vimeo.com/123456789', 'https://vimeo.com/not-a-video', 'https://youtube.com/watch?v=123456789', 'https://example.test/editorial.mp4'] as $reference) {
            $this->actingAs($admin)->post(route('admin.banners.store'), [
                'position' => 'home_editorial_gallery',
                'image' => $reference,
                'is_active' => 1,
            ])->assertSessionHasErrors('image');
        }

        $this->assertDatabaseCount('banners', 0);
    }

    public function test_homepage_renders_active_scheduled_editorial_gallery_images_in_sort_order(): void
    {
        Banner::create([
            'title' => 'Second editorial image',
            'image' => 'banners/editorial-second.jpg',
            'position' => 'home_editorial_gallery',
            'sort_order' => 20,
            'is_active' => true,
        ]);
        Banner::create([
            'title' => 'First editorial image',
            'image' => 'banners/editorial-first.jpg',
            'position' => 'home_editorial_gallery',
            'sort_order' => 10,
            'is_active' => true,
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addDay(),
        ]);
        foreach ([
            ['image' => 'banners/editorial-inactive.jpg', 'is_active' => false],
            ['image' => 'banners/editorial-future.jpg', 'is_active' => true, 'starts_at' => now()->addDay()],
            ['image' => 'banners/editorial-expired.jpg', 'is_active' => true, 'ends_at' => now()->subMinute()],
        ] as $attributes) {
            Banner::create($attributes + [
                'title' => null,
                'position' => 'home_editorial_gallery',
                'sort_order' => 30,
            ]);
        }

        $content = $this->get(route('home'))->assertOk()->getContent();
        $firstPosition = strpos($content, '/storage/banners/editorial-first.jpg');
        $secondPosition = strpos($content, '/storage/banners/editorial-second.jpg');

        $this->assertNotFalse($firstPosition);
        $this->assertNotFalse($secondPosition);
        $this->assertLessThan($secondPosition, $firstPosition);
        $this->assertStringContainsString('data-editorial-gallery', $content);
        $this->assertStringNotContainsString('/storage/banners/editorial-inactive.jpg', $content);
        $this->assertStringNotContainsString('/storage/banners/editorial-future.jpg', $content);
        $this->assertStringNotContainsString('/storage/banners/editorial-expired.jpg', $content);
    }

    public function test_homepage_omits_the_editorial_gallery_when_no_gallery_images_are_available(): void
    {
        $this->get(route('home'))->assertOk()
            ->assertDontSee('data-editorial-gallery', false);
    }

    public function test_admin_can_configure_high_jewelry_video_and_image_banners(): void
    {
        Storage::fake('public');
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.banners.store'), [
            'title' => 'High Jewelry Film',
            'image' => 'https://player.vimeo.com/video/123456789',
            'position' => 'home_high_jewelry_video',
            'is_active' => 1,
        ])->assertRedirect(route('admin.banners.index'));

        $this->actingAs($admin)->post(route('admin.banners.store'), [
            'title' => null,
            'image_upload' => UploadedFile::fake()->create('high-jewelry.jpg', 10, 'image/jpeg'),
            'position' => 'home_high_jewelry_image',
            'sort_order' => 1,
            'is_active' => 1,
        ])->assertRedirect(route('admin.banners.index'));

        $this->assertDatabaseHas('banners', [
            'position' => 'home_high_jewelry_video',
            'image' => 'https://vimeo.com/123456789',
        ]);
        $imageBanner = Banner::where('position', 'home_high_jewelry_image')->firstOrFail();
        Storage::disk('public')->assertExists($imageBanner->image);

        $this->actingAs($admin)->get(route('admin.banners.create'))
            ->assertOk()
            ->assertSee('home_high_jewelry_video', false)
            ->assertSee('home_high_jewelry_image', false)
            ->assertSeeText('High Jewelry Image');
    }

    public function test_high_jewelry_image_rejects_vimeo_and_file_video_references(): void
    {
        $admin = $this->admin();

        foreach (['https://vimeo.com/123456789', 'https://youtube.com/watch?v=123456789', 'https://example.test/high-jewelry.webm'] as $reference) {
            $this->actingAs($admin)->post(route('admin.banners.store'), [
                'position' => 'home_high_jewelry_image',
                'image' => $reference,
                'is_active' => 1,
            ])->assertSessionHasErrors('image');
        }

        $this->assertDatabaseCount('banners', 0);
    }

    public function test_homepage_renders_active_scheduled_high_jewelry_images_in_sort_order_with_its_video(): void
    {
        Banner::create([
            'title' => 'High Jewelry Film',
            'image' => 'https://vimeo.com/123456789',
            'position' => 'home_high_jewelry_video',
            'is_active' => true,
        ]);
        Banner::create([
            'title' => 'Second high image',
            'image' => 'banners/high-second.jpg',
            'position' => 'home_high_jewelry_image',
            'sort_order' => 20,
            'is_active' => true,
        ]);
        Banner::create([
            'title' => 'First high image',
            'image' => 'banners/high-first.jpg',
            'position' => 'home_high_jewelry_image',
            'sort_order' => 10,
            'is_active' => true,
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addDay(),
        ]);
        foreach ([
            ['image' => 'banners/high-inactive.jpg', 'is_active' => false],
            ['image' => 'banners/high-future.jpg', 'is_active' => true, 'starts_at' => now()->addDay()],
            ['image' => 'banners/high-expired.jpg', 'is_active' => true, 'ends_at' => now()->subMinute()],
        ] as $attributes) {
            Banner::create($attributes + [
                'title' => null,
                'position' => 'home_high_jewelry_image',
                'sort_order' => 30,
            ]);
        }

        $content = $this->get(route('home'))->assertOk()->getContent();
        $firstPosition = strpos($content, '/storage/banners/high-first.jpg');
        $secondPosition = strpos($content, '/storage/banners/high-second.jpg');

        $this->assertNotFalse($firstPosition);
        $this->assertNotFalse($secondPosition);
        $this->assertLessThan($secondPosition, $firstPosition);
        $this->assertStringContainsString('https://player.vimeo.com/video/123456789?playsinline=1', $content);
        $this->assertStringContainsString('data-high-jewelry-carousel', $content);
        $this->assertStringContainsString('data-high-jewelry-next', $content);
        $this->assertStringNotContainsString('/storage/banners/high-inactive.jpg', $content);
        $this->assertStringNotContainsString('/storage/banners/high-future.jpg', $content);
        $this->assertStringNotContainsString('/storage/banners/high-expired.jpg', $content);
    }

    public function test_homepage_handles_empty_and_single_high_jewelry_image_lists_safely(): void
    {
        $this->get(route('home'))->assertOk()
            ->assertDontSee('data-high-jewelry', false);

        Banner::create([
            'title' => 'High Jewelry Film',
            'image' => 'https://vimeo.com/123456789',
            'position' => 'home_high_jewelry_video',
            'is_active' => true,
        ]);
        Banner::create([
            'title' => 'Single high image',
            'image' => 'banners/high-single.jpg',
            'position' => 'home_high_jewelry_image',
            'is_active' => true,
        ]);

        $this->get(route('home'))->assertOk()
            ->assertSee('data-high-jewelry-carousel', false)
            ->assertSee('/storage/banners/high-single.jpg', false)
            ->assertDontSee('data-high-jewelry-next', false);
    }

    public function test_admin_can_create_a_high_jewelry_campaign_image(): void
    {
        Storage::fake('public');
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.banners.store'), [
            'title' => null,
            'image_upload' => UploadedFile::fake()->create('high-jewelry-campaign.jpg', 10, 'image/jpeg'),
            'position' => 'home_high_jewelry_campaign',
            'sort_order' => 1,
            'is_active' => 1,
        ])->assertRedirect(route('admin.banners.index'));

        $banner = Banner::where('position', 'home_high_jewelry_campaign')->firstOrFail();

        $this->assertNull($banner->title);
        $this->assertStringStartsWith('banners/', $banner->image);
        Storage::disk('public')->assertExists($banner->image);

        $this->actingAs($admin)->get(route('admin.banners.create'))
            ->assertOk()
            ->assertSee('home_high_jewelry_campaign', false)
            ->assertSeeText('High Jewelry Campaign');
    }

    public function test_high_jewelry_campaign_rejects_video_references(): void
    {
        $admin = $this->admin();

        foreach (['https://vimeo.com/123456789', 'https://youtube.com/watch?v=123456789', 'https://example.test/high-jewelry.mp4'] as $reference) {
            $this->actingAs($admin)->post(route('admin.banners.store'), [
                'position' => 'home_high_jewelry_campaign',
                'image' => $reference,
                'is_active' => 1,
            ])->assertSessionHasErrors('image');
        }

        $this->assertDatabaseCount('banners', 0);
    }

    public function test_homepage_renders_only_an_active_scheduled_high_jewelry_campaign(): void
    {
        Banner::create([
            'title' => 'High Jewelry Campaign',
            'image' => 'banners/high-campaign-live.jpg',
            'position' => 'home_high_jewelry_campaign',
            'is_active' => true,
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addDay(),
        ]);
        foreach ([
            ['image' => 'banners/high-campaign-inactive.jpg', 'is_active' => false],
            ['image' => 'banners/high-campaign-future.jpg', 'is_active' => true, 'starts_at' => now()->addDay()],
            ['image' => 'banners/high-campaign-expired.jpg', 'is_active' => true, 'ends_at' => now()->subMinute()],
        ] as $attributes) {
            Banner::create($attributes + [
                'title' => null,
                'position' => 'home_high_jewelry_campaign',
            ]);
        }

        $this->get(route('home'))->assertOk()
            ->assertSee('home-high-jewelry-campaign', false)
            ->assertSee('/storage/banners/high-campaign-live.jpg', false)
            ->assertDontSee('/storage/banners/high-campaign-inactive.jpg', false)
            ->assertDontSee('/storage/banners/high-campaign-future.jpg', false)
            ->assertDontSee('/storage/banners/high-campaign-expired.jpg', false);
    }

    public function test_homepage_omits_high_jewelry_campaign_when_no_valid_image_exists(): void
    {
        $this->get(route('home'))->assertOk()
            ->assertDontSee('home-high-jewelry-campaign', false);
    }

    public function test_admin_can_create_a_high_jewelry_editorial_image(): void
    {
        Storage::fake('public');
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.banners.store'), [
            'title' => null,
            'image_upload' => UploadedFile::fake()->create('high-jewelry-editorial.webp', 10, 'image/webp'),
            'position' => 'home_high_jewelry_editorial',
            'is_active' => 1,
        ])->assertRedirect(route('admin.banners.index'));

        $banner = Banner::where('position', 'home_high_jewelry_editorial')->firstOrFail();

        $this->assertStringStartsWith('banners/', $banner->image);
        Storage::disk('public')->assertExists($banner->image);

        $this->actingAs($admin)->get(route('admin.banners.create'))
            ->assertOk()
            ->assertSee('home_high_jewelry_editorial', false)
            ->assertSeeText('High Jewelry Editorial');
    }

    public function test_high_jewelry_editorial_rejects_video_references(): void
    {
        $admin = $this->admin();

        foreach (['https://vimeo.com/123456789', 'https://youtube.com/watch?v=123456789', 'https://example.test/high-jewelry.mp4'] as $reference) {
            $this->actingAs($admin)->post(route('admin.banners.store'), [
                'position' => 'home_high_jewelry_editorial',
                'image' => $reference,
                'is_active' => 1,
            ])->assertSessionHasErrors('image');
        }

        $this->assertDatabaseCount('banners', 0);
    }

    public function test_high_jewelry_product_editorial_renders_unique_active_diamond_products_and_a_live_editorial_image(): void
    {
        $diamondRoot = Category::create([
            'name' => 'Trang sức kim cương',
            'slug' => 'trang-suc-kim-cuong',
            'is_active' => true,
        ]);
        $diamondRings = Category::create([
            'name' => 'Nhẫn kim cương',
            'slug' => 'nhan-kim-cuong',
            'parent_id' => $diamondRoot->id,
            'is_active' => true,
        ]);
        $inactiveCategory = Category::create([
            'name' => 'Inactive diamond category',
            'slug' => 'inactive-diamond-category',
            'parent_id' => $diamondRoot->id,
            'is_active' => false,
        ]);
        $products = collect(range(1, 5))->map(fn (int $number) => Product::create([
            'category_id' => $diamondRings->id,
            'name' => 'High jewelry ring '.$number,
            'slug' => 'high-jewelry-ring-'.$number,
            'sku' => 'HIGH-RING-'.$number,
            'price' => 20000000 + $number,
            'stock' => 1,
            'is_active' => true,
            'sort_order' => $number,
        ]));
        $inactiveProduct = Product::create([
            'category_id' => $diamondRings->id,
            'name' => 'Inactive high jewelry ring',
            'slug' => 'inactive-high-jewelry-ring',
            'sku' => 'HIGH-INACTIVE',
            'price' => 20000000,
            'stock' => 1,
            'is_active' => false,
        ]);
        $inactiveCategoryProduct = Product::create([
            'category_id' => $inactiveCategory->id,
            'name' => 'Inactive category high jewelry ring',
            'slug' => 'inactive-category-high-jewelry-ring',
            'sku' => 'HIGH-CATEGORY-INACTIVE',
            'price' => 20000000,
            'stock' => 1,
            'is_active' => true,
        ]);
        Banner::create([
            'title' => 'Live editorial',
            'image' => 'banners/high-editorial-live.jpg',
            'position' => 'home_high_jewelry_editorial',
            'is_active' => true,
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addDay(),
        ]);
        foreach ([
            ['image' => 'banners/high-editorial-inactive.jpg', 'is_active' => false],
            ['image' => 'banners/high-editorial-future.jpg', 'is_active' => true, 'starts_at' => now()->addDay()],
            ['image' => 'banners/high-editorial-expired.jpg', 'is_active' => true, 'ends_at' => now()->subMinute()],
        ] as $attributes) {
            Banner::create($attributes + [
                'title' => null,
                'position' => 'home_high_jewelry_editorial',
            ]);
        }

        $content = $this->get(route('home'))->assertOk()->getContent();

        $this->assertStringContainsString('home-high-jewelry-products', $content);
        $this->assertStringContainsString('/storage/banners/high-editorial-live.jpg', $content);
        $this->assertStringNotContainsString('/storage/banners/high-editorial-inactive.jpg', $content);
        $this->assertStringNotContainsString('/storage/banners/high-editorial-future.jpg', $content);
        $this->assertStringNotContainsString('/storage/banners/high-editorial-expired.jpg', $content);
        preg_match_all('/data-high-jewelry-product-id="(\d+)"/', $content, $matches);
        $renderedProductIds = $matches[1];

        $this->assertCount(4, $renderedProductIds);
        $this->assertCount(4, array_unique($renderedProductIds));
        foreach ($products->take(4) as $product) {
            $this->assertContains((string) $product->id, $renderedProductIds);
        }
        $this->assertNotContains((string) $products->last()->id, $renderedProductIds);
        $this->assertNotContains((string) $inactiveProduct->id, $renderedProductIds);
        $this->assertNotContains((string) $inactiveCategoryProduct->id, $renderedProductIds);
    }

    public function test_high_jewelry_product_editorial_preserves_variant_selection_before_cart_addition(): void
    {
        $diamondRoot = Category::create([
            'name' => 'Trang sức kim cương',
            'slug' => 'trang-suc-kim-cuong',
            'is_active' => true,
        ]);
        $diamondRings = Category::create([
            'name' => 'Nhẫn kim cương',
            'slug' => 'nhan-kim-cuong',
            'parent_id' => $diamondRoot->id,
            'is_active' => true,
        ]);
        $simpleProduct = Product::create([
            'category_id' => $diamondRings->id,
            'name' => 'Simple high jewelry ring',
            'slug' => 'simple-high-jewelry-ring',
            'sku' => 'HIGH-SIMPLE',
            'price' => 20000000,
            'stock' => 1,
            'is_active' => true,
        ]);
        $variantProduct = Product::create([
            'category_id' => $diamondRings->id,
            'name' => 'Sized high jewelry ring',
            'slug' => 'sized-high-jewelry-ring',
            'sku' => 'HIGH-SIZED',
            'price' => 22000000,
            'stock' => 1,
            'is_active' => true,
        ]);
        ProductVariant::create([
            'product_id' => $variantProduct->id,
            'sku' => 'HIGH-SIZED-12',
            'size' => '12',
            'price' => 22000000,
            'stock' => 1,
            'is_active' => true,
        ]);
        Banner::create([
            'title' => null,
            'image' => 'banners/high-editorial-live.jpg',
            'position' => 'home_high_jewelry_editorial',
            'is_active' => true,
        ]);

        $content = $this->get(route('home'))->assertOk()->getContent();

        $this->assertStringContainsString('data-high-jewelry-product-id="'.$simpleProduct->id.'"', $content);
        $this->assertStringContainsString('data-high-jewelry-action="add-cart"', $content);
        $this->assertStringContainsString('data-high-jewelry-product-id="'.$variantProduct->id.'"', $content);
        $this->assertStringContainsString('data-high-jewelry-action="select-options"', $content);
        $this->assertStringContainsString(route('product.show', $variantProduct->slug), $content);
    }

    public function test_homepage_omits_the_high_jewelry_product_editorial_block_without_an_editorial_image(): void
    {
        $this->get(route('home'))->assertOk()
            ->assertDontSee('home-high-jewelry-products', false);
    }

    public function test_admin_can_create_a_cz_editorial_image(): void
    {
        Storage::fake('public');
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.banners.store'), [
            'title' => null,
            'image_upload' => UploadedFile::fake()->create('cz-editorial.png', 10, 'image/png'),
            'position' => 'home_cz_editorial',
            'is_active' => 1,
        ])->assertRedirect(route('admin.banners.index'));

        $banner = Banner::where('position', 'home_cz_editorial')->firstOrFail();

        $this->assertStringStartsWith('banners/', $banner->image);
        Storage::disk('public')->assertExists($banner->image);

        $this->actingAs($admin)->get(route('admin.banners.create'))
            ->assertOk()
            ->assertSee('home_cz_editorial', false)
            ->assertSeeText('CZ Editorial');
    }

    public function test_cz_editorial_rejects_video_references(): void
    {
        $admin = $this->admin();

        foreach (['https://vimeo.com/123456789', 'https://youtube.com/watch?v=123456789', 'https://example.test/cz-editorial.webm'] as $reference) {
            $this->actingAs($admin)->post(route('admin.banners.store'), [
                'position' => 'home_cz_editorial',
                'image' => $reference,
                'is_active' => 1,
            ])->assertSessionHasErrors('image');
        }

        $this->assertDatabaseCount('banners', 0);
    }

    public function test_cz_showcase_renders_unique_active_products_from_the_cz_subtree_with_live_editorial_media(): void
    {
        $czRoot = Category::create([
            'name' => 'Trang sức CZ',
            'slug' => 'trang-suc-cz',
            'is_active' => true,
        ]);
        $czRings = Category::create([
            'name' => 'Nhẫn CZ',
            'slug' => 'nhan-cz',
            'parent_id' => $czRoot->id,
            'is_active' => true,
        ]);
        $inactiveCzCategory = Category::create([
            'name' => 'Inactive CZ category',
            'slug' => 'inactive-cz-category',
            'parent_id' => $czRoot->id,
            'is_active' => false,
        ]);
        $unrelatedCategory = Category::create([
            'name' => 'Unrelated category',
            'slug' => 'unrelated-category',
            'is_active' => true,
        ]);
        $products = collect(range(1, 5))->map(fn (int $number) => Product::create([
            'category_id' => $czRings->id,
            'name' => 'CZ jewelry '.$number,
            'slug' => 'cz-jewelry-'.$number,
            'sku' => 'CZ-'.$number,
            'price' => 5000000 + $number,
            'stock' => 1,
            'is_active' => true,
            'sort_order' => $number,
        ]));
        $inactiveProduct = Product::create([
            'category_id' => $czRings->id,
            'name' => 'Inactive CZ jewelry',
            'slug' => 'inactive-cz-jewelry',
            'sku' => 'CZ-INACTIVE',
            'price' => 5000000,
            'stock' => 1,
            'is_active' => false,
        ]);
        $inactiveCategoryProduct = Product::create([
            'category_id' => $inactiveCzCategory->id,
            'name' => 'Inactive category CZ jewelry',
            'slug' => 'inactive-category-cz-jewelry',
            'sku' => 'CZ-CATEGORY-INACTIVE',
            'price' => 5000000,
            'stock' => 1,
            'is_active' => true,
        ]);
        $unrelatedProduct = Product::create([
            'category_id' => $unrelatedCategory->id,
            'name' => 'Unrelated jewelry',
            'slug' => 'unrelated-jewelry',
            'sku' => 'UNRELATED-CZ',
            'price' => 5000000,
            'stock' => 1,
            'is_active' => true,
        ]);
        Banner::create([
            'title' => 'Live CZ editorial',
            'image' => 'banners/cz-editorial-live.jpg',
            'position' => 'home_cz_editorial',
            'is_active' => true,
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addDay(),
        ]);
        foreach ([
            ['image' => 'banners/cz-editorial-inactive.jpg', 'is_active' => false],
            ['image' => 'banners/cz-editorial-future.jpg', 'is_active' => true, 'starts_at' => now()->addDay()],
            ['image' => 'banners/cz-editorial-expired.jpg', 'is_active' => true, 'ends_at' => now()->subMinute()],
        ] as $attributes) {
            Banner::create($attributes + [
                'title' => null,
                'position' => 'home_cz_editorial',
            ]);
        }

        $content = $this->get(route('home'))->assertOk()->getContent();

        $this->assertStringContainsString('home-cz-showcase', $content);
        $this->assertStringContainsString('/storage/banners/cz-editorial-live.jpg', $content);
        $this->assertStringNotContainsString('/storage/banners/cz-editorial-inactive.jpg', $content);
        $this->assertStringNotContainsString('/storage/banners/cz-editorial-future.jpg', $content);
        $this->assertStringNotContainsString('/storage/banners/cz-editorial-expired.jpg', $content);
        preg_match_all('/data-cz-product-id="(\d+)"/', $content, $matches);
        $renderedProductIds = $matches[1];

        $this->assertCount(4, $renderedProductIds);
        $this->assertCount(4, array_unique($renderedProductIds));
        foreach ($products->take(4) as $product) {
            $this->assertContains((string) $product->id, $renderedProductIds);
        }
        $this->assertNotContains((string) $products->last()->id, $renderedProductIds);
        $this->assertNotContains((string) $inactiveProduct->id, $renderedProductIds);
        $this->assertNotContains((string) $inactiveCategoryProduct->id, $renderedProductIds);
        $this->assertNotContains((string) $unrelatedProduct->id, $renderedProductIds);
    }

    public function test_homepage_omits_the_cz_showcase_without_an_editorial_image(): void
    {
        $this->get(route('home'))->assertOk()
            ->assertDontSee('home-cz-showcase', false);
    }

    public function test_admin_can_create_a_colored_gemstone_editorial_image(): void
    {
        Storage::fake('public');
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.banners.store'), [
            'title' => null,
            'image_upload' => UploadedFile::fake()->create('colored-gemstone-editorial.jpg', 10, 'image/jpeg'),
            'position' => 'home_colored_gemstone_editorial',
            'is_active' => 1,
        ])->assertRedirect(route('admin.banners.index'));

        $banner = Banner::where('position', 'home_colored_gemstone_editorial')->firstOrFail();

        $this->assertStringStartsWith('banners/', $banner->image);
        Storage::disk('public')->assertExists($banner->image);

        $this->actingAs($admin)->get(route('admin.banners.create'))
            ->assertOk()
            ->assertSee('home_colored_gemstone_editorial', false)
            ->assertSeeText('Colored Gemstone Editorial');
    }

    public function test_colored_gemstone_editorial_rejects_video_references(): void
    {
        $admin = $this->admin();

        foreach (['https://vimeo.com/123456789', 'https://youtube.com/watch?v=123456789', 'https://example.test/gemstone-editorial.mp4'] as $reference) {
            $this->actingAs($admin)->post(route('admin.banners.store'), [
                'position' => 'home_colored_gemstone_editorial',
                'image' => $reference,
                'is_active' => 1,
            ])->assertSessionHasErrors('image');
        }

        $this->assertDatabaseCount('banners', 0);
    }

    public function test_colored_gemstone_showcase_renders_unique_active_products_from_its_subtree_with_live_editorial_media(): void
    {
        $gemstoneRoot = Category::create([
            'name' => 'Trang sức đá màu',
            'slug' => 'trang-suc-da-mau',
            'is_active' => true,
        ]);
        $gemstoneRings = Category::create([
            'name' => 'Nhẫn đá màu',
            'slug' => 'nhan-da-mau',
            'parent_id' => $gemstoneRoot->id,
            'is_active' => true,
        ]);
        $inactiveGemstoneCategory = Category::create([
            'name' => 'Inactive gemstone category',
            'slug' => 'inactive-gemstone-category',
            'parent_id' => $gemstoneRoot->id,
            'is_active' => false,
        ]);
        $unrelatedCategory = Category::create([
            'name' => 'Unrelated category',
            'slug' => 'unrelated-gemstone-category',
            'is_active' => true,
        ]);
        $products = collect(range(1, 5))->map(fn (int $number) => Product::create([
            'category_id' => $gemstoneRings->id,
            'name' => 'Gemstone jewelry '.$number,
            'slug' => 'gemstone-jewelry-'.$number,
            'sku' => 'GEM-'.$number,
            'price' => 8000000 + $number,
            'stock' => 1,
            'is_active' => true,
            'sort_order' => $number,
        ]));
        $inactiveProduct = Product::create([
            'category_id' => $gemstoneRings->id,
            'name' => 'Inactive gemstone jewelry',
            'slug' => 'inactive-gemstone-jewelry',
            'sku' => 'GEM-INACTIVE',
            'price' => 8000000,
            'stock' => 1,
            'is_active' => false,
        ]);
        $inactiveCategoryProduct = Product::create([
            'category_id' => $inactiveGemstoneCategory->id,
            'name' => 'Inactive category gemstone jewelry',
            'slug' => 'inactive-category-gemstone-jewelry',
            'sku' => 'GEM-CATEGORY-INACTIVE',
            'price' => 8000000,
            'stock' => 1,
            'is_active' => true,
        ]);
        $unrelatedProduct = Product::create([
            'category_id' => $unrelatedCategory->id,
            'name' => 'Unrelated jewelry',
            'slug' => 'unrelated-gemstone-jewelry',
            'sku' => 'GEM-UNRELATED',
            'price' => 8000000,
            'stock' => 1,
            'is_active' => true,
        ]);
        Banner::create([
            'title' => 'Live gemstone editorial',
            'image' => 'banners/gemstone-editorial-live.jpg',
            'position' => 'home_colored_gemstone_editorial',
            'is_active' => true,
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addDay(),
        ]);
        foreach ([
            ['image' => 'banners/gemstone-editorial-inactive.jpg', 'is_active' => false],
            ['image' => 'banners/gemstone-editorial-future.jpg', 'is_active' => true, 'starts_at' => now()->addDay()],
            ['image' => 'banners/gemstone-editorial-expired.jpg', 'is_active' => true, 'ends_at' => now()->subMinute()],
        ] as $attributes) {
            Banner::create($attributes + [
                'title' => null,
                'position' => 'home_colored_gemstone_editorial',
            ]);
        }

        $content = $this->get(route('home'))->assertOk()->getContent();

        $this->assertStringContainsString('home-colored-gemstone-showcase', $content);
        $this->assertStringContainsString('/storage/banners/gemstone-editorial-live.jpg', $content);
        $this->assertStringNotContainsString('/storage/banners/gemstone-editorial-inactive.jpg', $content);
        $this->assertStringNotContainsString('/storage/banners/gemstone-editorial-future.jpg', $content);
        $this->assertStringNotContainsString('/storage/banners/gemstone-editorial-expired.jpg', $content);
        preg_match_all('/data-colored-gemstone-product-id="(\d+)"/', $content, $matches);
        $renderedProductIds = $matches[1];

        $this->assertCount(4, $renderedProductIds);
        $this->assertCount(4, array_unique($renderedProductIds));
        foreach ($products->take(4) as $product) {
            $this->assertContains((string) $product->id, $renderedProductIds);
        }
        $this->assertNotContains((string) $products->last()->id, $renderedProductIds);
        $this->assertNotContains((string) $inactiveProduct->id, $renderedProductIds);
        $this->assertNotContains((string) $inactiveCategoryProduct->id, $renderedProductIds);
        $this->assertNotContains((string) $unrelatedProduct->id, $renderedProductIds);
    }

    public function test_homepage_omits_the_colored_gemstone_showcase_without_an_editorial_image(): void
    {
        $this->get(route('home'))->assertOk()
            ->assertDontSee('home-colored-gemstone-showcase', false);
    }

    public function test_admin_can_create_a_pearl_editorial_image(): void
    {
        Storage::fake('public');
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.banners.store'), [
            'title' => null,
            'image_upload' => UploadedFile::fake()->create('pearl-editorial.webp', 10, 'image/webp'),
            'position' => 'home_pearl_editorial',
            'is_active' => 1,
        ])->assertRedirect(route('admin.banners.index'));

        $banner = Banner::where('position', 'home_pearl_editorial')->firstOrFail();

        $this->assertStringStartsWith('banners/', $banner->image);
        Storage::disk('public')->assertExists($banner->image);

        $this->actingAs($admin)->get(route('admin.banners.create'))
            ->assertOk()
            ->assertSee('home_pearl_editorial', false)
            ->assertSeeText('Pearl Editorial');
    }

    public function test_pearl_editorial_rejects_video_references(): void
    {
        $admin = $this->admin();

        foreach (['https://vimeo.com/123456789', 'https://youtube.com/watch?v=123456789', 'https://example.test/pearl-editorial.ogg'] as $reference) {
            $this->actingAs($admin)->post(route('admin.banners.store'), [
                'position' => 'home_pearl_editorial',
                'image' => $reference,
                'is_active' => 1,
            ])->assertSessionHasErrors('image');
        }

        $this->assertDatabaseCount('banners', 0);
    }

    public function test_pearl_showcase_renders_unique_active_products_from_its_subtree_with_live_editorial_media(): void
    {
        $pearlRoot = Category::create([
            'name' => 'Trang sức ngọc trai',
            'slug' => 'trang-suc-ngoc-trai',
            'is_active' => true,
        ]);
        $pearlEarrings = Category::create([
            'name' => 'Bông tai ngọc trai',
            'slug' => 'bong-tai-ngoc-trai',
            'parent_id' => $pearlRoot->id,
            'is_active' => true,
        ]);
        $inactivePearlCategory = Category::create([
            'name' => 'Inactive pearl category',
            'slug' => 'inactive-pearl-category',
            'parent_id' => $pearlRoot->id,
            'is_active' => false,
        ]);
        $unrelatedCategory = Category::create([
            'name' => 'Unrelated category',
            'slug' => 'unrelated-pearl-category',
            'is_active' => true,
        ]);
        $products = collect(range(1, 5))->map(fn (int $number) => Product::create([
            'category_id' => $pearlEarrings->id,
            'name' => 'Pearl jewelry '.$number,
            'slug' => 'pearl-jewelry-'.$number,
            'sku' => 'PEARL-'.$number,
            'price' => 7000000 + $number,
            'stock' => 1,
            'is_active' => true,
            'sort_order' => $number,
        ]));
        $inactiveProduct = Product::create([
            'category_id' => $pearlEarrings->id,
            'name' => 'Inactive pearl jewelry',
            'slug' => 'inactive-pearl-jewelry',
            'sku' => 'PEARL-INACTIVE',
            'price' => 7000000,
            'stock' => 1,
            'is_active' => false,
        ]);
        $inactiveCategoryProduct = Product::create([
            'category_id' => $inactivePearlCategory->id,
            'name' => 'Inactive category pearl jewelry',
            'slug' => 'inactive-category-pearl-jewelry',
            'sku' => 'PEARL-CATEGORY-INACTIVE',
            'price' => 7000000,
            'stock' => 1,
            'is_active' => true,
        ]);
        $unrelatedProduct = Product::create([
            'category_id' => $unrelatedCategory->id,
            'name' => 'Unrelated jewelry',
            'slug' => 'unrelated-pearl-jewelry',
            'sku' => 'PEARL-UNRELATED',
            'price' => 7000000,
            'stock' => 1,
            'is_active' => true,
        ]);
        Banner::create([
            'title' => 'Live pearl editorial',
            'image' => 'banners/pearl-editorial-live.jpg',
            'position' => 'home_pearl_editorial',
            'is_active' => true,
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addDay(),
        ]);
        foreach ([
            ['image' => 'banners/pearl-editorial-inactive.jpg', 'is_active' => false],
            ['image' => 'banners/pearl-editorial-future.jpg', 'is_active' => true, 'starts_at' => now()->addDay()],
            ['image' => 'banners/pearl-editorial-expired.jpg', 'is_active' => true, 'ends_at' => now()->subMinute()],
        ] as $attributes) {
            Banner::create($attributes + [
                'title' => null,
                'position' => 'home_pearl_editorial',
            ]);
        }

        $content = $this->get(route('home'))->assertOk()->getContent();

        $this->assertStringContainsString('home-pearl-showcase', $content);
        $this->assertStringContainsString('/storage/banners/pearl-editorial-live.jpg', $content);
        $this->assertStringNotContainsString('/storage/banners/pearl-editorial-inactive.jpg', $content);
        $this->assertStringNotContainsString('/storage/banners/pearl-editorial-future.jpg', $content);
        $this->assertStringNotContainsString('/storage/banners/pearl-editorial-expired.jpg', $content);
        preg_match_all('/data-pearl-product-id="(\d+)"/', $content, $matches);
        $renderedProductIds = $matches[1];

        $this->assertCount(4, $renderedProductIds);
        $this->assertCount(4, array_unique($renderedProductIds));
        foreach ($products->take(4) as $product) {
            $this->assertContains((string) $product->id, $renderedProductIds);
        }
        $this->assertNotContains((string) $products->last()->id, $renderedProductIds);
        $this->assertNotContains((string) $inactiveProduct->id, $renderedProductIds);
        $this->assertNotContains((string) $inactiveCategoryProduct->id, $renderedProductIds);
        $this->assertNotContains((string) $unrelatedProduct->id, $renderedProductIds);
    }

    public function test_homepage_omits_the_pearl_showcase_without_an_editorial_image(): void
    {
        $this->get(route('home'))->assertOk()
            ->assertDontSee('home-pearl-showcase', false);
    }

    public function test_admin_can_create_a_wedding_editorial_image_and_it_rejects_video_references(): void
    {
        Storage::fake('public');
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.banners.store'), [
            'title' => null,
            'image_upload' => UploadedFile::fake()->create('wedding-editorial.webp', 10, 'image/webp'),
            'position' => 'home_wedding_editorial',
            'is_active' => 1,
        ])->assertRedirect(route('admin.banners.index'));

        $banner = Banner::where('position', 'home_wedding_editorial')->firstOrFail();
        $this->assertStringStartsWith('banners/', $banner->image);
        Storage::disk('public')->assertExists($banner->image);

        $this->actingAs($admin)->post(route('admin.banners.store'), [
            'position' => 'home_wedding_editorial',
            'image' => 'https://vimeo.com/123456789',
            'is_active' => 1,
        ])->assertSessionHasErrors('image');

        $this->actingAs($admin)->get(route('admin.banners.create'))
            ->assertOk()
            ->assertSee('home_wedding_editorial', false)
            ->assertSeeText('Wedding Editorial');
    }

    public function test_wedding_showcase_uses_only_active_products_in_the_active_wedding_collection_and_pages_them_by_four(): void
    {
        $category = Category::create([
            'name' => 'Wedding rings',
            'slug' => 'wedding-rings',
            'is_active' => true,
        ]);
        $inactiveCategory = Category::create([
            'name' => 'Inactive wedding rings',
            'slug' => 'inactive-wedding-rings',
            'is_active' => false,
        ]);
        $collection = Collection::create([
            'name' => 'Trang sức cưới',
            'slug' => 'trang-suc-cuoi',
            'is_active' => true,
        ]);
        $products = collect(range(1, 5))->map(fn (int $number) => Product::create([
            'category_id' => $category->id,
            'name' => 'Wedding ring '.$number,
            'slug' => 'wedding-ring-'.$number,
            'sku' => 'WEDDING-'.$number,
            'price' => 12000000 + $number,
            'stock' => 1,
            'is_active' => true,
            'sort_order' => $number,
        ]));
        $inactiveProduct = Product::create([
            'category_id' => $category->id,
            'name' => 'Inactive wedding ring',
            'slug' => 'inactive-wedding-ring',
            'sku' => 'WEDDING-INACTIVE',
            'price' => 12000000,
            'stock' => 1,
            'is_active' => false,
        ]);
        $inactiveCategoryProduct = Product::create([
            'category_id' => $inactiveCategory->id,
            'name' => 'Inactive category wedding ring',
            'slug' => 'inactive-category-wedding-ring',
            'sku' => 'WEDDING-INACTIVE-CATEGORY',
            'price' => 12000000,
            'stock' => 1,
            'is_active' => true,
        ]);
        $unrelatedProduct = Product::create([
            'category_id' => $category->id,
            'name' => 'Unrelated ring',
            'slug' => 'unrelated-ring',
            'sku' => 'WEDDING-UNRELATED',
            'price' => 12000000,
            'stock' => 1,
            'is_active' => true,
        ]);
        $collection->products()->attach($products->pluck('id')->all());
        $collection->products()->attach([$inactiveProduct->id, $inactiveCategoryProduct->id]);

        Banner::create([
            'title' => 'Live wedding editorial',
            'image' => 'banners/wedding-editorial-live.jpg',
            'position' => 'home_wedding_editorial',
            'is_active' => true,
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addDay(),
        ]);
        foreach ([
            ['image' => 'banners/wedding-editorial-inactive.jpg', 'is_active' => false],
            ['image' => 'banners/wedding-editorial-future.jpg', 'is_active' => true, 'starts_at' => now()->addDay()],
            ['image' => 'banners/wedding-editorial-expired.jpg', 'is_active' => true, 'ends_at' => now()->subMinute()],
        ] as $attributes) {
            Banner::create($attributes + [
                'title' => null,
                'position' => 'home_wedding_editorial',
            ]);
        }

        $content = $this->get(route('home'))->assertOk()->getContent();

        $this->assertStringContainsString('home-wedding-showcase', $content);
        $this->assertStringContainsString('/storage/banners/wedding-editorial-live.jpg', $content);
        $this->assertStringNotContainsString('/storage/banners/wedding-editorial-inactive.jpg', $content);
        $this->assertStringNotContainsString('/storage/banners/wedding-editorial-future.jpg', $content);
        $this->assertStringNotContainsString('/storage/banners/wedding-editorial-expired.jpg', $content);
        preg_match_all('/data-wedding-product-id="(\d+)"/', $content, $matches);
        $renderedProductIds = $matches[1];

        $this->assertCount(5, $renderedProductIds);
        $this->assertCount(5, array_unique($renderedProductIds));
        preg_match_all('/\sdata-wedding-page\s/', $content, $pageMatches);
        $this->assertCount(2, $pageMatches[0]);
        $this->assertStringContainsString('data-wedding-page-size="4"', $content);
        $this->assertStringContainsString('data-wedding-page-size="1"', $content);
        $this->assertStringContainsString('data-wedding-previous', $content);
        $this->assertStringContainsString('data-wedding-next', $content);
        foreach ($products as $product) {
            $this->assertContains((string) $product->id, $renderedProductIds);
        }
        $this->assertNotContains((string) $inactiveProduct->id, $renderedProductIds);
        $this->assertNotContains((string) $inactiveCategoryProduct->id, $renderedProductIds);
        $this->assertNotContains((string) $unrelatedProduct->id, $renderedProductIds);
    }

    public function test_wedding_showcase_hides_controls_for_one_page_and_excludes_an_inactive_collection(): void
    {
        $category = Category::create([
            'name' => 'Wedding rings',
            'slug' => 'wedding-rings',
            'is_active' => true,
        ]);
        $collection = Collection::create([
            'name' => 'Trang sức cưới',
            'slug' => 'trang-suc-cuoi',
            'is_active' => true,
        ]);
        $products = collect(range(1, 4))->map(fn (int $number) => Product::create([
            'category_id' => $category->id,
            'name' => 'One page wedding ring '.$number,
            'slug' => 'one-page-wedding-ring-'.$number,
            'sku' => 'ONE-PAGE-WEDDING-'.$number,
            'price' => 12000000 + $number,
            'stock' => 1,
            'is_active' => true,
            'sort_order' => $number,
        ]));
        $collection->products()->attach($products->pluck('id')->all());
        Banner::create([
            'title' => null,
            'image' => 'banners/wedding-editorial-live.jpg',
            'position' => 'home_wedding_editorial',
            'is_active' => true,
        ]);

        $this->get(route('home'))->assertOk()
            ->assertSee('home-wedding-showcase', false)
            ->assertDontSee('data-wedding-previous', false)
            ->assertDontSee('data-wedding-next', false);

        $collection->update(['is_active' => false]);

        $this->get(route('home'))->assertOk()
            ->assertDontSee('home-wedding-showcase', false);
    }

    public function test_homepage_testimonials_render_only_eligible_reviews_with_safe_identity_and_real_ratings(): void
    {
        $category = Category::create([
            'name' => 'Testimonial category',
            'slug' => 'testimonial-category',
            'is_active' => true,
        ]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Testimonial product',
            'slug' => 'testimonial-product',
            'sku' => 'TESTIMONIAL-PRODUCT',
            'price' => 10000000,
            'stock' => 1,
            'is_active' => true,
        ]);
        $eligibleUsers = collect([
            ['name' => 'Nguyễn Văn An', 'email' => 'nguyen.an@example.test'],
            ['name' => 'Trần Minh', 'email' => 'tran.minh@example.test'],
            ['name' => 'Lê Anh', 'email' => 'le.anh@example.test'],
            ['name' => 'Phạm Quỳnh', 'email' => 'pham.quynh@example.test'],
        ])->map(fn (array $attributes) => User::factory()->create($attributes + ['is_active' => true]));
        $eligibleUsers->each(function (User $user, int $index) use ($product): void {
            ProductReview::create([
                'product_id' => $product->id,
                'user_id' => $user->id,
                'rating' => $index === 1 ? 4 : 5,
                'comment' => 'Nhận xét hợp lệ '.$index,
                'is_active' => true,
            ]);
        });

        $hiddenUser = User::factory()->create(['email' => 'hidden@example.test']);
        ProductReview::create([
            'product_id' => $product->id,
            'user_id' => $hiddenUser->id,
            'rating' => 5,
            'comment' => 'Nhận xét bị ẩn',
            'is_active' => false,
        ]);
        $emptyCommentUser = User::factory()->create(['email' => 'empty@example.test']);
        ProductReview::create([
            'product_id' => $product->id,
            'user_id' => $emptyCommentUser->id,
            'rating' => 5,
            'comment' => '   ',
            'is_active' => true,
        ]);
        $lowRatingUser = User::factory()->create(['email' => 'low@example.test']);
        ProductReview::create([
            'product_id' => $product->id,
            'user_id' => $lowRatingUser->id,
            'rating' => 3,
            'comment' => 'Đánh giá ba sao',
            'is_active' => true,
        ]);
        $inactiveProduct = Product::create([
            'category_id' => $category->id,
            'name' => 'Inactive testimonial product',
            'slug' => 'inactive-testimonial-product',
            'sku' => 'INACTIVE-TESTIMONIAL-PRODUCT',
            'price' => 10000000,
            'stock' => 1,
            'is_active' => false,
        ]);
        $inactiveProductUser = User::factory()->create(['email' => 'inactive-product@example.test']);
        ProductReview::create([
            'product_id' => $inactiveProduct->id,
            'user_id' => $inactiveProductUser->id,
            'rating' => 5,
            'comment' => 'Sản phẩm đã ngừng hiển thị',
            'is_active' => true,
        ]);

        $content = $this->get(route('home'))->assertOk()->getContent();

        $this->assertStringContainsString('home-testimonials', $content);
        $this->assertStringContainsString('Nhận xét hợp lệ', $content);
        $this->assertStringContainsString('aria-label="4 trên 5 sao"', $content);
        $this->assertStringContainsString('>NA<', $content);
        $this->assertStringNotContainsString('Nhận xét bị ẩn', $content);
        $this->assertStringNotContainsString('Đánh giá ba sao', $content);
        $this->assertStringNotContainsString('Sản phẩm đã ngừng hiển thị', $content);
        $this->assertStringNotContainsString('hidden@example.test', $content);
        $this->assertStringNotContainsString('nguyen.an@example.test', $content);
        preg_match_all('/data-testimonial-selector/', $content, $selectors);
        $this->assertCount(3, $selectors[0]);
    }

    public function test_homepage_omits_testimonials_when_no_review_meets_the_visibility_criteria(): void
    {
        $category = Category::create([
            'name' => 'Testimonial category',
            'slug' => 'testimonial-category',
            'is_active' => true,
        ]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Testimonial product',
            'slug' => 'testimonial-product',
            'sku' => 'TESTIMONIAL-PRODUCT',
            'price' => 10000000,
            'stock' => 1,
            'is_active' => true,
        ]);
        $user = User::factory()->create();
        ProductReview::create([
            'product_id' => $product->id,
            'user_id' => $user->id,
            'rating' => 5,
            'comment' => '   ',
            'is_active' => true,
        ]);

        $this->get(route('home'))->assertOk()
            ->assertDontSee('home-testimonials', false);
    }

    public function test_empty_banner_title_does_not_render_an_empty_homepage_heading_wrapper(): void
    {
        Banner::create([
            'title' => null,
            'image' => 'banners/image-only.jpg',
            'position' => 'home',
            'is_active' => true,
        ]);

        $this->get(route('home'))->assertOk()
            ->assertDontSee('home-hero__content', false)
            ->assertDontSee('<h1></h1>', false);
    }

    public function test_placeholder_story_title_uses_the_safe_default_in_the_player_label(): void
    {
        Banner::create([
            'title' => '.',
            'image' => 'https://vimeo.com/123456789',
            'position' => 'home_story',
            'is_active' => true,
        ]);

        $content = $this->get(route('home'))->assertOk()->getContent();

        $this->assertStringContainsString('title="A LOVE STORY THAT STAYS"', $content);
        $this->assertStringNotContainsString('title="."', $content);
    }

    public function test_missing_or_inactive_campaign_uses_the_safe_video_fallback(): void
    {
        Banner::create([
            'title' => 'Hidden story',
            'image' => 'https://vimeo.com/123456789',
            'position' => 'home_story',
            'is_active' => false,
        ]);

        $this->get(route('home'))->assertOk()
            ->assertSeeText('VIDEO MEDIA REQUIRED')
            ->assertDontSee('player.vimeo.com/video/123456789', false);
    }

    private function admin(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::create(['name' => 'admin', 'guard_name' => 'web']));

        return $user;
    }
}
