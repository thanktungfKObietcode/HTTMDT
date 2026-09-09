<?php

// Test-only subprocess. Connection settings travel through stdin, never argv/logs.
use App\Http\Controllers\VnPayController;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Services\OrderLifecycleService;
use App\Services\VnPayExpiryService;
use App\Services\VnPayReconciliationService;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\VnPayQueryFixtures;
use Tests\Support\MySqlPaymentDatabaseGuard;

require dirname(__DIR__, 2).'/vendor/autoload.php';

try {
    $input = json_decode(trim(fgets(STDIN)), true, flags: JSON_THROW_ON_ERROR);
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    config()->set(['database.connections.mysql' => $input['connection'],
        'session.driver' => 'array', 'cache.default' => 'array', 'logging.default' => 'null']);
    MySqlPaymentDatabaseGuard::assertIsolated();
    Http::preventStrayRequests();

    $worker = new class {
        use VnPayQueryFixtures;

        public function run(array $job): array
        {
            $this->configureVnPay();
            $db = DB::connection();
            $connectionId = (int) $db->selectOne('SELECT CONNECTION_ID() AS id')->id;
            $matches = 0;
            $released = false;
            $db->beforeExecuting(function (string $sql) use ($job, $connectionId, &$matches, &$released): void {
                if ($released || ! str_contains(strtolower($sql), 'for update')
                    || ! str_contains(strtolower($sql), 'from `'.$job['lock_table'].'`')) {
                    return;
                }
                if (++$matches !== ($job['lock_ordinal'] ?? 1)) {
                    return;
                }
                $released = true;
                echo json_encode(['kind' => 'ready', 'connection_id' => $connectionId]).PHP_EOL;
                flush();
                if (trim(fgets(STDIN)) !== 'GO') {
                    throw new RuntimeException('Barrier not released.');
                }
            });

            if (in_array($job['action'], ['query', 'expiry'], true)) {
                Http::fake(fn ($request) => Http::response($this->queryResponse($request['vnp_TxnRef'], [
                    'vnp_TransactionStatus' => $job['action'] === 'expiry' ? '02' : '00',
                ])));
            }

            $result = match ($job['action']) {
                'cancel' => app(OrderLifecycleService::class)->transition(Order::findOrFail($job['order_id']), 'cancelled')->status,
                'expiry' => app(VnPayExpiryService::class)->expire(Order::findOrFail($job['order_id'])),
                'query' => app(VnPayReconciliationService::class)->reconcile(PaymentTransaction::findOrFail($job['transaction_id']))->outcome->value,
                'ipn' => app(VnPayController::class)->ipn(Request::create('/thanh-toan/vnpay/ipn', 'GET',
                    $this->ipnPayload(PaymentTransaction::findOrFail($job['transaction_id']), [
                        'vnp_TransactionNo' => $job['external_id'] ?? '1234567',
                    ])))->getData(true)['RspCode'],
                'checkout' => $this->checkout($job),
                default => throw new RuntimeException('Unknown test operation.'),
            };

            return ['kind' => 'done', 'result' => $result, 'barrier_reached' => $released];
        }

        private function checkout(array $job): int
        {
            Auth::guard()->setUser(User::findOrFail($job['user_id']));
            $request = Request::create('/thanh-toan', 'POST', [
                'customer_name' => 'Race Test', 'customer_phone' => '0900000000',
                'customer_email' => 'race@example.test', 'province' => 'Ha Noi',
                'district' => 'Test district', 'ward' => 'Test ward', 'address_line' => 'Test address',
                'shipping_method_id' => $job['shipping_id'], 'payment_method' => 'cod',
                'checkout_token' => (string) Str::uuid(),
            ]);

            return app(Kernel::class)->handle($request)->getStatusCode();
        }
    };
    echo json_encode($worker->run($input['job']), JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $e) {
    // Never echo exception messages/SQL bindings/connection configuration.
    echo json_encode(['kind' => 'error', 'class' => $e::class, 'file' => basename($e->getFile()), 'line' => $e->getLine()]).PHP_EOL;
    exit(1);
}
