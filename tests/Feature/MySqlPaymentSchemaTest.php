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
use Illuminate\Support\Str;
use Tests\TestCase;

class MySqlPaymentSchemaTest extends TestCase
{
    use DatabaseMigrations;

    private const ISOLATED_DATABASE = 'httmdt_payment_test';

    protected function beforeRefreshingDatabase(): void
    {
        if (getenv('RUN_MYSQL_PAYMENT_TESTS') !== '1') {
            $this->markTestSkipped('Set RUN_MYSQL_PAYMENT_TESTS=1 only for the isolated MySQL payment test database.');
        }

        if (config('database.default') !== 'mysql'
            || config('database.connections.mysql.database') !== self::ISOLATED_DATABASE) {
            throw new \RuntimeException(
                'Refusing destructive test migration: DB_CONNECTION must be mysql and DB_DATABASE must be '.self::ISOLATED_DATABASE.'.'
            );
        }
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

        $reference = PaymentAttemptReference::generate();
        $first = $this->createTransaction($order, $reference);
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
        ]);
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
