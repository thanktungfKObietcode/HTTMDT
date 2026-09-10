<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PaymentGatewayEvent;
use App\Models\PaymentTransaction;
use App\Models\Role;
use App\Models\User;
use App\Payments\VnPayGateway;
use App\Services\PaymentDeploymentPreflight;
use App\Services\PaymentService;
use App\Support\PaymentError;
use App\Support\PaymentSecurityConfiguration;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\VnPayQueryFixtures;
use Tests\TestCase;

class PhaseSixDThreeSecurityHardeningTest extends TestCase
{
    use DatabaseMigrations, VnPayQueryFixtures;

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:') {
            throw new \RuntimeException('Security tests require isolated SQLite memory.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->configureVnPay();
    }

    private function production(): void
    {
        config(['app.env' => 'production', 'app.debug' => false, 'app.url' => 'https://shop.example.test',
            'session.secure' => true, 'session.http_only' => true, 'session.same_site' => 'lax',
            'session.driver' => 'database', 'cache.default' => 'database', 'security.trusted_proxies' => []]);
    }

    #[DataProvider('unsafeProduction')]
    public function test_unsafe_production_configuration_stops_payment_before_routing(string $key, mixed $value): void
    {
        $this->production();
        config([$key => $value]);
        $this->assertNotEmpty(app(PaymentSecurityConfiguration::class)->failures());
        $this->post('https://shop.example.test/thanh-toan')->assertStatus(503)
            ->assertDontSee('SQLSTATE')->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame(0, Order::count());
        try {
            app(VnPayGateway::class)->assertConfigured();
            $this->fail('Unsafe runtime must not initiate gateway payments.');
        } catch (\RuntimeException $exception) {
            $this->assertStringNotContainsString('phase6c-fixture-only', $exception->getMessage());
        }
    }

    public static function unsafeProduction(): array
    {
        return [
            ['app.debug', true], ['app.url', 'http://shop.example.test'],
            ['app.url', 'https://user:secret@example.test'], ['session.secure', false],
            ['session.http_only', false], ['session.same_site', 'none'],
            ['session.driver', 'array'], ['cache.default', 'array'],
            ['security.trusted_proxies', ['*']], ['security.trusted_proxies', ['REMOTE_ADDR']],
            ['security.trusted_proxies', ['0.0.0.0/0']],
        ];
    }

    public function test_local_http_still_works_and_does_not_get_hsts_or_strict_csp(): void
    {
        config(['app.env' => 'local', 'app.debug' => true]);
        $this->get('/thanh-toan/vnpay/return')->assertOk()
            ->assertHeaderMissing('Strict-Transport-Security')->assertHeaderMissing('Content-Security-Policy')
            ->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertSame([], app(PaymentSecurityConfiguration::class)->failures());
    }

    public function test_production_https_headers_and_session_cookie_attributes(): void
    {
        $this->production();
        $response = $this->get('https://shop.example.test/thanh-toan/vnpay/return')->assertOk()
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000')
            ->assertHeader('Cache-Control', 'no-store, private');
        $cookie = collect($response->headers->getCookies())->first(fn ($cookie) => $cookie->getName() === config('session.cookie'));
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
    }

