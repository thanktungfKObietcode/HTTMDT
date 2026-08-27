<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Role;
use App\Models\ShippingMethod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminShippingManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('admin.shipping.index'))->assertRedirect(route('login'));
    }

    public function test_normal_user_gets_403(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $this->actingAs($user)->get(route('admin.shipping.index'))->assertForbidden();
    }

    public function test_inactive_admin_gets_403(): void
    {
        $admin = $this->createAdmin(false);
        $this->actingAs($admin)->get(route('admin.shipping.index'))->assertForbidden();
    }

    public function test_active_admin_can_access_shipping_list(): void
    {
        $admin = $this->createAdmin();
        $this->actingAs($admin)->get(route('admin.shipping.index'))->assertOk();
    }

    public function test_shipping_list_shows_shipping_methods(): void
    {
        $admin = $this->createAdmin();
        ShippingMethod::create($this->methodData(['name' => 'Giao hỏa tốc']));

        $this->actingAs($admin)
            ->get(route('admin.shipping.index'))
            ->assertOk()
            ->assertSeeText('Giao hỏa tốc');
    }

    public function test_search_by_name_returns_matching_method(): void
    {
        $admin = $this->createAdmin();
        ShippingMethod::create($this->methodData(['name' => 'FINDME', 'code' => 'FINDME_CODE']));
        ShippingMethod::create($this->methodData(['name' => 'OTHER', 'code' => 'OTHER_CODE']));

        $this->actingAs($admin)
            ->get(route('admin.shipping.index', ['q' => 'FINDME']))
            ->assertOk()
            ->assertSeeText('FINDME')
            ->assertDontSeeText('OTHER');
    }

    public function test_admin_can_create_shipping_method(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin)
            ->post(route('admin.shipping.store'), [
                'name'               => 'New Method',
                'code'               => 'NEW_METHOD',
                'base_fee'           => '15000',
                'fee_per_km'         => '1000',
                'estimated_days_min' => '1',
                'estimated_days_max' => '3',
                'is_active'          => '1',
            ])
            ->assertRedirect(route('admin.shipping.index'));

        $this->assertDatabaseHas('shipping_methods', [
            'name'     => 'New Method',
            'code'     => 'NEW_METHOD',
            'base_fee' => '15000.00',
        ]);
    }

    public function test_admin_can_update_shipping_method(): void
    {
        $admin = $this->createAdmin();
        $method = ShippingMethod::create($this->methodData(['code' => 'UPDATEME', 'base_fee' => 10000]));

        $this->actingAs($admin)
            ->put(route('admin.shipping.update', $method), [
                'name'               => 'Updated Name',
                'code'               => 'UPDATEME',
                'base_fee'           => '25000',
                'fee_per_km'         => '1000',
                'is_active'          => '1',
            ])
            ->assertRedirect(route('admin.shipping.index'));

        $this->assertDatabaseHas('shipping_methods', ['code' => 'UPDATEME', 'base_fee' => '25000.00']);
    }

    public function test_admin_can_deactivate_shipping_method(): void
    {
        $admin = $this->createAdmin();
        $method = ShippingMethod::create($this->methodData(['code' => 'DEACTIVATE', 'is_active' => true]));

        $this->actingAs($admin)
            ->from(route('admin.shipping.index'))
            ->post(route('admin.shipping.deactivate', $method))
            ->assertRedirect();

        $this->assertFalse((bool) $method->fresh()->is_active);
    }

    public function test_admin_can_activate_shipping_method(): void
    {
        $admin = $this->createAdmin();
        $method = ShippingMethod::create($this->methodData(['code' => 'REACTIVATE', 'is_active' => false]));

        $this->actingAs($admin)
            ->from(route('admin.shipping.index'))
            ->post(route('admin.shipping.activate', $method))
            ->assertRedirect();

        $this->assertTrue((bool) $method->fresh()->is_active);
    }

    public function test_admin_can_delete_unused_shipping_method(): void
    {
        $admin = $this->createAdmin();
        $method = ShippingMethod::create($this->methodData(['code' => 'DELETEOK']));

        $this->actingAs($admin)
            ->delete(route('admin.shipping.destroy', $method))
            ->assertRedirect(route('admin.shipping.index'));

        $this->assertDatabaseMissing('shipping_methods', ['id' => $method->id]);
    }

    public function test_admin_cannot_delete_shipping_method_with_orders(): void
    {
        $admin = $this->createAdmin();
        $method = ShippingMethod::create($this->methodData(['code' => 'INUSE']));

        Order::create([
            'user_id'            => $admin->id,
            'shipping_method_id' => $method->id,
            'order_number'       => 'TEST-0001',
            'customer_name'      => 'Test',
            'customer_phone'     => '1234567890',
            'shipping_address'   => 'Test',
            'subtotal'           => 100000,
            'total_amount'       => 100000,
        ]);

        $this->actingAs($admin)
            ->from(route('admin.shipping.index'))
            ->delete(route('admin.shipping.destroy', $method))
            ->assertRedirect()
            ->assertSessionHasErrors('delete');

        $this->assertDatabaseHas('shipping_methods', ['id' => $method->id]);
    }

    // --- Helpers ---

    private function createAdmin(bool $is_active = true): User
    {
        $admin = User::factory()->create([
            'password'  => Hash::make('admin-password'),
            'is_active' => $is_active,
        ]);
        $role = Role::firstOrCreate(['name' => 'admin'], ['guard_name' => 'web']);
        $admin->roles()->attach($role->id);
        return $admin;
    }

    private function methodData(array $override = []): array
    {
        return array_merge([
            'name'               => 'Test Method',
            'code'               => 'TEST_METHOD',
            'description'        => null,
            'base_fee'           => 15000,
            'fee_per_km'         => 0,
            'estimated_days_min' => null,
            'estimated_days_max' => null,
            'is_active'          => true,
        ], $override);
    }
}
