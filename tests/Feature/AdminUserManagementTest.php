<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Models\UserAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminUserManagementTest extends TestCase
{
    use RefreshDatabase;

    // --- 1. Authorization ---

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('admin.users.index'))->assertRedirect(route('login'));
    }

    public function test_normal_authenticated_user_gets_403(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $this->actingAs($user)->get(route('admin.users.index'))->assertForbidden();
    }

    public function test_inactive_admin_gets_403(): void
    {
        $admin = $this->createAdmin(false);
        $this->actingAs($admin)->get(route('admin.users.index'))->assertForbidden();
    }

    public function test_active_admin_can_access_user_list(): void
    {
        $admin = $this->createAdmin();
        $this->actingAs($admin)->get(route('admin.users.index'))->assertOk();
    }

    // --- 2. User list ---

    public function test_user_list_shows_users(): void
    {
        $admin = $this->createAdmin();
        $user = User::factory()->create(['name' => 'Listed User', 'email' => 'listed@example.com']);

        $this->actingAs($admin)
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertSeeText('Listed User');
    }

    // --- 3. Search ---

    public function test_search_by_name_returns_matching_user(): void
    {
        $admin = $this->createAdmin();
        User::factory()->create(['name' => 'Unique SearchName', 'email' => 'match@example.com']);
        User::factory()->create(['name' => 'Other Person', 'email' => 'other@example.com']);

        $this->actingAs($admin)
            ->get(route('admin.users.index', ['q' => 'SearchName']))
            ->assertOk()
            ->assertSeeText('Unique SearchName')
            ->assertDontSeeText('Other Person');
    }

    public function test_search_by_email_returns_matching_user(): void
    {
        $admin = $this->createAdmin();
        User::factory()->create(['name' => 'Email Match User', 'email' => 'findme@example.com']);
        User::factory()->create(['name' => 'DifferentPerson', 'email' => 'notme@example.com']);

        $this->actingAs($admin)
            ->get(route('admin.users.index', ['q' => 'findme']))
            ->assertOk()
            ->assertSeeText('Email Match User')
            ->assertDontSeeText('DifferentPerson');
    }

    // --- 4. Role filter ---

    public function test_role_filter_shows_only_users_with_that_role(): void
    {
        $admin = $this->createAdmin();
        $customerRole = Role::firstOrCreate(['name' => 'customer'], ['guard_name' => 'web']);
        $customer = User::factory()->create(['name' => 'CustomerUser', 'email' => 'cu@example.com', 'is_active' => true]);
        $customer->roles()->attach($customerRole->id);

        $this->actingAs($admin)
            ->get(route('admin.users.index', ['role' => 'admin']))
            ->assertOk()
            ->assertDontSeeText('CustomerUser');

        $this->actingAs($admin)
            ->get(route('admin.users.index', ['role' => 'customer']))
            ->assertOk()
            ->assertSeeText('CustomerUser');
    }

    // --- 5. Active / inactive filter ---

    public function test_active_filter_shows_only_active_users(): void
    {
        $admin = $this->createAdmin();
        User::factory()->create(['name' => 'ActiveUser', 'email' => 'active@example.com', 'is_active' => true]);
        User::factory()->create(['name' => 'InactiveUser', 'email' => 'inactive@example.com', 'is_active' => false]);

        $this->actingAs($admin)
            ->get(route('admin.users.index', ['status' => 'active']))
            ->assertOk()
            ->assertSeeText('ActiveUser')
            ->assertDontSeeText('InactiveUser');
    }

    public function test_inactive_filter_shows_only_inactive_users(): void
    {
        $admin = $this->createAdmin();
        User::factory()->create(['name' => 'ActivePerson', 'email' => 'activep@example.com', 'is_active' => true]);
        User::factory()->create(['name' => 'InactivePerson', 'email' => 'inactivep@example.com', 'is_active' => false]);

        $this->actingAs($admin)
            ->get(route('admin.users.index', ['status' => 'inactive']))
            ->assertOk()
            ->assertSeeText('InactivePerson')
            ->assertDontSeeText('ActivePerson');
    }

    // --- 6. User detail ---

    public function test_admin_can_view_user_detail(): void
    {
        $admin = $this->createAdmin();
        $user = User::factory()->create([
            'name'      => 'DetailTestUser',
            'email'     => 'detailtest@example.com',
            'phone'     => '0901234567',
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.users.show', $user))
            ->assertOk()
            ->assertSeeText('DetailTestUser')
            ->assertSeeText('detailtest@example.com');
    }

    public function test_user_detail_does_not_show_password(): void
    {
        $admin = $this->createAdmin();
        $user = User::factory()->create(['password' => Hash::make('secret-password')]);

        $this->actingAs($admin)
            ->get(route('admin.users.show', $user))
            ->assertOk()
            ->assertDontSee('secret-password');
    }

    public function test_user_detail_shows_address(): void
    {
        $admin = $this->createAdmin();
        $user = User::factory()->create(['name' => 'UserWithAddress']);
        UserAddress::create([
            'user_id'      => $user->id,
            'full_name'    => 'TestRecipient',
            'phone'        => '0987654321',
            'province'     => 'Ha Noi',
            'district'     => 'Hoan Kiem',
            'ward'         => 'Phuc Tan',
            'address_line' => '123 Pho Hue',
            'is_default'   => true,
            'is_active'    => true,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.users.show', $user))
            ->assertOk()
            ->assertSeeText('TestRecipient');
    }

    public function test_nonexistent_user_returns_404(): void
    {
        $admin = $this->createAdmin();
        $this->actingAs($admin)->get('/admin/users/999999')->assertNotFound();
    }

    // --- 7. Deactivate user ---

    public function test_admin_can_deactivate_user(): void
    {
        $admin = $this->createAdmin();
        $user = User::factory()->create(['is_active' => true]);

        $this->actingAs($admin)
            ->from(route('admin.users.show', $user))
            ->post(route('admin.users.deactivate', $user))
            ->assertRedirect();

        $this->assertFalse((bool) $user->fresh()->is_active);
    }

    public function test_admin_cannot_deactivate_themselves(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin)
            ->from(route('admin.users.show', $admin))
            ->post(route('admin.users.deactivate', $admin))
            ->assertRedirect()
            ->assertSessionHasErrors('status');

        $this->assertTrue((bool) $admin->fresh()->is_active);
    }

    // --- 8. Activate user ---

    public function test_admin_can_activate_user(): void
    {
        $admin = $this->createAdmin();
        $user = User::factory()->create(['is_active' => false]);

        $this->actingAs($admin)
            ->from(route('admin.users.show', $user))
            ->post(route('admin.users.activate', $user))
            ->assertRedirect();

        $this->assertTrue((bool) $user->fresh()->is_active);
    }

    // --- 9. Login security ---

    public function test_inactive_user_cannot_login(): void
    {
        User::factory()->create([
            'email'     => 'inactive@login.com',
            'password'  => Hash::make('password123'),
            'is_active' => false,
        ]);

        $this->post(route('login.store'), [
            'email'    => 'inactive@login.com',
            'password' => 'password123',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_active_user_can_login(): void
    {
        User::factory()->create([
            'email'     => 'active@login.com',
            'password'  => Hash::make('password123'),
            'is_active' => true,
        ]);

        $this->post(route('login.store'), [
            'email'    => 'active@login.com',
            'password' => 'password123',
        ])->assertRedirect();

        $this->assertAuthenticated();
    }

    // --- 10. Admin authorization still works ---

    public function test_admin_middleware_blocks_unauthenticated_on_all_user_routes(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $this->get(route('admin.users.index'))->assertRedirect(route('login'));
        $this->get(route('admin.users.show', $user))->assertRedirect(route('login'));
        $this->post(route('admin.users.deactivate', $user))->assertRedirect(route('login'));
        $this->post(route('admin.users.activate', $user))->assertRedirect(route('login'));
    }

    public function test_admin_middleware_blocks_normal_user_on_all_user_routes(): void
    {
        $normal = User::factory()->create(['is_active' => true]);
        $target = User::factory()->create(['is_active' => true]);

        $this->actingAs($normal)->get(route('admin.users.index'))->assertForbidden();
        $this->actingAs($normal)->get(route('admin.users.show', $target))->assertForbidden();
        $this->actingAs($normal)->post(route('admin.users.deactivate', $target))->assertForbidden();
        $this->actingAs($normal)->post(route('admin.users.activate', $target))->assertForbidden();
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