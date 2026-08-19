<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Material;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminProductTest extends TestCase
{
    use RefreshDatabase;

    private Category $category;

    private Material $material;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = Category::create([
            'name' => 'Nhẫn admin',
            'slug' => 'nhan-admin',
            'is_active' => true,
        ]);
        $this->material = Material::create([
            'name' => 'Bạc admin',
            'code' => 'bac-admin',
            'is_active' => true,
        ]);
    }

    public function test_guest_and_normal_user_are_blocked(): void
    {
        $this->get(route('admin.products.index'))->assertRedirect(route('login'));

        $user = User::factory()->create();
        $this->actingAs($user)->get(route('admin.products.index'))->assertForbidden();
    }

    public function test_admin_can_view_search_and_filter_products(): void
    {
        $admin = $this->createAdmin();
        $active = $this->createProduct('Silver Ring', true);
        $inactive = $this->createProduct('Hidden Ring', false);

        $this->actingAs($admin)
            ->get(route('admin.products.index', ['q' => 'Silver', 'category_id' => $this->category->id, 'status' => 'active']))
            ->assertOk()
            ->assertSeeText($active->name)
            ->assertDontSeeText($inactive->name);
    }

    public function test_admin_can_create_product(): void
    {
        $admin = $this->createAdmin();

        $response = $this->actingAs($admin)->post(route('admin.products.store'), $this->productPayload());

        $response->assertRedirect(route('admin.products.index'));
        $this->assertDatabaseHas('products', [
            'name' => 'Created product',
            'slug' => 'created-product',
            'sku' => 'SKU-CREATED-001',
            'category_id' => $this->category->id,
            'is_active' => true,
        ]);
    }

    public function test_product_validation_rejects_invalid_prices_stock_and_duplicate_sku(): void
    {
        $admin = $this->createAdmin();
        $existingProduct = $this->createProduct('Existing product');

        $response = $this->actingAs($admin)
            ->from(route('admin.products.create'))
            ->post(route('admin.products.store'), [
                'name' => '',
                'slug' => 'invalid-product',
                'sku' => $existingProduct->sku,
                'price' => -1,
                'sale_price' => 10,
                'stock' => -1,
            ]);

        $response->assertRedirect(route('admin.products.create'))
            ->assertSessionHasErrors(['name', 'sku', 'price', 'sale_price', 'stock']);
    }

    public function test_admin_can_edit_and_deactivate_product(): void
    {
        $admin = $this->createAdmin();
        $product = $this->createProduct('Before update', true);

        $this->actingAs($admin)->put(route('admin.products.update', $product), array_merge($this->productPayload(), [
            'name' => 'After update',
            'slug' => 'after-update',
            'sku' => 'SKU-AFTER-001',
            'is_active' => 0,
        ]))->assertRedirect(route('admin.products.index'));

        $product->refresh();
        $this->assertSame('After update', $product->name);
        $this->assertFalse($product->is_active);
    }

    public function test_admin_can_create_and_edit_variant(): void
    {
        $admin = $this->createAdmin();
        $product = $this->createProduct('Variant product');

        $this->actingAs($admin)->post(route('admin.products.variants.store', $product), [
            'sku' => 'VARIANT-001',
            'size' => 'S',
            'price' => 120000,
            'stock' => 5,
            'is_active' => 1,
        ])->assertRedirect(route('admin.products.variants.index', $product));

        $variant = ProductVariant::where('sku', 'VARIANT-001')->firstOrFail();
        $this->assertSame($product->id, $variant->product_id);

        $this->actingAs($admin)->put(route('admin.products.variants.update', [$product, $variant]), [
            'sku' => 'VARIANT-UPDATED',
            'size' => 'M',
            'price' => 130000,
            'stock' => 7,
            'is_active' => 1,
        ])->assertRedirect(route('admin.products.variants.index', $product));

        $variant->refresh();
        $this->assertSame('VARIANT-UPDATED', $variant->sku);
        $this->assertSame('M', $variant->size);
    }

    public function test_variant_validation_rejects_invalid_values_and_duplicate_sku(): void
    {
        $admin = $this->createAdmin();
        $product = $this->createProduct('Variant validation');
        ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'VARIANT-DUPLICATE',
            'price' => 100000,
            'stock' => 1,
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin)
            ->from(route('admin.products.variants.create', $product))
            ->post(route('admin.products.variants.store', $product), [
                'sku' => 'VARIANT-DUPLICATE',
                'price' => -1,
                'sale_price' => 2,
                'stock' => -1,
            ]);

        $response->assertRedirect(route('admin.products.variants.create', $product))
            ->assertSessionHasErrors(['sku', 'price', 'sale_price', 'stock']);
    }

    public function test_variant_from_another_product_cannot_be_manipulated(): void
    {
        $admin = $this->createAdmin();
        $product = $this->createProduct('Owner product');
        $otherProduct = $this->createProduct('Other product');
        $variant = ProductVariant::create([
            'product_id' => $otherProduct->id,
            'sku' => 'VARIANT-OTHER',
            'price' => 100000,
            'stock' => 2,
            'is_active' => true,
        ]);

        $this->actingAs($admin)->get(route('admin.products.variants.edit', [$product, $variant]))->assertNotFound();
        $this->actingAs($admin)->put(route('admin.products.variants.update', [$product, $variant]), [
            'sku' => 'VARIANT-HACKED',
            'price' => 1,
            'stock' => 1,
        ])->assertNotFound();
        $this->actingAs($admin)->delete(route('admin.products.variants.destroy', [$product, $variant]))->assertNotFound();

        $this->assertDatabaseHas('product_variants', ['id' => $variant->id, 'sku' => 'VARIANT-OTHER']);
    }

    private function createAdmin(): User
    {
        $admin = User::factory()->create([
            'password' => Hash::make('admin-password'),
            'is_active' => true,
        ]);
        $role = Role::create(['name' => 'admin', 'guard_name' => 'web']);
        $admin->roles()->attach($role->id);

        return $admin;
    }

    private function createProduct(string $name, bool $active = true): Product
    {
        static $number = 0;
        $number++;

        return Product::create([
            'category_id' => $this->category->id,
            'material_id' => $this->material->id,
            'name' => $name,
            'slug' => 'product-' . $number,
            'sku' => 'SKU-ADMIN-' . str_pad((string) $number, 3, '0', STR_PAD_LEFT),
            'price' => 100000,
            'stock' => 5,
            'is_active' => $active,
        ]);
    }

    private function productPayload(): array
    {
        return [
            'name' => 'Created product',
            'slug' => 'created-product',
            'sku' => 'SKU-CREATED-001',
            'category_id' => $this->category->id,
            'material_id' => $this->material->id,
            'price' => 200000,
            'sale_price' => 180000,
            'stock' => 10,
            'is_active' => 1,
            'featured' => 1,
        ];
    }
}