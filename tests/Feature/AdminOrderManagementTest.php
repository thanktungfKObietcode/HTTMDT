<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Refund;
use App\Models\Role;
use App\Models\User;
use App\Services\OrderLifecycleService;
use App\Services\PaymentService;
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

    public function test_admin_can_approve_requested_refund(): void
    {
        [$admin, $customer, $order, $transaction, $refund] = $this->createRefundContext();

        $this->actingAs($admin)->post(route('admin.refunds.approve', $refund), [
            'admin_note' => 'Approved by admin',
        ])->assertRedirect();

        $refund->refresh();
        $this->assertSame(Refund::STATUS_APPROVED, $refund->status);
        $this->assertSame($admin->id, $refund->reviewed_by);
        $this->assertNotNull($refund->reviewed_at);
        $this->assertSame('paid', $order->refresh()->payment_status);
        $this->assertSame('paid', $transaction->refresh()->payment_status);
    }

    public function test_admin_can_reject_requested_refund(): void
    {
        [$admin, $customer, $order, $transaction, $refund] = $this->createRefundContext();

        $this->actingAs($admin)->post(route('admin.refunds.reject', $refund), [
            'admin_note' => 'Refund is not eligible',
        ])->assertRedirect();

        $refund->refresh();
        $this->assertSame(Refund::STATUS_REJECTED, $refund->status);
        $this->assertSame($admin->id, $refund->reviewed_by);
        $this->assertNotNull($refund->reviewed_at);
        $this->assertSame('Refund is not eligible', $refund->admin_note);
        $this->assertSame('paid', $order->refresh()->payment_status);
        $this->assertSame('paid', $transaction->refresh()->payment_status);
    }

    public function test_repeated_approve_is_idempotent(): void
    {
        [$admin, $customer, $order, $transaction, $refund] = $this->createRefundContext();

        $this->actingAs($admin)->post(route('admin.refunds.approve', $refund))->assertRedirect();
        $reviewedAt = $refund->refresh()->reviewed_at?->toISOString();

        $this->actingAs($admin)->post(route('admin.refunds.approve', $refund))->assertRedirect();

        $this->assertSame(Refund::STATUS_APPROVED, $refund->refresh()->status);
        $this->assertSame($reviewedAt, $refund->reviewed_at?->toISOString());
        $this->assertDatabaseCount('refunds', 1);
    }

    public function test_repeated_reject_is_idempotent_and_releases_reserved_balance(): void
    {
        [$admin, $customer, $order, $transaction, $refund] = $this->createRefundContext();

        $this->actingAs($admin)->post(route('admin.refunds.reject', $refund), [
            'admin_note' => 'Rejected once',
        ])->assertRedirect();
        $refund->refresh();
        $reviewedAt = $refund->reviewed_at?->toISOString();

        $this->actingAs($admin)->post(route('admin.refunds.reject', $refund), [
            'admin_note' => 'Must not be appended',
        ])->assertRedirect();

        $this->assertSame(Refund::STATUS_REJECTED, $refund->refresh()->status);
        $this->assertSame($reviewedAt, $refund->reviewed_at?->toISOString());
        $this->assertSame('Rejected once', $refund->admin_note);

        $this->actingAs($customer)->post(route('payment.refund', $order), [
            'amount' => '230000.00',
            'reason' => 'Replacement after rejection',
        ])->assertRedirect(route('order.show', $order));

        $this->assertDatabaseCount('refunds', 2);
        $this->assertDatabaseHas('refunds', [
            'order_id' => $order->id,
            'amount' => '230000.00',
            'status' => Refund::STATUS_REQUESTED,
        ]);
        $this->assertSame(PaymentService::PAYMENT_PAID, $transaction->refresh()->payment_status);
    }

    public function test_rejected_refund_cannot_be_approved(): void
    {
        [$admin, $customer, $order, $transaction, $refund] = $this->createRefundContext();

        $this->actingAs($admin)->post(route('admin.refunds.reject', $refund), [
            'admin_note' => 'Rejected',
        ])->assertRedirect();

        $this->actingAs($admin)
            ->from(route('admin.orders.show', $order))
            ->post(route('admin.refunds.approve', $refund))
            ->assertRedirect(route('admin.orders.show', $order))
            ->assertSessionHasErrors('refund');

        $this->assertSame(Refund::STATUS_REJECTED, $refund->refresh()->status);
    }

    public function test_customer_and_staff_cannot_call_admin_refund_actions(): void
    {
        [$admin, $customer, $order, $transaction, $refund] = $this->createRefundContext();

        $this->actingAs($customer)->post(route('admin.refunds.approve', $refund))->assertForbidden();
        $this->actingAs($customer)->post(route('admin.refunds.reject', $refund), [
            'admin_note' => 'No access',
        ])->assertForbidden();
        $this->actingAs($customer)->post(route('admin.refunds.execute', $refund))->assertForbidden();

        $staff = User::factory()->create(['is_active' => true]);
        $staffRole = Role::create(['name' => 'staff', 'guard_name' => 'web']);
        $staff->roles()->attach($staffRole->id);

        $this->actingAs($staff)->post(route('admin.refunds.approve', $refund))->assertForbidden();
        $this->actingAs($staff)->post(route('admin.refunds.reject', $refund), [
            'admin_note' => 'No access',
        ])->assertForbidden();
        $this->actingAs($staff)->post(route('admin.refunds.execute', $refund))->assertForbidden();

        $this->assertSame(Refund::STATUS_REQUESTED, $refund->refresh()->status);
    }

    public function test_approved_full_refund_executes_and_updates_all_financial_statuses_without_restoring_stock(): void
    {
        [$admin, $customer, $order, $transaction, $refund] = $this->createRefundContext();
        $product = Product::create([
            'name' => 'Refund inventory product',
            'slug' => 'refund-inventory-product',
            'sku' => 'REFUND-INVENTORY-SKU',
            'price' => 230000,
            'stock' => 3,
            'is_active' => true,
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'sku' => $product->sku,
            'quantity' => 1,
            'unit_price' => 230000,
            'total_price' => 230000,
        ]);

        $this->actingAs($admin)->post(route('admin.refunds.approve', $refund))->assertRedirect();
        $this->actingAs($admin)->post(route('admin.refunds.execute', $refund))->assertRedirect();

        $this->assertSame(Refund::STATUS_COMPLETED, $refund->refresh()->status);
        $this->assertNotNull($refund->processing_at);
        $this->assertNotNull($refund->completed_at);
        $this->assertSame($admin->id, $refund->processed_by);
        $this->assertSame(PaymentService::PAYMENT_REFUNDED, $transaction->refresh()->payment_status);
        $this->assertSame(PaymentService::PAYMENT_REFUNDED, $order->refresh()->payment_status);
        $this->assertSame(OrderLifecycleService::STATUS_REFUNDED, $order->status);
        $this->assertSame(3, (int) $product->refresh()->stock);
        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $order->id,
            'status' => OrderLifecycleService::STATUS_REFUNDED,
            'changed_by' => $admin->id,
        ]);
    }

    public function test_failed_execution_marks_refund_failed_without_changing_financial_statuses(): void
    {
        [$admin, $customer, $order, $transaction, $refund] = $this->createRefundContext();
        $service = app(PaymentService::class);
        $service->approveRefund($refund, $admin->id);
        $processingWasVisible = false;

        try {
            $service->executeRefund(
                $refund,
                $admin->id,
                null,
                function (Refund $processingRefund) use (&$processingWasVisible): void {
                    $processingWasVisible = $processingRefund->fresh()->status === Refund::STATUS_PROCESSING;
                    throw new \RuntimeException('Simulated internal failure');
                }
            );
            $this->fail('Execution failure should be reported.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Thực thi hoàn tiền thất bại.', $exception->getMessage());
        }

        $this->assertTrue($processingWasVisible);
        $this->assertSame(Refund::STATUS_FAILED, $refund->refresh()->status);
        $this->assertNotNull($refund->processing_at);
        $this->assertNotNull($refund->failed_at);
        $this->assertSame($admin->id, $refund->processed_by);
        $this->assertSame('paid', $transaction->refresh()->payment_status);
        $this->assertSame('paid', $order->refresh()->payment_status);
        $this->assertSame(OrderLifecycleService::STATUS_DELIVERED, $order->status);

        try {
            $service->executeRefund($refund->refresh(), $admin->id);
            $this->fail('A failed refund must not execute again.');
        } catch (\RuntimeException) {
            $this->assertSame(Refund::STATUS_FAILED, $refund->refresh()->status);
        }

        $replacement = $service->requestRefund(
            $order->refresh(),
            '230000.00',
            'Replacement after execution failure',
            $customer->id
        );

        $this->assertSame(Refund::STATUS_REQUESTED, $replacement->status);
        $this->assertDatabaseCount('refunds', 2);
        $this->assertSame('paid', $transaction->refresh()->payment_status);
        $this->assertSame('paid', $order->refresh()->payment_status);
    }

    public function test_repeated_execute_does_not_duplicate_financial_effects(): void
    {
        [$admin, $customer, $order, $transaction, $refund] = $this->createRefundContext();
        $service = app(PaymentService::class);
        $service->approveRefund($refund, $admin->id);
        $service->executeRefund($refund, $admin->id);
        $service->executeRefund($refund->refresh(), $admin->id);

        try {
            $service->handleCallback([
                'order_number' => $order->order_number,
                'transaction_id' => $transaction->transaction_id,
                'payment_status' => PaymentService::PAYMENT_PAID,
                'amount' => (string) $transaction->amount,
                'gateway' => (string) $transaction->gateway,
            ]);
            $this->fail('A paid callback must not reopen a refunded transaction.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('callback', $exception->getMessage());
        }

        $this->actingAs($admin)
            ->from(route('admin.orders.show', $order))
            ->post(route('admin.refunds.approve', $refund))
            ->assertRedirect(route('admin.orders.show', $order))
            ->assertSessionHasErrors('refund');

        $this->assertSame(Refund::STATUS_COMPLETED, $refund->refresh()->status);
        $this->assertDatabaseCount('refunds', 1);
        $this->assertSame(1, $order->statusHistory()->where('status', OrderLifecycleService::STATUS_REFUNDED)->count());
        $this->assertSame(PaymentService::PAYMENT_REFUNDED, $transaction->refresh()->payment_status);
    }

    public function test_requested_refund_cannot_be_executed_directly(): void
    {
        [$admin, $customer, $order, $transaction, $refund] = $this->createRefundContext();

        $this->actingAs($admin)
            ->from(route('admin.orders.show', $order))
            ->post(route('admin.refunds.execute', $refund))
            ->assertRedirect(route('admin.orders.show', $order))
            ->assertSessionHasErrors('refund');

        $this->assertSame(Refund::STATUS_REQUESTED, $refund->refresh()->status);
    }

    public function test_partial_completed_refund_does_not_mark_whole_payment_refunded(): void
    {
        [$admin, $customer, $order, $transaction, $refund] = $this->createRefundContext('100000.00');
        $service = app(PaymentService::class);
        $service->approveRefund($refund, $admin->id);
        $service->executeRefund($refund, $admin->id);

        $this->assertSame(Refund::STATUS_COMPLETED, $refund->refresh()->status);
        $this->assertSame('paid', $transaction->refresh()->payment_status);
        $this->assertSame('paid', $order->refresh()->payment_status);
        $this->assertSame(OrderLifecycleService::STATUS_DELIVERED, $order->status);

        $this->actingAs($customer)->post(route('payment.refund', $order), [
            'amount' => '130000.00',
            'reason' => 'Remaining balance',
        ])->assertRedirect(route('order.show', $order));

        $this->assertDatabaseCount('refunds', 2);
        $this->assertDatabaseHas('refunds', [
            'order_id' => $order->id,
            'amount' => '130000.00',
            'status' => Refund::STATUS_REQUESTED,
        ]);
    }

    public function test_multiple_partial_refunds_reaching_exact_paid_amount_mark_full_refund(): void
    {
        [$admin, $customer, $order, $transaction, $firstRefund] = $this->createRefundContext('30.00');
        $order->update([
            'subtotal' => '100.00',
            'shipping_fee' => '0.00',
            'total_amount' => '100.00',
        ]);
        $transaction->update(['amount' => '100.00']);
        $service = app(PaymentService::class);

        $service->approveRefund($firstRefund, $admin->id);
        $service->executeRefund($firstRefund, $admin->id);

        $secondRefund = $service->requestRefund(
            $order->refresh(),
            '20.00',
            'Second partial refund',
            $customer->id
        );
        $service->approveRefund($secondRefund, $admin->id);
        $service->executeRefund($secondRefund, $admin->id);

        $this->assertSame(PaymentService::PAYMENT_PAID, $transaction->refresh()->payment_status);
        $this->assertSame(PaymentService::PAYMENT_PAID, $order->refresh()->payment_status);
        $this->assertSame(OrderLifecycleService::STATUS_DELIVERED, $order->status);

        $thirdRefund = $service->requestRefund(
            $order->refresh(),
            '50.00',
            'Final partial refund',
            $customer->id
        );
        $service->approveRefund($thirdRefund, $admin->id);
        $service->executeRefund($thirdRefund, $admin->id);

        $this->assertSame(Refund::STATUS_COMPLETED, $firstRefund->refresh()->status);
        $this->assertSame(Refund::STATUS_COMPLETED, $secondRefund->refresh()->status);
        $this->assertSame(Refund::STATUS_COMPLETED, $thirdRefund->refresh()->status);
        $this->assertSame(PaymentService::PAYMENT_REFUNDED, $transaction->refresh()->payment_status);
        $this->assertSame(PaymentService::PAYMENT_REFUNDED, $order->refresh()->payment_status);
        $this->assertSame(OrderLifecycleService::STATUS_REFUNDED, $order->status);
        $this->assertSame(1, $order->statusHistory()
            ->where('status', OrderLifecycleService::STATUS_REFUNDED)
            ->count());
    }

    public function test_generic_admin_status_endpoint_cannot_mark_order_refunded(): void
    {
        $admin = $this->createAdmin();
        $customer = User::factory()->create();
        $order = $this->createOrder($customer, 'SA-ADMIN-NO-DIRECT-REFUND', 'delivered', 'paid');

        $this->actingAs($admin)
            ->from(route('admin.orders.show', $order))
            ->post(route('admin.orders.status', $order), [
                'status' => OrderLifecycleService::STATUS_REFUNDED,
            ])
            ->assertRedirect(route('admin.orders.show', $order))
            ->assertSessionHasErrors('status');

        $this->assertSame(OrderLifecycleService::STATUS_DELIVERED, $order->refresh()->status);
        $this->assertDatabaseCount('refunds', 0);
        $this->assertDatabaseMissing('order_status_history', [
            'order_id' => $order->id,
            'status' => OrderLifecycleService::STATUS_REFUNDED,
        ]);
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

    private function createRefundContext(string $refundAmount = '230000.00'): array
    {
        $admin = $this->createAdmin();
        $customer = User::factory()->create();
        $order = $this->createOrder(
            $customer,
            'SA-ADMIN-REFUND-' . uniqid(),
            OrderLifecycleService::STATUS_DELIVERED,
            PaymentService::PAYMENT_PAID
        );
        $transaction = PaymentTransaction::create([
            'order_id' => $order->id,
            'gateway' => 'cod',
            'transaction_id' => 'TXN-ADMIN-REFUND-' . uniqid(),
            'payment_status' => PaymentService::PAYMENT_PAID,
            'amount' => '230000.00',
        ]);
        $refund = Refund::create([
            'order_id' => $order->id,
            'payment_transaction_id' => $transaction->id,
            'requested_by' => $customer->id,
            'amount' => $refundAmount,
            'reason' => 'Customer request',
            'status' => Refund::STATUS_REQUESTED,
        ]);

        return [$admin, $customer, $order, $transaction, $refund];
    }
}
