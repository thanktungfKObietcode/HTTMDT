<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FineJewelryNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_three_level_categories_render_in_desktop_and_mobile_navigation(): void
    {
        $gold = $this->category('Trang sức vàng', 'trang-suc-vang');
        $diamond = $this->category('Trang sức kim cương', 'trang-suc-kim-cuong', $gold);
        $ring = $this->category('Nhẫn', 'nhan-kim-cuong', $diamond);
        $hiddenGroup = $this->category('Ẩn khỏi điều hướng', 'an-khoi-dieu-huong', $gold, false);
        $this->category('Nhẫn ẩn', 'nhan-an', $hiddenGroup);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('data-mega-menu', false)
            ->assertSee($gold->name)
            ->assertSee($diamond->name)
            ->assertSee($ring->name)
            ->assertSee(route('products.index', ['category' => $ring->slug]), false)
            ->assertSee('mobile-category-tree', false)
            ->assertDontSee($hiddenGroup->name);
    }

    public function test_parent_category_filters_include_active_descendants_without_duplicates(): void
    {
        $gold = $this->category('Trang sức vàng', 'trang-suc-vang');
        $diamond = $this->category('Trang sức kim cương', 'trang-suc-kim-cuong', $gold);
        $ring = $this->category('Nhẫn kim cương', 'nhan-kim-cuong', $diamond);
        $earrings = $this->category('Bông tai kim cương', 'bong-tai-kim-cuong', $diamond);
        $inactiveGroup = $this->category('Đá màu ẩn', 'da-mau-an', $gold, false);
        $inactiveLeaf = $this->category('Nhẫn đá màu ẩn', 'nhan-da-mau-an', $inactiveGroup);

        $ringProduct = $this->product('diamond-ring', $ring);
        $earringProduct = $this->product('diamond-earrings', $earrings);
        $hiddenProduct = $this->product('hidden-gem-ring', $inactiveLeaf);

        $diamondResponse = $this->get(route('products.index', ['category' => $diamond->slug]));
        $diamondResponse->assertOk()
            ->assertSee($ringProduct->name)
            ->assertSee($earringProduct->name)
            ->assertDontSee($hiddenProduct->name);
        $this->assertSame(2, $diamondResponse->viewData('products')->total());

        $rootResponse = $this->get(route('products.index', ['category' => $gold->slug]));
        $rootResponse->assertOk()
            ->assertSee($ringProduct->name)
            ->assertSee($earringProduct->name)
            ->assertDontSee($hiddenProduct->name);
        $this->assertSame(2, $rootResponse->viewData('products')->total());

        $this->get(route('products.index', ['category' => $ring->slug]))
            ->assertOk()
            ->assertSee($ringProduct->name)
            ->assertDontSee($earringProduct->name);
    }

    public function test_category_urls_and_product_breadcrumb_follow_the_active_hierarchy(): void
    {
        $gold = $this->category('Trang sức vàng', 'trang-suc-vang');
        $diamond = $this->category('Trang sức kim cương', 'trang-suc-kim-cuong', $gold);
        $ring = $this->category('Nhẫn', 'nhan-kim-cuong', $diamond);
        $product = $this->product('diamond-ring-breadcrumb', $ring);

        $this->get(route('products.index', ['category' => $gold->slug]))->assertOk();
        $this->get(route('products.index', ['category' => $diamond->slug]))->assertOk();
        $this->get(route('products.index', ['category' => $ring->slug]))->assertOk();

        $this->get(route('product.show', $product->slug))
            ->assertOk()
            ->assertSeeText('Trang chủ')
            ->assertSee(route('products.index', ['category' => $gold->slug]), false)
            ->assertSee(route('products.index', ['category' => $diamond->slug]), false)
            ->assertSee(route('products.index', ['category' => $ring->slug]), false);
    }

    private function category(string $name, string $slug, ?Category $parent = null, bool $isActive = true): Category
    {
        return Category::create([
            'name' => $name,
            'slug' => $slug,
            'parent_id' => $parent?->id,
            'is_active' => $isActive,
        ]);
    }

    private function product(string $slug, Category $category): Product
    {
        return Product::create([
            'category_id' => $category->id,
            'name' => str_replace('-', ' ', $slug),
            'slug' => $slug,
            'sku' => strtoupper($slug),
            'price' => '1500000.00',
            'stock' => 2,
            'is_active' => true,
        ]);
    }
}
