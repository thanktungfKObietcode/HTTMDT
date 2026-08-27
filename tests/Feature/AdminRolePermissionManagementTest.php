<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminRolePermissionManagementTest extends TestCase
{
    use RefreshDatabase;

    // --- 1. Authorization ---

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('admin.roles.index'))->assertRedirect(route('login'));
    }

    public function test_normal_user_gets_403(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $this->actingAs($user)->get(route('admin.roles.index'))->assertForbidden();
    }

    public function test_inactive_admin_gets_403(): void
    {
        $admin = $this->createAdmin(false);
        $this->actingAs($admin)->get(route('admin.roles.index'))->assertForbidden();
    }

    public function test_active_admin_can_access_roles_list(): void
    {
        $admin = $this->createAdmin();
        $this->actingAs($admin)->get(route('admin.roles.index'))->assertOk();
    }

    // --- 2. Role List ---

    public function test_role_list_shows_existing_roles(): void
    {
        $admin = $this->createAdmin();
        // Admin role was already created in createAdmin()
        $this->actingAs($admin)
            ->get(route('admin.roles.index'))
            ->assertOk()
            ->assertSeeText('admin');
    }

    public function test_role_list_search_filters_by_name(): void
    {
        $admin = $this->createAdmin();
        // Use names that don't overlap with any static text in the view
        Role::firstOrCreate(['name' => 'staff'], ['guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'moderator'], ['guard_name' => 'web']);

        $this->actingAs($admin)
            ->get(route('admin.roles.index', ['q' => 'staff']))
            ->assertOk()
            ->assertSeeText('staff')
            ->assertDontSeeText('moderator');
    }

    // --- 3. Role Detail ---

    public function test_active_admin_can_view_role_detail(): void
    {
        $admin = $this->createAdmin();
        $role = Role::firstOrCreate(['name' => 'admin'], ['guard_name' => 'web']);

        $this->actingAs($admin)
            ->get(route('admin.roles.show', $role))
            ->assertOk()
            ->assertSeeText('admin');
    }

    public function test_role_detail_shows_users_with_that_role(): void
    {
        $admin = $this->createAdmin();
        $role = Role::firstOrCreate(['name' => 'admin'], ['guard_name' => 'web']);

        $this->actingAs($admin)
            ->get(route('admin.roles.show', $role))
            ->assertOk()
            ->assertSeeText($admin->name);
    }

    public function test_role_detail_shows_permissions(): void
    {
        $admin = $this->createAdmin();
        $role = Role::firstOrCreate(['name' => 'admin'], ['guard_name' => 'web']);
        $perm = Permission::create(['name' => 'manage-orders', 'guard_name' => 'web']);
        $role->permissions()->attach($perm->id);

        $this->actingAs($admin)
            ->get(route('admin.roles.show', $role))
            ->assertOk()
            ->assertSeeText('manage-orders');
    }

    public function test_invalid_role_id_returns_404(): void
    {
        $admin = $this->createAdmin();
        $this->actingAs($admin)
            ->get(route('admin.roles.show', 99999))
            ->assertNotFound();
    }

    // --- 4. Authorization on all Role routes ---

    public function test_all_role_routes_blocked_for_guests(): void
    {
        $role = Role::firstOrCreate(['name' => 'test-role'], ['guard_name' => 'web']);

        $this->get(route('admin.roles.index'))->assertRedirect(route('login'));
        $this->get(route('admin.roles.show', $role))->assertRedirect(route('login'));
    }

    public function test_all_role_routes_blocked_for_normal_users(): void
    {
        $normal = User::factory()->create(['is_active' => true]);
        $role = Role::firstOrCreate(['name' => 'test-role2'], ['guard_name' => 'web']);

        $this->actingAs($normal)->get(route('admin.roles.index'))->assertForbidden();
        $this->actingAs($normal)->get(route('admin.roles.show', $role))->assertForbidden();
    }

    // --- 5. Data integrity: role_user pivot ---

    public function test_role_user_pivot_is_read_correctly(): void
    {
        $admin = $this->createAdmin();
        $role = Role::firstOrCreate(['name' => 'admin'], ['guard_name' => 'web']);

        // Admin should be in the users list of the admin role
        $this->assertTrue($role->users()->where('users.id', $admin->id)->exists());
    }

    // --- 6. Permission–Role pivot ---

    public function test_permission_role_pivot_is_readable(): void
    {
        $admin = $this->createAdmin();
        $role = Role::firstOrCreate(['name' => 'admin'], ['guard_name' => 'web']);
        $perm = Permission::create(['name' => 'view-reports', 'guard_name' => 'web']);
        $role->permissions()->attach($perm->id);

        // Reload from DB
        $this->assertTrue($role->permissions()->where('permissions.id', $perm->id)->exists());
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
}
