<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminCouponManagementTest extends TestCase
{
    use RefreshDatabase;

    // --- 1. Authorization ---

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('admin.coupons.index'))->assertRedirect(route('login'));
    }

    public function test_normal_user_gets_403(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $this->actingAs($user)->get(route('admin.coupons.index'))->assertForbidden();
    }

    public function test_inactive_admin_is_logged_out_and_redirected(): void
    {
        $admin = $this->createAdmin(false);
        $this->actingAs($admin)->get(route('admin.coupons.index'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_active_admin_can_access_coupon_list(): void
    {
        $admin = $this->createAdmin();
        $this->actingAs($admin)->get(route('admin.coupons.index'))->assertOk();
    }

    // --- 2. Coupon list ---

    public function test_coupon_list_shows_coupons(): void
    {
        $admin = $this->createAdmin();
        Coupon::create($this->couponData(['code' => 'LISTTEST']));

        $this->actingAs($admin)
            ->get(route('admin.coupons.index'))
            ->assertOk()
            ->assertSeeText('LISTTEST');
    }

    // --- 3. Search ---

    public function test_search_by_code_returns_matching_coupon(): void
    {
        $admin = $this->createAdmin();
        Coupon::create($this->couponData(['code' => 'FINDME10']));
        Coupon::create($this->couponData(['code' => 'OTHER50']));

        $this->actingAs($admin)
            ->get(route('admin.coupons.index', ['q' => 'FINDME']))
            ->assertOk()
            ->assertSeeText('FINDME10')
            ->assertDontSeeText('OTHER50');
    }

    // --- 4. Active / inactive filter ---

    public function test_active_filter_shows_only_active_coupons(): void
    {
        $admin = $this->createAdmin();
        Coupon::create($this->couponData(['code' => 'STAY_ON_C1', 'is_active' => true]));
        Coupon::create($this->couponData(['code' => 'STAY_OFF_C1', 'is_active' => false]));

        $this->actingAs($admin)
            ->get(route('admin.coupons.index', ['status' => 'active']))
            ->assertOk()
            ->assertSeeText('STAY_ON_C1')
            ->assertDontSeeText('STAY_OFF_C1');
    }

    public function test_inactive_filter_shows_only_inactive_coupons(): void
    {
        $admin = $this->createAdmin();
        Coupon::create($this->couponData(['code' => 'STAY_ON_C2', 'is_active' => true]));
        Coupon::create($this->couponData(['code' => 'STAY_OFF_C2', 'is_active' => false]));

        $this->actingAs($admin)
            ->get(route('admin.coupons.index', ['status' => 'inactive']))
            ->assertOk()
            ->assertSeeText('STAY_OFF_C2')
            ->assertDontSeeText('STAY_ON_C2');
    }

    // --- 5. Create coupon ---

    public function test_admin_can_view_create_form(): void
    {
        $admin = $this->createAdmin();
        $this->actingAs($admin)->get(route('admin.coupons.create'))->assertOk();
    }

    public function test_admin_can_create_percent_coupon(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin)
            ->post(route('admin.coupons.store'), [
                'code'                 => 'NEWPCT20',
                'type'                 => 'percent',
                'value'                => '20',
                'minimum_order_amount' => '100000',
                'starts_at'            => '',
                'ends_at'              => '',
                'usage_limit'          => '',
                'is_active'            => '1',
            ])
            ->assertRedirect(route('admin.coupons.index'));

        $this->assertDatabaseHas('coupons', [
            'code'  => 'NEWPCT20',
            'type'  => 'percent',
            'value' => '20.00',
        ]);
    }

    public function test_admin_can_create_fixed_coupon(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin)
            ->post(route('admin.coupons.store'), [
                'code'                 => 'FIXED50K',
                'type'                 => 'fixed',
                'value'                => '50000',
                'minimum_order_amount' => '',
                'starts_at'            => '',
                'ends_at'              => '',
                'usage_limit'          => '',
                'is_active'            => '1',
            ])
            ->assertRedirect(route('admin.coupons.index'));

        $this->assertDatabaseHas('coupons', ['code' => 'FIXED50K', 'type' => 'fixed']);
    }

    // --- 6. Validation ---

    public function test_code_is_required(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin)
            ->post(route('admin.coupons.store'), [
                'code'  => '',
                'type'  => 'percent',
                'value' => '10',
            ])
            ->assertSessionHasErrors('code');
    }

    public function test_type_must_be_valid(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin)
            ->post(route('admin.coupons.store'), [
                'code'  => 'TESTCODE',
                'type'  => 'invalid_type',
                'value' => '10',
            ])
            ->assertSessionHasErrors('type');
    }

    public function test_value_is_required_and_numeric(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin)
            ->post(route('admin.coupons.store'), [
                'code'  => 'TESTVAL',
                'type'  => 'percent',
                'value' => '',
            ])
            ->assertSessionHasErrors('value');
    }

    public function test_duplicate_code_is_rejected(): void
    {
        $admin = $this->createAdmin();
        Coupon::create($this->couponData(['code' => 'DUPCODE']));

        $this->actingAs($admin)
            ->post(route('admin.coupons.store'), [
                'code'  => 'DUPCODE',
                'type'  => 'percent',
                'value' => '10',
            ])
            ->assertSessionHasErrors('code');
    }

    public function test_ends_at_must_be_after_starts_at(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin)
            ->post(route('admin.coupons.store'), [
                'code'      => 'DATETEST',
                'type'      => 'percent',
                'value'     => '10',
                'starts_at' => '2030-01-10 00:00:00',
                'ends_at'   => '2030-01-01 00:00:00',
            ])
            ->assertSessionHasErrors('ends_at');
    }

    // --- 7. Edit coupon ---

    public function test_admin_can_view_edit_form(): void
    {
        $admin = $this->createAdmin();
        $coupon = Coupon::create($this->couponData(['code' => 'EDITME']));

        $this->actingAs($admin)
            ->get(route('admin.coupons.edit', $coupon))
            ->assertOk()
            ->assertSeeText('EDITME');
    }

    public function test_admin_can_update_coupon(): void
    {
        $admin = $this->createAdmin();
        $coupon = Coupon::create($this->couponData(['code' => 'UPDATEME', 'value' => 10]));

        $this->actingAs($admin)
            ->put(route('admin.coupons.update', $coupon), [
                'code'                 => 'UPDATEME',
                'type'                 => 'percent',
                'value'                => '25',
                'minimum_order_amount' => '',
                'starts_at'            => '',
                'ends_at'              => '',
                'usage_limit'          => '',
                'is_active'            => '1',
            ])
            ->assertRedirect(route('admin.coupons.index'));

        $this->assertDatabaseHas('coupons', ['code' => 'UPDATEME', 'value' => '25.00']);
    }

    public function test_code_unique_rule_ignores_self_on_update(): void
    {
        $admin = $this->createAdmin();
        $coupon = Coupon::create($this->couponData(['code' => 'SELFCODE']));

        $this->actingAs($admin)
            ->put(route('admin.coupons.update', $coupon), [
                'code'      => 'SELFCODE',
                'type'      => 'percent',
                'value'     => '15',
                'is_active' => '1',
            ])
            ->assertRedirect(route('admin.coupons.index'));
    }

    public function test_admin_can_deactivate_coupon_via_edit_form_by_omitting_is_active(): void
    {
        $admin = $this->createAdmin();
        $coupon = Coupon::create($this->couponData(['code' => 'TOGGLEOFF', 'is_active' => true]));

        $this->actingAs($admin)
            ->put(route('admin.coupons.update', $coupon), [
                'code'                 => 'TOGGLEOFF',
                'type'                 => 'percent',
                'value'                => '10',
                // is_active omitted (checkbox unchecked)
            ])
            ->assertRedirect(route('admin.coupons.index'));

        $this->assertFalse((bool) $coupon->fresh()->is_active);
    }

    // --- 8. Activate / Deactivate ---

    public function test_admin_can_deactivate_coupon(): void
    {
        $admin = $this->createAdmin();
        $coupon = Coupon::create($this->couponData(['code' => 'DEACTIVATE', 'is_active' => true]));

        $this->actingAs($admin)
            ->from(route('admin.coupons.index'))
            ->post(route('admin.coupons.deactivate', $coupon))
            ->assertRedirect();

        $this->assertFalse((bool) $coupon->fresh()->is_active);
    }

    public function test_admin_can_activate_coupon(): void
    {
        $admin = $this->createAdmin();
        $coupon = Coupon::create($this->couponData(['code' => 'REACTIVATE', 'is_active' => false]));

        $this->actingAs($admin)
            ->from(route('admin.coupons.index'))
            ->post(route('admin.coupons.activate', $coupon))
            ->assertRedirect();

        $this->assertTrue((bool) $coupon->fresh()->is_active);
    }

    // --- 9. Delete safety ---

    public function test_admin_can_delete_unused_coupon(): void
    {
        $admin = $this->createAdmin();
        $coupon = Coupon::create($this->couponData(['code' => 'DELETEOK']));

        $this->actingAs($admin)
            ->delete(route('admin.coupons.destroy', $coupon))
            ->assertRedirect(route('admin.coupons.index'));

        $this->assertDatabaseMissing('coupons', ['id' => $coupon->id]);
    }

    public function test_admin_cannot_delete_coupon_with_usage_history(): void
    {
        $admin = $this->createAdmin();
        $user = User::factory()->create(['is_active' => true]);
        $coupon = Coupon::create($this->couponData(['code' => 'INUSE']));

        CouponUsage::create([
            'coupon_id'       => $coupon->id,
            'user_id'         => $user->id,
            'order_number'    => 'SA-20260101-0001',
            'discount_amount' => 10000,
        ]);

        $this->actingAs($admin)
            ->from(route('admin.coupons.index'))
            ->delete(route('admin.coupons.destroy', $coupon))
            ->assertRedirect()
            ->assertSessionHasErrors('delete');

        $this->assertDatabaseHas('coupons', ['id' => $coupon->id]);
    }

    // --- 10. Full authorization check on all routes ---

    public function test_admin_middleware_blocks_all_coupon_routes_for_guests(): void
    {
        $coupon = Coupon::create($this->couponData(['code' => 'AUTHCK']));

        $this->get(route('admin.coupons.index'))->assertRedirect(route('login'));
        $this->get(route('admin.coupons.create'))->assertRedirect(route('login'));
        $this->post(route('admin.coupons.store'), [])->assertRedirect(route('login'));
        $this->get(route('admin.coupons.edit', $coupon))->assertRedirect(route('login'));
        $this->put(route('admin.coupons.update', $coupon), [])->assertRedirect(route('login'));
        $this->delete(route('admin.coupons.destroy', $coupon))->assertRedirect(route('login'));
        $this->post(route('admin.coupons.activate', $coupon))->assertRedirect(route('login'));
        $this->post(route('admin.coupons.deactivate', $coupon))->assertRedirect(route('login'));
    }

    public function test_admin_middleware_blocks_all_coupon_routes_for_normal_users(): void
    {
        $normal = User::factory()->create(['is_active' => true]);
        $coupon = Coupon::create($this->couponData(['code' => 'AUTHCK2']));

        $this->actingAs($normal)->get(route('admin.coupons.index'))->assertForbidden();
        $this->actingAs($normal)->get(route('admin.coupons.create'))->assertForbidden();
        $this->actingAs($normal)->post(route('admin.coupons.store'), [])->assertForbidden();
        $this->actingAs($normal)->get(route('admin.coupons.edit', $coupon))->assertForbidden();
        $this->actingAs($normal)->put(route('admin.coupons.update', $coupon), [])->assertForbidden();
        $this->actingAs($normal)->delete(route('admin.coupons.destroy', $coupon))->assertForbidden();
        $this->actingAs($normal)->post(route('admin.coupons.activate', $coupon))->assertForbidden();
        $this->actingAs($normal)->post(route('admin.coupons.deactivate', $coupon))->assertForbidden();
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

    private function couponData(array $override = []): array
    {
        return array_merge([
            'code'                 => 'TESTCOUPON',
            'type'                 => 'percent',
            'value'                => 10,
            'minimum_order_amount' => null,
            'starts_at'            => null,
            'ends_at'              => null,
            'usage_limit'          => null,
            'is_active'            => true,
        ], $override);
    }
}
