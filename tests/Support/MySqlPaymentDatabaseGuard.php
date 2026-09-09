<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class MySqlPaymentDatabaseGuard
{
    public const DATABASE = 'httmdt_payment_test';

    /** Read-only proof, called BEFORE DatabaseMigrations may reset any schema. */
    public static function assertIsolated(): void
    {
        if (getenv('RUN_MYSQL_PAYMENT_TESTS') !== '1'
            || ! app()->environment('testing') || app()->configurationIsCached()
            || config('database.default') !== 'mysql'
            || config('database.connections.mysql.driver') !== 'mysql'
            || config('database.connections.mysql.database') !== self::DATABASE) {
            throw new RuntimeException('MySQL payment tests require explicit testing configuration and an isolated database.');
        }

        // URLs and read/write overrides can replace a seemingly safe database name.
        foreach (['url', 'read', 'write'] as $override) {
            if (! empty(config('database.connections.mysql.'.$override))) {
                throw new RuntimeException('MySQL payment tests refuse URL or read/write connection overrides.');
            }
        }

        try {
            $connection = DB::connection('mysql');
            if ($connection->getDriverName() !== 'mysql'
                || $connection->getDatabaseName() !== self::DATABASE
                || $connection->getConfig('read') || $connection->getConfig('write')) {
                throw new RuntimeException('Resolved database is not isolated.');
            }
            // Use the write PDO: this is the exact connection used by migrations.
            $identity = $connection->selectOne(
                'SELECT DATABASE() AS database_name, @@default_storage_engine AS storage_engine', [], false
            );
            if ($identity?->database_name !== self::DATABASE
                || strcasecmp((string) ($identity->storage_engine ?? ''), 'InnoDB') !== 0) {
                throw new RuntimeException('Connected database is not isolated InnoDB.');
            }
        } catch (Throwable) {
            // Do not expose connection credentials or SQL bindings in test errors.
            throw new RuntimeException('Cannot prove isolated MySQL payment test connection; no migrations are permitted.');
        }
    }
}
