<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\PaymentGatewayEvent;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\ShippingMethod;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Str;
use Tests\Concerns\VnPayQueryFixtures;
use Tests\Support\MySqlPaymentDatabaseGuard;
use Tests\Support\MySqlPaymentRace;
use Tests\TestCase;

class MySqlPaymentConcurrencyTest extends TestCase
{
    use DatabaseMigrations, VnPayQueryFixtures;

    protected function beforeRefreshingDatabase(): void
    {
        if (getenv('RUN_MYSQL_PAYMENT_TESTS') !== '1') {
            $this->markTestSkipped('Requires explicit isolated MySQL payment test configuration.');
        }
        MySqlPaymentDatabaseGuard::assertIsolated();
    }

    public function test_a_concurrent_checkout_does_not_oversell(): void
    {
        $product = Product::create(['name' => 'Limited ring', 'slug' => Str::random(16), 'sku' => Str::random(16),
            'price' => '105000.00', 'stock' => 1, 'is_active' => true]);
        $shipping = ShippingMethod::create(['name' => 'Test shipping', 'code' => 'race', 'base_fee' => 0, 'is_active' => true]);
        $jobs = [];
        foreach (range(1, 2) as $unused) {
            $user = User::factory()->create(['is_active' => true]);
            $cart = Cart::create(['user_id' => $user->id]);
            $cart->items()->create(['product_id' => $product->id, 'quantity' => 1, 'unit_price' => '105000.00']);
            $jobs[] = ['action' => 'checkout', 'user_id' => $user->id, 'shipping_id' => $shipping->id];
        }
        $race = MySqlPaymentRace::run('products', $product->id, $jobs);
        $this->assertCount(2, array_unique($race['overlapping_connections']));
        $this->assertSame([302, 302], $race['results']);
        $this->assertSame(0, $product->fresh()->stock);
        $this->assertSame(1, Order::count());
        $this->assertSame(1, OrderItem::count());
        $this->assertSame(1, (int) OrderItem::sum('quantity'));
        $this->assertSame(1, PaymentTransaction::count());
        $this->assertSame(1, \App\Models\CartItem::count());
    }

    public function test_b_concurrent_cancellation_restores_once(): void
    {
        [$order, $tx, $product] = $this->context();
        $race = $this->race($order, [['action' => 'cancel'], ['action' => 'cancel']]);
        $this->assertSame(['cancelled', 'cancelled'], $race['results']);
        $this->assertCancelledOnce($order, $product);
        $this->assertSame('failed', $tx->fresh()->payment_status);
    }

    public function test_c_successful_ipn_races_final_expiry_decision(): void
    {
        [$order, $tx, $product] = $this->context();
        $race = $this->race($order, [
            ['action' => 'ipn', 'transaction_id' => $tx->id],
            ['action' => 'expiry', 'lock_ordinal' => 2],
        ]);
        $this->assertContains($race['results'][0], ['00', '99']);
        if ($order->fresh()->payment_status === 'paid') {
            $this->assertSame(3, $product->fresh()->stock);
            $this->assertSame('pending', $order->fresh()->status);
            $this->assertSame('skipped_paid', $race['results'][1]);
            $this->assertSame(0, OrderStatusHistory::where('status', 'cancelled')->count());
        } else {
            $this->assertCancelledOnce($order, $product);
            $this->assertTrue($tx->fresh()->requiresReconciliation());
            $this->assertSame(1, PaymentGatewayEvent::where('event_type', 'ipn_conflict')->count());
        }
    }

    public function test_d_querydr_and_ipn_share_one_effective_settlement(): void
    {
        [$order, $tx, $product] = $this->context();
        $race = $this->race($order, [
            ['action' => 'query', 'transaction_id' => $tx->id],
            ['action' => 'ipn', 'transaction_id' => $tx->id],
        ]);
        $this->assertContains($race['results'][0], ['processed', 'already_processed']);
        $this->assertContains($race['results'][1], ['00', '02']);
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame(1, PaymentTransaction::where('payment_status', 'paid')->count());
        $this->assertSame(3, $product->fresh()->stock);
        $outcomes = PaymentGatewayEvent::whereIn('event_type', ['ipn_settled', 'ipn_duplicate', 'query_settled'])->get();
        $this->assertSame(1, $outcomes->filter(fn ($event) => $event->metadata['outcome'] === 'processed')->count());
        $this->assertSame(1, $outcomes->filter(fn ($event) => $event->metadata['outcome'] === 'already_processed')->count());
        $this->assertSame(0, PaymentGatewayEvent::where('reconciliation_required', true)->count());
    }

    public function test_e_two_expiry_workers_restore_once(): void
    {
        [$order, $tx, $product] = $this->context();
        $race = $this->race($order, [
            ['action' => 'expiry', 'lock_ordinal' => 2], ['action' => 'expiry', 'lock_ordinal' => 2],
        ]);
        $this->assertSame(1, count(array_filter($race['results'], fn ($result) => $result === 'cancelled')));
        $this->assertCancelledOnce($order, $product);
        $this->assertSame(1, PaymentGatewayEvent::where('event_type', 'expiry_cancelled')->count());
        $this->assertSame('failed', $tx->fresh()->payment_status);
    }

    public function test_f_two_successful_attempts_do_not_settle_twice(): void
    {
        [$order, $tx, $product] = $this->context();
        $tx->update(['payment_status' => 'failed']);
        $second = app(PaymentService::class)->createOrGetPendingTransaction($order);
        $race = $this->race($order, [
            ['action' => 'ipn', 'transaction_id' => $tx->id, 'external_id' => '1234567'],
            ['action' => 'ipn', 'transaction_id' => $second->id, 'external_id' => '7654321'],
        ]);
        $results = $race['results'];
        sort($results);
        $this->assertSame(['00', '99'], $results);
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame(1, PaymentTransaction::where('payment_status', 'paid')->count());
        $this->assertSame(1, PaymentTransaction::get()->filter(fn ($attempt) => $attempt->requiresReconciliation())->count());
        $this->assertSame(1, PaymentGatewayEvent::where('event_type', 'ipn_settled')->count());
        $this->assertSame(1, PaymentGatewayEvent::where('event_type', 'ipn_conflict')->count());
        $this->assertSame(3, $product->fresh()->stock);
    }

    private function context(): array
    {
        $this->configureVnPay();
        [, $order, $tx, $product] = $this->paymentContext();
        $order->update(['payment_expires_at' => now()->subMinute()]);
        $tx->update(['expires_at' => now()->subMinute()]);
        return [$order, $tx, $product];
    }

    private function race(Order $order, array $jobs): array
    {
        $result = MySqlPaymentRace::run('orders', $order->id,
            array_map(fn ($job) => $job + ['order_id' => $order->id], $jobs));
        $this->assertCount(2, array_unique($result['overlapping_connections']));
        return $result;
    }

    private function assertCancelledOnce(Order $order, Product $product): void
    {
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame(5, $product->fresh()->stock);
        $this->assertSame(1, OrderStatusHistory::where('order_id', $order->id)->where('status', 'cancelled')->count());
        $this->assertSame(0, PaymentTransaction::where('payment_status', 'paid')->count());
    }
}
