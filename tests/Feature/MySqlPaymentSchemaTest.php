<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PaymentGatewayEvent;
use App\Models\PaymentTransaction;
use App\Payments\GatewayEventType;
use App\Services\OrderLifecycleService;
use App\Services\PaymentService;
use App\Support\PaymentAttemptReference;
use App\Support\PaymentMethod;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Support\Money;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Support\MySqlPaymentDatabaseGuard;

class MySqlPaymentSchemaTest extends TestCase
{
    use DatabaseMigrations;

    private const ISOLATED_DATABASE = 'httmdt_payment_test';

    protected function beforeRefreshingDatabase(): void
    {
        if (getenv('RUN_MYSQL_PAYMENT_TESTS') !== '1') {
            $this->markTestSkipped('Set RUN_MYSQL_PAYMENT_TESTS=1 only for the isolated MySQL payment test database.');
        }

        MySqlPaymentDatabaseGuard::assertIsolated();
    }

    public function test_mysql_innodb_payment_constraints_and_lock_queries(): void
    {
        $tables = DB::table('information_schema.TABLES')
            ->where('TABLE_SCHEMA', self::ISOLATED_DATABASE)
            ->whereIn('TABLE_NAME', ['orders', 'payment_transactions', 'refunds', 'payment_gateway_events'])
            ->pluck('ENGINE', 'TABLE_NAME');

        $this->assertCount(4, $tables);
        foreach ($tables as $engine) {
            $this->assertSame('InnoDB', $engine);
        }

        $order = $this->createOrder();
        try {
            $this->createOrder(['checkout_token' => $order->checkout_token]);
            $this->fail('Duplicate checkout token must be rejected by MySQL.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        $this->createOrder(['checkout_token' => null]);
        $this->createOrder(['checkout_token' => null]);
        $this->assertSame(2, Order::whereNull('checkout_token')->count());

        $reference = PaymentAttemptReference::generate();
        $first = $this->createTransaction($order, $reference);
        $this->assertTrue(str_ends_with(DB::selectOne(
            'SELECT COLLATION(transaction_id) AS collation_name FROM payment_transactions WHERE id = ?', [$first->id]
        )->collation_name, '_ci'));
        $this->rejects(fn () => $this->createTransaction($order, strtolower($reference)));
        try {
            $this->createTransaction($order, $reference);
            $this->fail('Duplicate merchant reference must be rejected by MySQL.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        $this->createTransaction($order, PaymentAttemptReference::generate());
        $this->assertSame(2, PaymentTransaction::query()->whereNull('gateway_transaction_id')->count());

        $externalId = '14567890';
        $this->createTransaction($order, PaymentAttemptReference::generate(), $externalId);
        try {
            $this->createTransaction($order, PaymentAttemptReference::generate(), $externalId);
            $this->fail('Duplicate gateway transaction identity must be rejected by MySQL.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        PaymentGatewayEvent::query()->create([
            'payment_transaction_id' => $first->id,
            'order_id' => $order->id,
            'gateway' => PaymentMethod::VNPAY,
            'event_type' => GatewayEventType::PaymentInitiated,
            'merchant_reference' => $first->transaction_id,
            'event_fingerprint' => hash('sha256', 'mysql-schema-test'),
            'signature_valid' => true,
            'reconciliation_required' => false,
            'metadata' => ['outcome' => 'processed', 'amount' => '125000.00'],
        ]);
        $this->assertSame('processed', DB::table('payment_gateway_events')
            ->value('metadata->outcome'));
        $this->rejects(fn () => DB::table('payment_gateway_events')->update(['metadata' => '{invalid']));
        $this->rejects(fn () => DB::table('payment_gateway_events')->update(['order_id' => 999999]));
        $this->assertSame('processed', PaymentGatewayEvent::first()->metadata['outcome']);
        $eventIndexes = collect(Schema::getIndexes('payment_gateway_events'));
        foreach (['merchant_reference', 'payment_transaction_id', 'event_fingerprint', 'reconciliation_required'] as $column) {
            $this->assertTrue($eventIndexes->contains(fn ($index) => $index['columns'][0] === $column));
        }
        try {
            $first->delete();
            $this->fail('Audit foreign key must restrict transaction deletion.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });
        DB::transaction(function () use ($order): void {
            Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            PaymentTransaction::query()->where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get();
        });

        $this->assertTrue(collect($queries)->contains(
            fn (string $sql): bool => str_contains($sql, 'payment_transactions')
                && str_contains($sql, 'order by `id` asc')
                && str_contains($sql, 'for update')
        ));

        $this->assertSame('+00:00', DB::selectOne('SELECT @@session.time_zone AS zone')->zone);
        $this->assertSame('UTC', config('app.timezone'));
        DB::table('payment_transactions')->where('id', $first->id)->update([
            'amount' => '9999999999999.99', 'expires_at' => '2026-09-10 03:00:00',
        ]);
        $this->assertSame('9999999999999.99', DB::table('payment_transactions')->where('id', $first->id)->value('amount'));
        $this->assertSame(999999999999999, Money::toMinorUnits((string) $first->fresh()->amount));
        $epoch = DB::selectOne('SELECT UNIX_TIMESTAMP(expires_at) AS epoch FROM payment_transactions WHERE id = ?', [$first->id])->epoch;
        $this->assertSame((new \DateTimeImmutable('2026-09-10T03:00:00Z'))->getTimestamp(), (int) $epoch);
        $this->assertSame('20260910100000', $first->fresh()->expires_at->setTimezone('Asia/Ho_Chi_Minh')->format('YmdHis'));
        DB::beginTransaction();
        DB::table('payment_transactions')->where('id', $first->id)->update(['amount' => '1.00']);
        DB::rollBack();
        $this->assertSame('9999999999999.99', DB::table('payment_transactions')->where('id', $first->id)->value('amount'));

        // A real down/up cycle reproduces InnoDB's implicit FK index replacement.
        $refund = DB::table('refunds')->insertGetId(['order_id' => $order->id, 'amount' => '1.00']);
        $row = DB::table('refunds')->find($refund);
        $this->assertSame('requested', $row->status);
        foreach (['requested_by', 'reviewed_by', 'processed_by', 'reviewed_at', 'processing_at', 'completed_at', 'failed_at'] as $field) {
            $this->assertNull($row->$field);
        }
        $migration = require database_path('migrations/2026_09_08_000002_add_workflow_fields_to_refunds_table.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('refunds', 'requested_by'));
        $this->rejects(fn () => DB::table('refunds')->where('id', $refund)->update(['order_id' => 999999]));
        DB::table('refunds')->where('id', $refund)->update(['status' => 'pending']);
        try {
            $migration->up();
            $this->fail('Legacy pending refunds must stop migration, not be guessed.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Refund statuses must be reviewed', $e->getMessage());
        }
        $this->assertFalse(Schema::hasColumn('refunds', 'requested_by'));
        DB::table('refunds')->where('id', $refund)->update(['status' => 'requested']);
        $migration->up();
        $this->assertTrue(Schema::hasColumn('refunds', 'requested_by'));

        // Exercise legacy identity preflight using the actual MySQL pre-6A schema.
        $identityMigration = require database_path('migrations/2026_09_09_000001_prepare_payment_transactions_for_vnpay.php');
        $identityMigration->down();
        $other = PaymentTransaction::where('id', '<>', $first->id)->firstOrFail();
        DB::table('payment_transactions')->where('id', $other->id)->update(['transaction_id' => 'normalizedref']);
        foreach ([null, '', str_repeat('X', 101), ' NormalizedRef '] as $invalidReference) {
            DB::table('payment_transactions')->where('id', $first->id)->update(['transaction_id' => $invalidReference]);
            try {
                $identityMigration->up();
                $this->fail('Unsafe legacy identity must stop the migration before DDL.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('Cannot enforce payment-attempt identity', $e->getMessage());
            }
            $this->assertFalse(Schema::hasColumn('payment_transactions', 'currency'));
        }
        DB::table('payment_transactions')->where('id', $first->id)->update(['transaction_id' => $reference]);
        $identityMigration->up();
        $this->assertSame('VND', $first->fresh()->currency);
    }

    private function rejects(callable $write): void
    {
        try {
            $write();
            $this->fail('MySQL must reject the invalid test write.');
        } catch (QueryException $e) {
            $this->assertContains($e->errorInfo[0], ['23000', '22032']);
        }
    }

    /** @param array<string,mixed> $overrides */
    private function createOrder(array $overrides = []): Order
    {
        return Order::query()->create(array_merge([
            'order_number' => 'ORD'.Str::upper(Str::random(20)),
            'checkout_token' => (string) Str::uuid(),
            'status' => OrderLifecycleService::STATUS_PENDING,
            'payment_method' => PaymentMethod::VNPAY,
            'payment_status' => PaymentService::PAYMENT_PENDING,
            'customer_name' => 'MySQL Payment Test',
            'customer_phone' => '0900000000',
            'shipping_address' => 'Isolated MySQL test address',
            'subtotal' => '125000.00',
            'shipping_fee' => '0.00',
            'discount_amount' => '0.00',
            'total_amount' => '125000.00',
        ], $overrides));
    }

    private function createTransaction(
        Order $order,
        string $reference,
        ?string $gatewayTransactionId = null
    ): PaymentTransaction {
        return PaymentTransaction::query()->create([
            'order_id' => $order->id,
            'gateway' => PaymentMethod::VNPAY,
            'transaction_id' => $reference,
            'gateway_transaction_id' => $gatewayTransactionId,
            'payment_status' => PaymentService::PAYMENT_PENDING,
            'amount' => '125000.00',
            'currency' => 'VND',
        ]);
    }
}
