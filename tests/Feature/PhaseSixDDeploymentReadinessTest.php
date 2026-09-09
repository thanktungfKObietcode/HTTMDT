<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PaymentGatewayEvent;
use App\Models\PaymentTransaction;
use App\Models\Refund;
use App\Payments\GatewayEventType;
use App\Services\OrderLifecycleService;
use App\Services\PaymentDeploymentPreflight;
use App\Services\PaymentService;
use App\Support\Money;
use App\Support\PaymentAttemptReference;
use App\Support\PaymentMethod;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class PhaseSixDDeploymentReadinessTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:') {
            throw new \RuntimeException('Phase 6D deployment tests require isolated SQLite memory; never use the development DB.');
        }
    }

    public function test_pending_migrations_expose_expected_identity_workflow_and_audit_schema(): void
    {
        $this->assertTrue(Schema::hasColumn('orders', 'checkout_token'));
        $this->assertTrue(Schema::hasColumn('orders', 'payment_expires_at'));
        $this->assertTrue(Schema::hasColumns('payment_transactions', [
            'gateway_transaction_id', 'currency', 'gateway_response_code',
            'gateway_transaction_status', 'paid_at', 'expires_at',
        ]));
        $this->assertTrue(Schema::hasColumns('refunds', [
            'requested_by', 'reviewed_by', 'processed_by', 'admin_note',
            'reviewed_at', 'processing_at', 'completed_at', 'failed_at',
        ]));
        $this->assertTrue(Schema::hasColumns('payment_gateway_events', [
            'payment_transaction_id', 'order_id', 'merchant_reference',
            'gateway_transaction_id', 'event_fingerprint', 'reconciliation_required',
        ]));

        $refund = Refund::query()->create([
            'order_id' => $this->createOrder()->id,
            'amount' => '1000.00',
            'reason' => 'Schema default test',
        ]);

        $this->assertSame(Refund::STATUS_REQUESTED, $refund->fresh()->status);
    }

    public function test_database_constraints_preserve_attempt_and_checkout_identity(): void
    {
        $checkoutToken = (string) Str::uuid();
        $this->createOrder(['checkout_token' => $checkoutToken]);

        try {
            $this->createOrder(['checkout_token' => $checkoutToken]);
            $this->fail('Duplicate checkout tokens must be rejected.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        $order = $this->createOrder();
        $reference = PaymentAttemptReference::generate();
        $this->createTransaction($order, $reference);

        $this->expectException(QueryException::class);
        $this->createTransaction($order, $reference);
    }

    public function test_gateway_identity_allows_multiple_nulls_but_rejects_duplicate_non_null_values(): void
    {
        $order = $this->createOrder();
        $this->createTransaction($order, PaymentAttemptReference::generate());
        $this->createTransaction($order, PaymentAttemptReference::generate());
        $this->assertSame(2, PaymentTransaction::query()->whereNull('gateway_transaction_id')->count());

        $this->createTransaction($order, PaymentAttemptReference::generate(), '14567890');

        $this->expectException(QueryException::class);
        $this->createTransaction($order, PaymentAttemptReference::generate(), '14567890');
    }

    public function test_gateway_event_foreign_keys_preserve_append_only_audit_history(): void
    {
        $order = $this->createOrder();
        $transaction = $this->createTransaction($order, PaymentAttemptReference::generate());
        PaymentGatewayEvent::query()->create([
            'payment_transaction_id' => $transaction->id,
            'order_id' => $order->id,
            'gateway' => PaymentMethod::VNPAY,
            'event_type' => GatewayEventType::PaymentInitiated,
            'merchant_reference' => $transaction->transaction_id,
            'event_fingerprint' => hash('sha256', $transaction->transaction_id),
            'signature_valid' => true,
            'reconciliation_required' => false,
            'metadata' => ['source' => 'deployment_test'],
        ]);

        $this->expectException(QueryException::class);
        $transaction->delete();
    }

    public function test_preflight_is_read_only_and_clean_isolated_schema_has_no_failures(): void
    {
        $order = $this->createOrder();
        $this->createTransaction($order, PaymentAttemptReference::generate());
        $before = $this->tableCounts();
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = ltrim(strtolower($query->sql));
        });

        $result = app(PaymentDeploymentPreflight::class)->run();

        $this->assertSame(0, $result['summary']['fail']);
        $this->assertSame($before, $this->tableCounts());
        $this->assertNotEmpty($queries);
        foreach ($queries as $sql) {
            $this->assertDoesNotMatchRegularExpression(
                '/^(insert|update|delete|replace|alter|create|drop|truncate)\b/',
                $sql
            );
        }
    }

    public function test_preflight_fails_closed_for_legacy_refund_status(): void
    {
        Refund::query()->create([
            'order_id' => $this->createOrder()->id,
            'amount' => '1000.00',
            'reason' => 'Legacy state',
            'status' => 'pending',
        ]);

        $result = app(PaymentDeploymentPreflight::class)->run();
        $check = collect($result['checks'])->firstWhere('id', 'refund_status_legacy_pending');

        $this->assertSame('FAIL', $result['verdict']);
        $this->assertSame('fail', $check['severity']);
        $this->assertSame(1, $check['count']);
    }

    public function test_refund_migration_refuses_to_guess_a_legacy_pending_state(): void
    {
        $blocked = $this->onIsolatedMigrationConnection(function (): void {
            Schema::create('refunds', function (Blueprint $table): void {
                $table->id();
                $table->string('status')->default('pending');
            });
            DB::table('refunds')->insert(['status' => 'pending']);

            $migration = require database_path('migrations/2026_09_08_000002_add_workflow_fields_to_refunds_table.php');
            $migration->up();
        });

        $this->assertTrue($blocked, 'Legacy refund state must block migration until explicitly reviewed.');
    }

    public function test_payment_identity_migration_detects_mysql_style_normalized_collisions(): void
    {
        $blocked = $this->onIsolatedMigrationConnection(function (): void {
            Schema::create('payment_transactions', function (Blueprint $table): void {
                $table->id();
                $table->string('transaction_id')->nullable();
            });
            DB::table('payment_transactions')->insert([
                ['transaction_id' => 'ABC123'],
                ['transaction_id' => 'abc123 '],
            ]);

            $migration = require database_path('migrations/2026_09_09_000001_prepare_payment_transactions_for_vnpay.php');
            $migration->up();
        });

        $this->assertTrue($blocked, 'References colliding under MySQL trim/case semantics must block migration.');
    }

    public function test_preflight_command_reports_machine_readable_result_without_mutation(): void
    {
        $order = $this->createOrder();
        $this->createTransaction($order, PaymentAttemptReference::generate());
        $before = $this->tableCounts();

        $this->artisan('payments:deployment-check', ['--json' => true])
            ->expectsOutputToContain('"verdict":"WARNING"')
            ->assertSuccessful();

        $this->assertSame($before, $this->tableCounts());
    }

    public function test_decimal_values_round_trip_through_minor_units_without_float_arithmetic(): void
    {
        $amount = '123456789.01';
        $order = $this->createOrder(['total_amount' => $amount, 'subtotal' => $amount]);
        $transaction = $this->createTransaction(
            $order,
            PaymentAttemptReference::generate(),
            amount: $amount
        );

        $this->assertSame($amount, $transaction->fresh()->amount);
        $this->assertSame(12345678901, Money::toMinorUnits($transaction->amount));
        $this->assertSame($order->fresh()->total_amount, Money::fromMinorUnits(Money::toMinorUnits($transaction->amount)));
    }

    /** @param array<string,mixed> $overrides */
    private function createOrder(array $overrides = []): Order
    {
        return Order::query()->create(array_merge([
            'order_number' => 'ORD'.Str::upper(Str::random(20)),
            'status' => OrderLifecycleService::STATUS_PENDING,
            'payment_method' => PaymentMethod::VNPAY,
            'payment_status' => PaymentService::PAYMENT_PENDING,
            'customer_name' => 'Deployment Test',
            'customer_phone' => '0900000000',
            'customer_email' => null,
            'shipping_address' => 'Isolated test address',
            'subtotal' => '125000.00',
            'shipping_fee' => '0.00',
            'discount_amount' => '0.00',
            'total_amount' => '125000.00',
        ], $overrides));
    }

    private function createTransaction(
        Order $order,
        string $reference,
        ?string $gatewayTransactionId = null,
        string $amount = '125000.00'
    ): PaymentTransaction {
        return PaymentTransaction::query()->create([
            'order_id' => $order->id,
            'gateway' => PaymentMethod::VNPAY,
            'transaction_id' => $reference,
            'gateway_transaction_id' => $gatewayTransactionId,
            'payment_status' => PaymentService::PAYMENT_PENDING,
            'amount' => $amount,
            'currency' => 'VND',
        ]);
    }

    /** @return array<string,int> */
    private function tableCounts(): array
    {
        return [
            'orders' => DB::table('orders')->count(),
            'payment_transactions' => DB::table('payment_transactions')->count(),
            'refunds' => DB::table('refunds')->count(),
            'payment_gateway_events' => DB::table('payment_gateway_events')->count(),
        ];
    }

    private function onIsolatedMigrationConnection(callable $callback): bool
    {
        $original = DB::getDefaultConnection();
        config(['database.connections.phase6d_guard' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
        DB::setDefaultConnection('phase6d_guard');

        try {
            $callback();

            return false;
        } catch (\RuntimeException) {
            return true;
        } finally {
            DB::disconnect('phase6d_guard');
            DB::setDefaultConnection($original);
            DB::purge('phase6d_guard');
        }
    }
}
