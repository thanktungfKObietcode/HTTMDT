<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Permission;
use App\Models\Refund;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PhaseThreeAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_without_permissions_can_reach_every_existing_admin_module(): void
    {
        $admin = $this->userWithRole('admin');
        $role = $admin->roles()->firstOrFail();

        $routes = [
            'admin.dashboard',
            'admin.products.index',
            'admin.categories.index',
            'admin.collections.index',
            'admin.materials.index',
            'admin.orders.index',
            'admin.coupons.index',
            'admin.shipping.index',
            'admin.users.index',
            'admin.roles.index',
        ];

        foreach ($routes as $routeName) {
            $this->actingAs($admin)->get(route($routeName))->assertOk();
        }

        $this->assertTrue($admin->hasPermission('permission.not.assigned'));
        $this->assertSame(0, $role->permissions()->count());
    }

    public function test_staff_can_view_allowed_module_but_view_permission_cannot_mutate_it(): void
    {
        $staff = $this->userWithRole('staff', ['products.view']);

        $this->actingAs($staff)->get(route('admin.products.index'))->assertOk();
        $this->actingAs($staff)->get(route('admin.products.create'))->assertForbidden();
        $this->actingAs($staff)->post(route('admin.products.store'))->assertForbidden();
        $this->actingAs($staff)->get(route('admin.categories.create'))->assertForbidden();
    }

    public function test_staff_direct_url_without_permission_is_forbidden(): void
    {
        $staff = $this->userWithRole('staff');

        $this->actingAs($staff)->get('/admin/orders')->assertForbidden();
        $this->actingAs($staff)->get('/admin/coupons')->assertForbidden();
        $this->actingAs($staff)->get('/admin/roles')->assertForbidden();
    }

    public function test_staff_refund_action_requires_orders_refund_permission(): void
    {
        $staff = $this->userWithRole('staff');
        $refund = $this->createRefund();

        $this->actingAs($staff)->post(route('admin.refunds.approve', $refund))->assertForbidden();

        $this->grantPermission($staff->roles()->where('name', 'staff')->firstOrFail(), 'orders.refund');
        $staff->unsetRelation('roles');

        $this->actingAs($staff)
            ->post(route('admin.refunds.approve', $refund))
            ->assertRedirect();
    }

    public function test_customer_and_vendor_are_denied_backoffice_even_when_role_has_permission(): void
    {
        $customer = $this->userWithRole('customer', ['products.view']);
        $vendor = $this->userWithRole('vendor', ['products.view']);

        $this->actingAs($customer)->get(route('admin.products.index'))->assertForbidden();
        $this->actingAs($vendor)->get(route('admin.products.index'))->assertForbidden();
        $this->assertFalse($customer->hasPermission('products.view'));
        $this->assertFalse($vendor->hasPermission('products.view'));
    }

    public function test_staff_inherits_permission_from_any_assigned_role(): void
    {
        $staff = $this->userWithRole('staff');
        $secondaryRole = Role::create(['name' => 'catalog-assistant', 'guard_name' => 'web']);
        $this->grantPermission($secondaryRole, 'products.view');
        $staff->roles()->attach($secondaryRole);

        $this->actingAs($staff)->get(route('admin.products.index'))->assertOk();
    }

    public function test_staff_login_redirects_to_first_permitted_backoffice_route(): void
    {
        $staff = $this->userWithRole('staff', ['products.view'], [
            'email' => 'staff@example.com',
            'password' => Hash::make('password123'),
        ]);

        $this->post(route('login.store'), [
            'email' => $staff->email,
            'password' => 'password123',
        ])->assertRedirect(route('admin.products.index'));
    }

    public function test_registration_assigns_only_customer_role_and_ignores_privilege_input(): void
    {
        $customerRole = Role::create(['name' => 'customer', 'guard_name' => 'web']);
        $adminRole = Role::create(['name' => 'admin', 'guard_name' => 'web']);
        $staffRole = Role::create(['name' => 'staff', 'guard_name' => 'web']);
        $vendorRole = Role::create(['name' => 'vendor', 'guard_name' => 'web']);

        $this->post(route('register.store'), [
            'name' => 'Safe Customer',
            'email' => 'safe-customer@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role_id' => $adminRole->id,
            'roles' => [$adminRole->id, $staffRole->id, $vendorRole->id],
            'is_active' => false,
        ])->assertRedirect(route('home'));

        $user = User::query()->where('email', 'safe-customer@example.com')->firstOrFail();
        $this->assertTrue($user->is_active);
        $this->assertEquals([$customerRole->id], $user->roles()->pluck('roles.id')->all());
    }

    public function test_registration_fails_closed_and_rolls_back_when_customer_role_is_missing(): void
    {
        $this->post(route('register.store'), [
            'name' => 'No Role Customer',
            'email' => 'no-role@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertSessionHasErrors('registration');

        $this->assertDatabaseMissing('users', ['email' => 'no-role@example.com']);
        $this->assertGuest();
    }

    public function test_inactive_customer_existing_session_is_blocked_from_all_authenticated_flows(): void
    {
        $customer = User::factory()->create(['is_active' => false]);
        $order = $this->createOrder($customer);

        $requests = [
            fn () => $this->get(route('cart.index')),
            fn () => $this->get(route('checkout.index')),
            fn () => $this->get(route('order.show', $order)),
            fn () => $this->get(route('address.index')),
            fn () => $this->post(route('payment.refund', $order), ['amount' => '1.00']),
            fn () => $this->get(route('account.index')),
        ];

        foreach ($requests as $request) {
            $this->actingAs($customer);
            $request()->assertRedirect(route('login'));
            $this->assertGuest();
        }
    }

    public function test_inactive_staff_and_admin_existing_sessions_are_logged_out_of_backoffice(): void
    {
        $staff = $this->userWithRole('staff', ['dashboard.view'], ['is_active' => false]);
        $admin = $this->userWithRole('admin', [], ['is_active' => false]);

        $this->actingAs($staff)->get(route('admin.dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
        $this->actingAs($admin)->get(route('admin.dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_admin_can_convert_customer_to_staff(): void
    {
        $admin = $this->userWithRole('admin');
        $this->role('customer');
        $staffRole = $this->role('staff');
        $customer = $this->userWithRole('customer');

        $this->actingAs($admin)
            ->put(route('admin.users.roles.update', $customer), ['roles' => [$staffRole->id]])
            ->assertRedirect();

        $this->assertTrue($customer->fresh()->hasRole('staff'));
        $this->assertFalse($customer->fresh()->hasRole('customer'));
    }

    public function test_staff_manager_cannot_grant_admin_role(): void
    {
        $this->role('admin');
        $manager = $this->userWithRole('staff', ['staff.manage']);
        $customer = $this->userWithRole('customer');
        $adminRole = $this->role('admin');

        $this->actingAs($manager)
            ->put(route('admin.users.roles.update', $customer), ['roles' => [$adminRole->id]])
            ->assertForbidden();

        $this->assertTrue($customer->fresh()->hasRole('customer'));
        $this->assertFalse($customer->fresh()->hasRole('admin'));
    }

    public function test_customer_update_permission_cannot_manage_staff_or_admin_accounts(): void
    {
        $this->role('admin');
        $employee = $this->userWithRole('staff', ['customers.update']);
        $customer = $this->userWithRole('customer');
        $otherStaff = $this->userWithRole('staff');
        $admin = $this->userWithRole('admin');

        $this->actingAs($employee)->post(route('admin.users.deactivate', $customer))->assertRedirect();
        $this->assertFalse($customer->fresh()->is_active);

        $this->actingAs($employee)->post(route('admin.users.deactivate', $otherStaff))->assertForbidden();
        $this->actingAs($employee)->post(route('admin.users.deactivate', $admin))->assertForbidden();
        $this->assertTrue($otherStaff->fresh()->is_active);
        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_staff_manager_without_customer_view_only_sees_staff_accounts(): void
    {
        $manager = $this->userWithRole('staff', ['staff.manage']);
        $staff = $this->userWithRole('staff', [], ['name' => 'Visible Employee']);
        $customer = $this->userWithRole('customer', [], ['name' => 'Hidden Customer']);

        $this->actingAs($manager)
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertSeeText($staff->name)
            ->assertDontSeeText($customer->name);
    }

    public function test_admin_cannot_remove_own_or_last_active_admin_role(): void
    {
        $admin = $this->userWithRole('admin');
        $customerRole = $this->role('customer');

        $this->actingAs($admin)
            ->from(route('admin.users.show', $admin))
            ->put(route('admin.users.roles.update', $admin), ['roles' => [$customerRole->id]])
            ->assertSessionHasErrors('roles');

        $this->assertTrue($admin->fresh()->hasRole('admin'));
        $this->assertSame(1, User::query()->where('is_active', true)
            ->whereHas('roles', fn ($query) => $query->where('name', 'admin'))->count());
    }

    public function test_admin_cannot_deactivate_self_and_peer_deactivation_preserves_active_admin(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->post(route('admin.users.deactivate', $admin))
            ->assertSessionHasErrors('status');
        $this->assertTrue($admin->fresh()->is_active);

        $peer = $this->userWithRole('admin');
        $this->actingAs($admin)->post(route('admin.users.deactivate', $peer))->assertRedirect();
        $this->assertFalse($peer->fresh()->is_active);
        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_role_permission_assignment_requires_roles_manage(): void
    {
        $admin = $this->userWithRole('admin');
        $staffRole = $this->role('staff');
        $permission = $this->permission('products.update');

        $this->actingAs($admin)
            ->put(route('admin.roles.permissions.update', $staffRole), ['permissions' => [$permission->id]])
            ->assertRedirect();
        $this->assertTrue($staffRole->permissions()->whereKey($permission->id)->exists());

        $unauthorizedStaff = $this->userWithRole('staff');
        $this->actingAs($unauthorizedStaff)
            ->put(route('admin.roles.permissions.update', $staffRole), ['permissions' => []])
            ->assertForbidden();
        $this->assertTrue($staffRole->permissions()->whereKey($permission->id)->exists());
    }

    public function test_staff_navigation_only_shows_permitted_modules(): void
    {
        $staff = $this->userWithRole('staff', ['products.view']);

        $response = $this->actingAs($staff)->get(route('admin.products.index'));

        $response->assertOk();
        $response->assertSee('href="'.route('admin.products.index').'"', false);
        $response->assertDontSee('href="'.route('admin.orders.index').'"', false);
        $response->assertDontSee('href="'.route('admin.coupons.index').'"', false);
        $response->assertDontSee('href="'.route('admin.roles.index').'"', false);
    }

    private function userWithRole(string $roleName, array $permissions = [], array $attributes = []): User
    {
        $user = User::factory()->create(array_merge(['is_active' => true], $attributes));
        $role = $this->role($roleName);
        $user->roles()->attach($role);

        foreach ($permissions as $permission) {
            $this->grantPermission($role, $permission);
        }

        return $user;
    }

    private function role(string $name): Role
    {
        return Role::query()->firstOrCreate(['name' => $name], ['guard_name' => 'web']);
    }

    private function permission(string $name): Permission
    {
        return Permission::query()->firstOrCreate(['name' => $name], ['guard_name' => 'web']);
    }

    private function grantPermission(Role $role, string $permission): void
    {
        $role->permissions()->syncWithoutDetaching([$this->permission($permission)->id]);
    }

    private function createOrder(User $user): Order
    {
        return Order::create([
            'user_id' => $user->id,
            'order_number' => 'AUTH-'.uniqid(),
            'status' => 'delivered',
            'payment_method' => 'cod',
            'payment_status' => 'paid',
            'customer_name' => $user->name,
            'customer_phone' => '0900000000',
            'shipping_address' => 'Test address',
            'subtotal' => '100000.00',
            'shipping_fee' => '0.00',
            'discount_amount' => '0.00',
            'total_amount' => '100000.00',
        ]);
    }

    private function createRefund(): Refund
    {
        $customer = User::factory()->create(['is_active' => true]);
        $order = $this->createOrder($customer);

        return Refund::create([
            'order_id' => $order->id,
            'amount' => '1000.00',
            'status' => Refund::STATUS_REQUESTED,
        ]);
    }
}