    public function test_untrusted_forwarded_https_and_wrong_host_are_rejected(): void
    {
        $this->production();
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
            ->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'shop.example.test'])
            ->get('http://shop.example.test/thanh-toan/vnpay/return')->assertStatus(400);
        $this->get('https://attacker.example.test/thanh-toan/vnpay/return')->assertStatus(400);
    }

    public function test_explicit_proxy_allows_forwarded_https_but_not_forwarded_host(): void
    {
        $this->production();
        config(['security.trusted_proxies' => ['192.0.2.10']]);
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])
            ->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'attacker.example.test'])
            ->get('http://shop.example.test/thanh-toan/vnpay/return')->assertOk()
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    }

    #[DataProvider('unsafeGateway')]
    public function test_production_gateway_requires_correct_callbacks_query_and_endpoint(string $key, mixed $value): void
    {
        $this->production();
        config([$key => $value]);
        $this->expectException(\RuntimeException::class);
        app(VnPayGateway::class)->assertConfigured();
    }

    public static function unsafeGateway(): array
    {
        return [
            ['vnpay.query_url', 'https://attacker.example.test/query'], ['vnpay.query_server_ip', ''],
            ['vnpay.query_timeout_seconds', 0], ['vnpay.hash_secret', ''],
            ['vnpay.return_url', 'https://attacker.example.test/return'],
            ['vnpay.ipn_url', 'https://shop.example.test/wrong-path'],
            ['vnpay.payment_url', 'https://sandbox.vnpayment.vn/wrong-path'],
            ['vnpay.payment_url', 'https://sandbox.vnpayment.vn:8443/paymentv2/vpcpay.html'],
        ];
    }

    public function test_legacy_simulated_callback_is_not_available_in_production(): void
    {
        $this->production();
        $this->post('https://shop.example.test/thanh-toan/callback')->assertNotFound();
        $this->assertSame(0, PaymentTransaction::count());
    }

    public function test_return_throttles_but_ipn_does_not_share_an_ip_rate_limit(): void
    {
        for ($i = 0; $i < 61; $i++) {
            $response = $this->get('/thanh-toan/vnpay/return');
        }
        $response->assertStatus(429);
        for ($i = 0; $i < 65; $i++) {
            $this->get('/thanh-toan/vnpay/ipn')->assertOk()->assertJsonStructure(['RspCode', 'Message']);
        }
        $this->assertSame(0, PaymentTransaction::count());
    }

    public function test_legacy_simulation_receipt_does_not_persist_signature(): void
    {
        [, $order, $tx] = $this->paymentContext();
        $order->update(['payment_method' => 'simulated-online']);
        $tx->update(['gateway' => 'simulated-online']);
        $payload = ['order_number' => $order->order_number, 'transaction_id' => $tx->transaction_id,
            'gateway' => 'simulated-online', 'payment_status' => 'paid', 'amount' => '210000.00'];
        $payload['signature'] = app(PaymentService::class)->callbackSignature($payload);
        $this->postJson('/thanh-toan/callback', $payload)->assertOk();
        $receipt = $tx->fresh()->payload['callback_payload'];
        $this->assertArrayNotHasKey('signature', $receipt);
        $this->assertCount(5, $receipt);
        $this->assertSame('paid', $tx->fresh()->payment_status);
    }

    public function test_large_gateway_query_is_rejected_without_audit_or_settlement(): void
    {
        $this->get('/thanh-toan/vnpay/ipn?extra='.str_repeat('a', 17000))->assertStatus(400);
        $this->assertSame(0, PaymentGatewayEvent::count());
        $this->assertSame(0, PaymentTransaction::count());
    }

    public function test_retry_and_checkout_share_customer_throttle_and_other_customer_is_unaffected(): void
    {
        [$user, $order] = $this->paymentContext();
        $this->actingAs($user);
        // Invalid checkout validation consumes this user's allowance, without creating orders.
        for ($i = 0; $i < 10; $i++) {
            $this->post('/thanh-toan')->assertRedirect();
        }
        $this->post(route('vnpay.initiate', $order))->assertStatus(429);
        $this->actingAs(User::factory()->create(['is_active' => true]))
            ->post('/thanh-toan')->assertRedirect();
        $this->assertSame(1, Order::count());
    }

    public function test_admin_reconciliation_throttles_before_external_query(): void
    {
        [, $order, $tx] = $this->paymentContext();
        $admin = User::factory()->create(['is_active' => true]);
        $admin->roles()->attach(Role::create(['name' => 'admin', 'guard_name' => 'web']));
        Http::fake(fn ($request) => Http::response($this->queryResponse($request['vnp_TxnRef'])));
        $this->actingAs($admin);
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('admin.orders.payments.reconcile', [$order, $tx]))->assertRedirect();
        }
        $this->post(route('admin.orders.payments.reconcile', [$order, $tx]))->assertStatus(429);
        Http::assertSentCount(5);
        $this->assertSame(1, PaymentTransaction::where('payment_status', 'paid')->count());
    }

    public function test_customer_post_still_requires_csrf_outside_test_bypass(): void
    {
        [$user, $order] = $this->paymentContext();
        $this->app->instance('env', 'production'); // Enable real framework CSRF checks, keep isolated DB/config.
        try {
            $this->actingAs($user)->post(route('vnpay.initiate', $order))->assertStatus(419);
        } finally {
            $this->app->instance('env', 'testing');
        }
        $this->assertSame('pending', $order->fresh()->payment_status);
        Http::assertNothingSent();
    }

    public function test_caught_database_exception_is_not_flashed_or_logged_raw(): void
    {
        [$user, $order] = $this->paymentContext();
        Log::spy();
        $this->mock(PaymentService::class, fn ($mock) => $mock->shouldReceive('requestRefund')
            ->once()->andThrow(new \PDOException('sensitive-test-sentinel SQLSTATE signed-url')));
        $this->actingAs($user)->post(route('payment.refund', $order), ['amount' => '1.00'])
            ->assertSessionHasErrors(['refund' => PaymentError::MESSAGE]);
        Log::shouldHaveReceived('warning')->once()->with('Payment operation failed safely.', ['exception_type' => \PDOException::class]);
    }

    public function test_uncaught_payment_exception_is_safe_even_with_debug_enabled(): void
    {
        config(['app.debug' => true]);
        Log::spy();
        Route::get('/thanh-toan/security-probe', fn () => throw new \PDOException('sensitive-test-sentinel'));
        $this->getJson('/thanh-toan/security-probe')->assertStatus(500)
            ->assertExactJson(['message' => PaymentError::MESSAGE])->assertDontSee('sensitive-test-sentinel');
        Log::shouldHaveReceived('warning')->once()->with('Payment operation failed safely.', ['exception_type' => \PDOException::class]);
    }

    public function test_caught_runtime_exception_logs_only_its_category_and_keeps_generic_response(): void
    {
        [$user, $order] = $this->paymentContext();
        $handler = new \Monolog\Handler\TestHandler();
        Log::swap(new \Illuminate\Log\Logger(new \Monolog\Logger('payment-test', [$handler])));
        $this->mock(PaymentService::class, fn ($mock) => $mock->shouldReceive('requestRefund')
            ->once()->andThrow(new \RuntimeException('private-runtime-message', 0,
                new \PDOException('private-previous-SQL-bindings'))));

        $this->actingAs($user)->post(route('payment.refund', $order), [
            'amount' => '1.00',
            'signature' => 'private-signature',
            'payload' => 'private-customer-payload',
        ])->assertSessionHasErrors(['refund' => PaymentError::MESSAGE]);

        $this->assertOnlyRuntimeExceptionCategoryLogged($handler);
    }

    public function test_uncaught_runtime_exception_logs_only_its_category_without_default_reporting(): void
    {
        config(['app.debug' => true]);
        $handler = new \Monolog\Handler\TestHandler();
        Log::swap(new \Illuminate\Log\Logger(new \Monolog\Logger('payment-test', [$handler])));
        Route::post('/thanh-toan/runtime-observability-probe', fn () => throw new \RuntimeException(
            'private-runtime-message', 0, new \PDOException('private-previous-SQL-bindings')));

        $this->postJson('/thanh-toan/runtime-observability-probe', [
            'vnp_SecureHash' => 'private-signature',
            'payload' => 'private-customer-payload',
        ])->assertStatus(500)->assertExactJson(['message' => PaymentError::MESSAGE]);

        $this->assertOnlyRuntimeExceptionCategoryLogged($handler);
    }

    private function assertOnlyRuntimeExceptionCategoryLogged(\Monolog\Handler\TestHandler $handler): void
    {
        // Capture actual log records: a second default report must not leak the exception.
        $records = $handler->getRecords();
        $this->assertCount(1, $records);
        $this->assertSame(\Monolog\Level::Warning, $records[0]->level);
        $this->assertSame('Payment operation failed safely.', $records[0]->message);
        $this->assertSame(['exception_type' => \RuntimeException::class], $records[0]->context);
        $this->assertSame([], $records[0]->extra);
        $serialized = json_encode($records, JSON_THROW_ON_ERROR);
        foreach (['private-runtime-message', 'private-previous-SQL-bindings', 'private-signature', 'private-customer-payload'] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, $serialized);
        }
    }

    public function test_http_server_error_message_is_also_redacted(): void
    {
        config(['app.debug' => true]);
        Route::get('/thanh-toan/server-error-probe', fn () => abort(500, 'sensitive-test-sentinel'));
        $this->getJson('/thanh-toan/server-error-probe')->assertStatus(500)
            ->assertExactJson(['message' => PaymentError::MESSAGE]);
    }

    public function test_expiry_candidate_database_failure_is_reported_without_raw_exception(): void
    {
        Log::spy();
        DB::connection()->beforeExecuting(function (string $sql): void {
            if (str_starts_with(strtolower($sql), 'select') && str_contains($sql, 'payment_expires_at')) {
                throw new \PDOException('sensitive-test-sentinel');
            }
        });
        $this->assertSame(1, Artisan::call('payments:expire-vnpay', ['--limit' => 25, '--dry-run' => true]));
        $this->assertStringNotContainsString('sensitive-test-sentinel', Artisan::output());
        Log::shouldHaveReceived('error')->once()->with('VNPay expiry candidate lookup failed.');
    }

    public function test_deployment_preflight_is_read_only_and_reports_static_production_failures(): void
    {
        $this->production();
        config(['app.debug' => true, 'vnpay.hash_secret' => 'sensitive-test-sentinel', 'vnpay.query_server_ip' => '']);
        $queries = [];
        DB::listen(function ($query) use (&$queries) { $queries[] = strtolower(ltrim($query->sql)); });
        $this->assertSame(1, Artisan::call('payments:deployment-check', ['--json' => true]));
        $output = Artisan::output();
        $result = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('FAIL', $result['verdict']);
        $checks = collect($result['checks'])->keyBy('id');
        $this->assertSame('fail', $checks['production_debug']['severity']);
        $this->assertSame('fail', $checks['vnpay_query_configuration']['severity']);
        $this->assertStringNotContainsString('sensitive-test-sentinel', $output);
        foreach ($queries as $sql) {
            $this->assertMatchesRegularExpression('/^(select|pragma)\b/', $sql);
        }
        Http::assertNothingSent();
    }

    public function test_preflight_connection_failure_returns_redacted_machine_readable_json(): void
    {
        $manager = app('db');
        DB::shouldReceive('connection')->andThrow(new \PDOException('sensitive-test-sentinel'));
        try {
            $this->assertSame(1, Artisan::call('payments:deployment-check', ['--json' => true]));
            $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('FAIL', $result['verdict']);
            $this->assertStringNotContainsString('sensitive-test-sentinel', Artisan::output());
        } finally {
            DB::swap($manager);
        }
    }

    public function test_expiry_schedule_is_opt_in_bounded_and_has_shared_overlap_protection(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => $event->description === 'vnpay-payment-expiry');
        $this->assertNotNull($event);
        $this->assertStringContainsString('--limit=25', $event->command);
        $this->assertSame('*/5 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);
        $this->assertSame(['production'], $event->environments);
        $this->assertFalse($event->filtersPass($this->app));
        $this->production();
        config(['vnpay.expiry_scheduler_enabled' => true]);
        $this->assertTrue($event->filtersPass($this->app));
        config(['cache.default' => 'array']);
        $this->assertFalse($event->filtersPass($this->app));
        Http::assertNothingSent();
    }
}
