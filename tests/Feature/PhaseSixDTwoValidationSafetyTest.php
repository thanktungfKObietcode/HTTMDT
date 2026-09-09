<?php

namespace Tests\Feature;

use Illuminate\Database\Connectors\MySqlConnector;
use Illuminate\Database\MySqlConnection;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\DB;
use Mockery;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\MySqlPaymentDatabaseGuard;
use Tests\TestCase;

class PhaseSixDTwoValidationSafetyTest extends TestCase
{
    private string|false $previousOptIn;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousOptIn = getenv('RUN_MYSQL_PAYMENT_TESTS');
        putenv('RUN_MYSQL_PAYMENT_TESTS=1');
        config(['database.default' => 'mysql', 'database.connections.mysql' => [
            'driver' => 'mysql', 'database' => MySqlPaymentDatabaseGuard::DATABASE,
            'host' => '127.0.0.1', 'url' => null, 'prefix' => '',
        ]]);
    }

    protected function tearDown(): void
    {
        putenv($this->previousOptIn === false
            ? 'RUN_MYSQL_PAYMENT_TESTS' : 'RUN_MYSQL_PAYMENT_TESTS='.$this->previousOptIn);
        parent::tearDown();
    }

    public function test_guard_rejects_development_database_before_connecting(): void
    {
        config(['database.connections.mysql.database' => 'silver_jewelry']);
        DB::shouldReceive('connection')->never();
        $this->expectException(RuntimeException::class);
        MySqlPaymentDatabaseGuard::assertIsolated();
    }

    public function test_guard_requires_opt_in_before_connecting(): void
    {
        putenv('RUN_MYSQL_PAYMENT_TESTS=0');
        DB::shouldReceive('connection')->never();
        $this->expectException(RuntimeException::class);
        MySqlPaymentDatabaseGuard::assertIsolated();
    }

    #[DataProvider('unsafeOverrides')]
    public function test_guard_rejects_connection_overrides_before_connecting(string $key, mixed $value): void
    {
        config(['database.connections.mysql.'.$key => $value]);
        DB::shouldReceive('connection')->never();
        $this->expectException(RuntimeException::class);
        MySqlPaymentDatabaseGuard::assertIsolated();
    }

    public static function unsafeOverrides(): array
    {
        return [
            'url' => ['url', 'mysql://127.0.0.1/silver_jewelry'],
            'read' => ['read', ['database' => 'silver_jewelry']],
            'write' => ['write', ['database' => 'silver_jewelry']],
        ];
    }

    public function test_url_can_override_declared_database_without_opening_a_connection(): void
    {
        config(['database.connections.mysql.url' => 'mysql://127.0.0.1/silver_jewelry']);
        // Laravel resolves URL configuration lazily; neither call opens a PDO.
        $this->assertSame(MySqlPaymentDatabaseGuard::DATABASE, config('database.connections.mysql.database'));
        $this->assertSame('silver_jewelry', DB::connection('mysql')->getDatabaseName());
    }

    #[DataProvider('unsafeConnectedStates')]
    public function test_guard_checks_server_identity_and_engine_before_migrations(string $database, string $engine): void
    {
        $this->mockConnection($database, $engine);
        $this->expectException(RuntimeException::class);
        MySqlPaymentDatabaseGuard::assertIsolated();
    }

    public static function unsafeConnectedStates(): array
    {
        return [
            'changed by session' => ['silver_jewelry', 'InnoDB'],
            'wrong engine' => [MySqlPaymentDatabaseGuard::DATABASE, 'MyISAM'],
        ];
    }

    public function test_guard_accepts_only_verified_test_database_without_writes(): void
    {
        $this->mockConnection(MySqlPaymentDatabaseGuard::DATABASE, 'InnoDB');
        MySqlPaymentDatabaseGuard::assertIsolated();
        $this->addToAssertionCount(1);
    }

    public function test_connection_errors_are_redacted(): void
    {
        DB::shouldReceive('connection')->once()->with('mysql')->andThrow(new RuntimeException('sensitive diagnostic sentinel'));
        try {
            MySqlPaymentDatabaseGuard::assertIsolated();
            $this->fail('Unverified connection must be refused.');
        } catch (RuntimeException $exception) {
            $this->assertStringNotContainsString('sentinel', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
    }

    public function test_utc_option_is_applied_to_the_connection_session_only(): void
    {
        $previous = Env::get('DB_TIMEZONE');
        try {
            Env::getRepository()->set('DB_TIMEZONE', '+00:00');
            $settings = require config_path('database.php');
            $pdo = Mockery::mock(PDO::class);
            $pdo->shouldReceive('exec')->once()->with("SET time_zone='+00:00';")->andReturn(0);
            $connector = new class extends MySqlConnector {
                public function configureForTest(PDO $pdo, array $settings): void
                {
                    $this->configureConnection($pdo, ['timezone' => $settings['timezone']]);
                }
            };
            $connector->configureForTest($pdo, $settings['connections']['mysql']);

            Env::getRepository()->clear('DB_TIMEZONE');
            $unset = require config_path('database.php');
            $this->assertNull($unset['connections']['mysql']['timezone']);
        } finally {
            if ($previous === null) {
                Env::getRepository()->clear('DB_TIMEZONE');
            } else {
                Env::getRepository()->set('DB_TIMEZONE', (string) $previous);
            }
        }
    }

    private function mockConnection(string $database, string $engine): void
    {
        $connection = Mockery::mock(MySqlConnection::class);
        DB::shouldReceive('connection')->once()->with('mysql')->andReturn($connection);
        $connection->shouldReceive('getDriverName')->once()->andReturn('mysql');
        $connection->shouldReceive('getDatabaseName')->once()->andReturn(MySqlPaymentDatabaseGuard::DATABASE);
        $connection->shouldReceive('getConfig')->with('read')->once()->andReturn(null);
        $connection->shouldReceive('getConfig')->with('write')->once()->andReturn(null);
        $connection->shouldReceive('selectOne')->once()->with(
            'SELECT DATABASE() AS database_name, @@default_storage_engine AS storage_engine', [], false
        )->andReturn((object) ['database_name' => $database, 'storage_engine' => $engine]);
        // Any write or unplanned database call fails this strict mock.
    }
}
