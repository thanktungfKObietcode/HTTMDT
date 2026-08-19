<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AdminOrderManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_and_normal_user_are_blocked(): void
    {
        $this->get(route('admin.orders.index'))->assertRedirect(route('login'));

        $user = User::factory()->create();
        $this->actingAs($user)->get(route('admin.orders.index'))->assertForbidden();
    }

    public function test_admin_can_list_search_and_filter_orders(): void
    {
        $admin = $this->createAdmin();
        $matching = $this->createOrder($admin, 'SA-ADMIN-SEARCH', 'pending', 'paid', 'Search Customer', 'search@example.com');
        $other = $this->createOrder($admin, 'SA-ADMIN-OTHER', 'shipped', 'failed', 'Other Customer', 'other@example.com');

        $this->actingAs($admin)->get(route('admin.orders.index', ['q' => 'SA-ADMIN-SEARCH']))
            ->assertOk()
            ->assertSeeText($matching->order_number)
            ->assertDontSeeText($other->order_number);

        $this->actingAs($admin)->get(route('admin.orders.index', ['status' => 'shipped']))
            ->assertSeeText($other->order_number)
            ->assertDontSeeText($matching->order_number);

        $this->actingAs($admin)->get(route('admin.orders.index', ['payment_status' => 'paid']))
            ->assertSeeText($matching->order_number)
            ->assertDontSeeText($other->order_number);
    }

    public function test_admin_can_view_order_detail_and_items(): void
    {
        $admin = $this->createAdmin();
        $order = $this->createOrder($admin, 'SA-ADMIN-DETAIL', 'pending', 'pending', 'Detail Customer', 'detail@example.com');
        $product = Product::create([
            'name' => 'Detail product',
            'slug' => 'detail-product',
            'sku' => 'DETAIL-SKU',
            'price' => 100000,
            'stock' => 2,
            'is_active' => true,
        ]);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'DETAIL-VARIANT',
            'size' => 'M',
            'price' => 120000,
            'stock' => 2,
            'is_active' => true,
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'product_name' => $product->name,
            'sku' => $variant->sku,
            'quantity' => 2,
            'unit_price' => 120000,
            'total_price' => 240000,
        ]);

        $this->actingAs($admin)->get(route('admin.orders.show', $order))
            ->assertOk()
            ->assertSeeText([$order->order_number, 'Detail Customer', 'detail@example.com', 'Detail product', 'DETAIL-VARIANT', '240.000đ']);
    }

    public function test_admin_can_apply_valid_lifecycle_transition(): void
    {
        $admin = $this->createAdmin();
        $order = $this->createOrder($admin, 'SA-ADMIN-TRANSITION', 'pending');

        $this->actingAs($admin)->post(route('admin.orders.status', $order), [
            'status' => 'confirmed',
            'note' => 'Admin confirmed',
        ])->assertRedirect();

        $order->refresh();
        $this->assertSame('confirmed', $order->status);
        $this->assertDatabaseHas('order_status_history', ['order_id' => $order->id, 'status' => 'confirmed', 'changed_by' => $admin->id]);
    }

    public function test_invalid_lifecycle_transition_is_rejected_without_change(): void
    {
        $admin = $this->createAdmin();
        $order = $this->createOrder($admin, 'SA-ADMIN-INVALID', 'pending');

        $this->actingAs($admin)->from(route('admin.orders.show', $order))
            ->post(route('admin.orders.status', $order), ['status' => 'delivered'])
            ->assertRedirect()
            ->assertSessionHasErrors('status');

        $this->assertSame('pending', $order->refresh()->status);
        $this->assertDatabaseMissing('order_status_history', ['order_id' => $order->id, 'status' => 'delivered']);
    }

    public function test_admin_refund_uses_existing_payment_service(): void
    {
        $admin = $this->createAdmin();
        $order = $this->createOrder($admin, 'SA-ADMIN-REFUND', 'delivered', 'paid');
        $transaction = PaymentTransaction::create([
            'order_id' => $order->id,
            'gateway' => 'cod',
            'transaction_id' => 'TXN-ADMIN-REFUND',
            'payment_status' => 'paid',
            'amount' => $order->total_amount,
        ]);

        $this->actingAs($admin)->post(route('admin.orders.refund', $order), [
            'amount' => $order->total_amount,
            'reason' => 'Admin refund',
        ])->assertRedirect();

        $this->assertDatabaseHas('refunds', [
            'order_id' => $order->id,
            'payment_transaction_id' => $transaction->id,
            'status' => 'completed',
        ]);
        $this->assertSame('refunded', $order->refresh()->payment_status);
    }

    public function test_invalid_order_id_returns_404_and_orders_cannot_be_deleted(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin)->get('/admin/orders/999999')->assertNotFound();
        $this->assertFalse(Route::has('admin.orders.destroy'));
    }

    private function createAdmin(): User
    {
        $admin = User::factory()->create(['password' => Hash::make('admin-password'), 'is_active' => true]);
        $role = Role::create(['name' => 'admin', 'guard_name' => 'web']);
        $admin->roles()->attach($role->id);

        return $admin;
    }

    private function createOrder(User $user, string $number, string $status = 'pending', string $paymentStatus = 'pending', string $name = 'Customer', string $email = 'customer@example.com'): Order
    {
        return Order::create([
            'user_id' => $user->id,
            'order_number' => $number,
            'status' => $status,
            'payment_status' => $paymentStatus,
            'customer_name' => $name,
            'customer_phone' => '0900000000',
            'customer_email' => $email,
            'shipping_address' => '123 Admin Street',
            'subtotal' => 200000,
            'shipping_fee' => 30000,
            'discount_amount' => 0,
            'total_amount' => 230000,
        ]);
    }
}