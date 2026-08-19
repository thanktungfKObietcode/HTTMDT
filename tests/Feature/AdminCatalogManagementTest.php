<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Collection;
use App\Models\Material;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminCatalogManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_and_normal_user_are_blocked_from_catalog_admin(): void
    {
        $this->get(route('admin.categories.index'))->assertRedirect(route('login'));

        $user = User::factory()->create();
        $this->actingAs($user)->get(route('admin.collections.index'))->assertForbidden();
        $this->actingAs($user)->get(route('admin.materials.index'))->assertForbidden();
    }

    public function test_admin_can_create_update_and_search_category(): void
    {
        $admin = $this->createAdmin();
        $payload = ['name' => 'Nhẫn bạc', 'slug' => 'nhan-bac-admin', 'description' => 'Danh mục nhẫn', 'is_active' => 1];

        $this->actingAs($admin)->post(route('admin.categories.store'), $payload)
            ->assertRedirect(route('admin.categories.index'));

        $category = Category::where('slug', 'nhan-bac-admin')->firstOrFail();
        $this->actingAs($admin)->put(route('admin.categories.update', $category), [
            'name' => 'Nhẫn bạc mới',
            'slug' => 'nhan-bac-moi',
            'is_active' => 1,
        ])->assertRedirect(route('admin.categories.index'));

        $this->actingAs($admin)->get(route('admin.categories.index', ['q' => 'Nhẫn bạc mới']))
            ->assertOk()
            ->assertSeeText('Nhẫn bạc mới');
    }

    public function test_category_validation_and_self_parent_are_rejected(): void
    {
        $admin = $this->createAdmin();
        $category = Category::create(['name' => 'Existing', 'slug' => 'existing-category', 'is_active' => true]);

        $this->actingAs($admin)->from(route('admin.categories.create'))
            ->post(route('admin.categories.store'), ['name' => '', 'slug' => ''])
            ->assertSessionHasErrors(['name', 'slug']);

        $this->actingAs($admin)->from(route('admin.categories.edit', $category))
            ->put(route('admin.categories.update', $category), [
                'name' => 'Existing',
                'slug' => 'existing-category',
                'parent_id' => $category->id,
            ])->assertSessionHasErrors('parent_id');
    }

    public function test_referenced_category_is_deactivated_not_deleted(): void
    {
        $admin = $this->createAdmin();
        $category = Category::create(['name' => 'Referenced category', 'slug' => 'referenced-category', 'is_active' => true]);
        $product = $this->createProduct($category, null);

        $this->actingAs($admin)->delete(route('admin.categories.destroy', $category))
            ->assertRedirect();

        $category->refresh();
        $this->assertTrue($category->exists);
        $this->assertFalse($category->is_active);
        $this->assertSame($category->id, $product->refresh()->category_id);
    }

    public function test_unreferenced_category_can_be_deleted(): void
    {
        $admin = $this->createAdmin();
        $category = Category::create(['name' => 'Disposable', 'slug' => 'disposable-category', 'is_active' => true]);

        $this->actingAs($admin)->delete(route('admin.categories.destroy', $category));

        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
    }

    public function test_collection_crud_and_referenced_collection_is_deactivated(): void
    {
        $admin = $this->createAdmin();
        $collection = Collection::create(['name' => 'Moonlight', 'slug' => 'moonlight', 'is_active' => true]);

        $this->actingAs($admin)->put(route('admin.collections.update', $collection), [
            'name' => 'Moonlight Updated',
            'slug' => 'moonlight-updated',
            'is_active' => 1,
        ])->assertRedirect(route('admin.collections.index'));

        $product = $this->createProduct(null, $collection);
        $this->actingAs($admin)->delete(route('admin.collections.destroy', $collection))->assertRedirect();

        $collection->refresh();
        $this->assertTrue($collection->exists);
        $this->assertFalse($collection->is_active);
        $this->assertTrue($collection->products()->whereKey($product->id)->exists());
    }

    public function test_material_crud_validation_and_referenced_material_is_deactivated(): void
    {
        $admin = $this->createAdmin();
        $material = Material::create(['name' => 'Bạc 925', 'code' => 'silver-925', 'purity' => '92.5%', 'is_active' => true]);

        $this->actingAs($admin)->put(route('admin.materials.update', $material), [
            'name' => 'Bạc 925 Updated',
            'code' => 'silver-925-updated',
            'purity' => '92.5%',
            'is_active' => 1,
        ])->assertRedirect(route('admin.materials.index'));

        $product = $this->createProduct(null, null, $material);
        $this->actingAs($admin)->delete(route('admin.materials.destroy', $material))->assertRedirect();

        $material->refresh();
        $this->assertTrue($material->exists);
        $this->assertFalse($material->is_active);
        $this->assertSame($material->id, $product->refresh()->material_id);
    }

    public function test_duplicate_material_data_is_rejected(): void
    {
        $admin = $this->createAdmin();
        Material::create(['name' => 'Bạc trùng', 'code' => 'duplicate-code', 'is_active' => true]);

        $this->actingAs($admin)->from(route('admin.materials.create'))
            ->post(route('admin.materials.store'), ['name' => 'Bạc trùng', 'code' => 'duplicate-code'])
            ->assertSessionHasErrors(['name', 'code']);
    }

    private function createAdmin(): User
    {
        $admin = User::factory()->create(['password' => Hash::make('admin-password'), 'is_active' => true]);
        $role = Role::create(['name' => 'admin', 'guard_name' => 'web']);
        $admin->roles()->attach($role->id);

        return $admin;
    }

    private function createProduct(?Category $category, ?Collection $collection, ?Material $material = null): Product
    {
        static $number = 0;
        $number++;

        $product = Product::create([
            'category_id' => $category?->id,
            'material_id' => $material?->id,
            'name' => 'Referenced product ' . $number,
            'slug' => 'referenced-product-' . $number,
            'sku' => 'REFERENCED-SKU-' . $number,
            'price' => 100000,
            'stock' => 2,
            'is_active' => true,
        ]);

        if ($collection) {
            $product->collections()->attach($collection->id);
        }

        return $product;
    }
}