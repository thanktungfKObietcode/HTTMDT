<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AddressManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_authenticated_user_can_view_addresses(): void
    {
        $response = $this->actingAs($this->user)->get(route('address.index'));

        $response->assertOk();
        $response->assertSeeText('Địa chỉ giao hàng');
    }

    public function test_guest_cannot_access_address_page(): void
    {
        $response = $this->get(route('address.index'));

        $response->assertRedirect(route('login'));
    }

    public function test_user_can_add_address(): void
    {
        $response = $this->actingAs($this->user)->post(route('address.store'), [
            'full_name' => 'Nguyễn Văn A',
            'phone' => '0912345678',
            'province' => 'Hà Nội',
            'district' => 'Hoàn Kiếm',
            'ward' => 'Tràng Tiền',
            'address_line' => '123 Đường chính',
            'is_default' => false,
        ]);

        $response->assertRedirect(route('address.index'));
        $this->assertDatabaseHas('user_addresses', [
            'user_id' => $this->user->id,
            'full_name' => 'Nguyễn Văn A',
            'phone' => '0912345678',
        ]);
    }

    public function test_user_can_edit_address(): void
    {
        $address = UserAddress::factory()->create([
            'user_id' => $this->user->id,
            'full_name' => 'Old Name',
        ]);

        $response = $this->actingAs($this->user)->put(route('address.update', $address->id), [
            'full_name' => 'New Name',
            'phone' => '0987654321',
            'province' => 'TP. Hồ Chí Minh',
            'district' => 'Quận 1',
            'ward' => 'Bến Nghé',
            'address_line' => '456 New Street',
            'is_default' => false,
        ]);

        $response->assertRedirect(route('address.index'));
        $this->assertDatabaseHas('user_addresses', [
            'id' => $address->id,
            'full_name' => 'New Name',
            'phone' => '0987654321',
        ]);
    }

    public function test_user_can_delete_address(): void
    {
        $address = UserAddress::factory()->create([
            'user_id' => $this->user->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->user)->delete(route('address.delete', $address->id));

        $response->assertRedirect(route('address.index'));
        $address->refresh();
        $this->assertFalse($address->is_active);
    }

    public function test_user_can_set_default_address(): void
    {
        $address1 = UserAddress::factory()->create([
            'user_id' => $this->user->id,
            'is_default' => false,
        ]);

        $address2 = UserAddress::factory()->create([
            'user_id' => $this->user->id,
            'is_default' => true,
        ]);

        $this->actingAs($this->user)->put(route('address.update', $address1->id), [
            'full_name' => $address1->full_name,
            'phone' => $address1->phone,
            'province' => $address1->province,
            'district' => $address1->district,
            'ward' => $address1->ward,
            'address_line' => $address1->address_line,
            'is_default' => true,
        ]);

        $address1->refresh();
        $address2->refresh();

        $this->assertTrue($address1->is_default);
        $this->assertFalse($address2->is_default);
    }

    public function test_user_cannot_edit_other_user_address(): void
    {
        $otherUser = User::factory()->create();
        $address = UserAddress::factory()->create(['user_id' => $otherUser->id]);

        $response = $this->actingAs($this->user)->put(route('address.update', $address->id), [
            'full_name' => 'New Name',
            'phone' => '0987654321',
            'province' => 'Hà Nội',
            'district' => 'Hoàn Kiếm',
            'ward' => 'Tràng Tiền',
            'address_line' => '456 Street',
            'is_default' => false,
        ]);

        $response->assertForbidden();
    }

    public function test_user_cannot_delete_other_user_address(): void
    {
        $otherUser = User::factory()->create();
        $address = UserAddress::factory()->create(['user_id' => $otherUser->id]);

        $response = $this->actingAs($this->user)->delete(route('address.delete', $address->id));

        $response->assertForbidden();
    }

    public function test_only_active_addresses_are_shown(): void
    {
        UserAddress::factory()->create([
            'user_id' => $this->user->id,
            'is_active' => true,
            'full_name' => 'Active Address',
        ]);

        UserAddress::factory()->create([
            'user_id' => $this->user->id,
            'is_active' => false,
            'full_name' => 'Inactive Address',
        ]);

        $response = $this->actingAs($this->user)->get(route('address.index'));

        $response->assertSeeText('Active Address');
        $response->assertDontSeeText('Inactive Address');
    }

    public function test_address_requires_valid_data(): void
    {
        $response = $this->actingAs($this->user)->post(route('address.store'), [
            'full_name' => '',
            'phone' => '',
            'province' => '',
            'district' => '',
            'ward' => '',
            'address_line' => '',
        ]);

        $response->assertSessionHasErrors(['full_name', 'phone', 'province', 'district', 'ward', 'address_line']);
    }
}
