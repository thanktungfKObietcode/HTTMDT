<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_admin(): void
    {
        $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
    }

    public function test_normal_user_cannot_access_admin(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('admin.dashboard'))->assertForbidden();
    }

    public function test_admin_role_can_access_dashboard(): void
    {
        $admin = $this->createAdmin();

        $response = $this->actingAs($admin)->get(route('admin.dashboard'));

        $response->assertOk();
        $response->assertSeeText('Dashboard');
        $response->assertSeeText($admin->email);
        $response->assertSee('action="' . route('logout') . '"', false);
    }

    public function test_dashboard_statistics_and_recent_orders_use_database_data(): void
    {
        $admin = $this->createAdmin();
        Product::create([
            'name' => 'Admin statistic product',
            'slug' => 'admin-statistic-product',
            'sku' => 'SKU-ADMIN-001',
            'price' => 250000,
            'is_active' => true,
            'stock' => 4,
        ]);
        Order::create([
            'user_id' => $admin->id,
            'order_number' => 'SA-ADMIN-001',
            'status' => 'pending',
            'payment_status' => 'paid',
            'customer_name' => $admin->name,
            'customer_phone' => '0900000000',
            'shipping_address' => 'Admin address',
            'subtotal' => 250000,
            'shipping_fee' => 0,
            'discount_amount' => 0,
            'total_amount' => 250000,
        ]);

        $response = $this->actingAs($admin)->get(route('admin.dashboard'));

        $response->assertOk();
        $response->assertSeeText('SA-ADMIN-001');
        $response->assertSeeText('250.000đ');
        $response->assertSeeText('Đơn chờ xử lý');
        $response->assertSeeText('Doanh thu đã thanh toán');
    }

    public function test_admin_dashboard_route_is_protected_on_direct_access(): void
    {
        $user = User::factory()->create();

        $this->get('/admin/dashboard')->assertRedirect(route('login'));
        $this->actingAs($user)->get('/admin/dashboard')->assertForbidden();
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
}