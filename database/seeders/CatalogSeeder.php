<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Collection;
use App\Models\Material;
use App\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Root categories
        |--------------------------------------------------------------------------
        */

        $categories = [
            'Nhẫn bạc' => 'nhan-bac',
            'Dây chuyền bạc' => 'day-chuyen-bac',
            'Lắc tay bạc' => 'lac-tay-bac',
            'Bông tai bạc' => 'bong-tai-bac',
        ];

        $root = Category::firstOrCreate(
            ['slug' => 'trang-suc-bac'],
            [
                'name' => 'Trang sức bạc',
                'description' => 'Các sản phẩm trang sức bạc',
                'depth' => 0,
                'lft' => 1,
                'rgt' => 0,
                'is_active' => true,
            ]
        );

        foreach ($categories as $name => $slug) {
            Category::firstOrCreate(
                ['slug' => $slug],
                [
                    'name' => $name,
                    'parent_id' => $root->id,
                    'depth' => 1,
                    'lft' => 0,
                    'rgt' => 0,
                    'is_active' => true,
                ]
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Collection
        |--------------------------------------------------------------------------
        */

        $collection = Collection::firstOrCreate(
            ['slug' => 'bo-suu-tap-moi'],
            [
                'name' => 'Bộ sưu tập mới',
                'description' => 'Những thiết kế mới nhất',
                'is_active' => true,
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | Material
        |--------------------------------------------------------------------------
        */

        $material = Material::where('code', 'silver_925')->first();

        /*
        |--------------------------------------------------------------------------
        | Products
        |--------------------------------------------------------------------------
        */

        $products = [
            [
                'name' => 'Nhẫn bạc 925',
                'slug' => 'nhan-bac-925',
                'sku' => 'NB-925-001',
                'price' => 890000,
                'category_id' => Category::where('slug', 'nhan-bac')->value('id') ?? $root->id,
            ],
            [
                'name' => 'Dây chuyền bạc 925',
                'slug' => 'day-chuyen-bac-925',
                'sku' => 'DC-925-001',
                'price' => 1290000,
                'category_id' => Category::where('slug', 'day-chuyen-bac')->value('id') ?? $root->id,
            ],
            [
                'name' => 'Lắc tay bạc 925',
                'slug' => 'lac-tay-bac-925',
                'sku' => 'LT-925-001',
                'price' => 750000,
                'category_id' => Category::where('slug', 'lac-tay-bac')->value('id') ?? $root->id,
            ],
            [
                'name' => 'Bông tai bạc 925',
                'slug' => 'bong-tai-bac-925',
                'sku' => 'BT-925-001',
                'price' => 590000,
                'category_id' => Category::where('slug', 'bong-tai-bac')->value('id') ?? $root->id,
            ],
        ];

        foreach ($products as $productData) {
            $product = Product::updateOrCreate(
                ['sku' => $productData['sku']],
                [
                    'name' => $productData['name'],
                    'slug' => $productData['slug'],
                    'sku' => $productData['sku'],
                    'price' => $productData['price'],
                    'category_id' => $productData['category_id'],
                    'material_id' => $material?->id,
                    'is_active' => true,
                    'featured' => true,
                    'stock' => 20,
                    'seo_title' => $productData['name'],
                    'seo_description' => $productData['name'] . ' chất lượng cao.',
                ]
            );

            $product->collections()->syncWithoutDetaching([
                $collection->id,
            ]);
        }
    }
}