<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Material;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_listing_uses_real_database_data(): void
    {
        $material = Material::create([
            'name' => 'Bạc 925',
            'code' => 'silver_925',
            'purity' => '925',
            'description' => 'Bạc 925',
            'is_active' => true,
        ]);

        $category = Category::create([
            'name' => 'Nhẫn bạc',
            'slug' => 'nhan-bac',
            'description' => 'Nhẫn bạc',
            'depth' => 1,
            'lft' => 1,
            'rgt' => 2,
            'is_active' => true,
        ]);

        Product::create([
            'category_id' => $category->id,
            'material_id' => $material->id,
            'name' => 'Nhẫn bạc 925',
            'slug' => 'nhan-bac-925',
            'short_description' => 'Mẫu nhẫn bạc cho mọi ngày.',
            'description' => 'Mô tả dài',
            'price' => 890000,
            'sale_price' => 790000,
            'sku' => 'NB-925-001',
            'featured_image' => 'demo.jpg',
            'featured' => true,
            'is_active' => true,
            'stock' => 10,
            'views' => 0,
            'rating_count' => 1,
            'average_rating' => 5,
        ]);

        $response = $this->get('/san-pham?q=nhan&category=nhan-bac&sort=price_asc');

        $response->assertStatus(200);
        $response->assertSee('Nhẫn bạc 925');
        $response->assertSee('nhan-bac-925');
    }

    public function test_product_detail_uses_real_database_record(): void
    {
        $material = Material::create([
            'name' => 'Bạc 925',
            'code' => 'silver_925',
            'purity' => '925',
            'description' => 'Bạc 925',
            'is_active' => true,
        ]);

        $category = Category::create([
            'name' => 'Dây chuyền bạc',
            'slug' => 'day-chuyen-bac',
            'description' => 'Dây chuyền bạc',
            'depth' => 1,
            'lft' => 1,
            'rgt' => 2,
            'is_active' => true,
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'material_id' => $material->id,
            'name' => 'Dây chuyền bạc 925',
            'slug' => 'day-chuyen-bac-925',
            'short_description' => 'Dây chuyền bạc tinh tế.',
            'description' => 'Mô tả dài',
            'price' => 1290000,
            'sale_price' => null,
            'sku' => 'DC-925-001',
            'featured_image' => 'demo-2.jpg',
            'featured' => true,
            'is_active' => true,
            'stock' => 10,
            'views' => 0,
            'rating_count' => 1,
            'average_rating' => 4,
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'DC-925-001-S',
            'size' => 'S',
            'price' => 1390000,
            'stock' => 5,
            'is_active' => true,
        ]);

        $response = $this->get('/san-pham/' . $product->slug);

        $response->assertStatus(200);
        $response->assertSee('Dây chuyền bạc 925');
        $response->assertSee('Bạc 925');
        $response->assertSee('name="product_id"', false);
        $response->assertSee('name="product_variant_id"', false);
        $response->assertSee('value="' . $variant->id . '"', false);
        $response->assertSee('action="' . route('cart.add') . '"', false);
    }
}
