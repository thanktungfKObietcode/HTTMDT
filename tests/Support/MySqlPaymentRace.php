<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

final class MySqlPaymentRace
{
    /** Two real PHP processes; both must be visible in InnoDB lock waits together. */
    public static function run(string $table, int $id, array $jobs): array
    {
        MySqlPaymentDatabaseGuard::assertIsolated();
        if (! in_array($table, ['orders', 'products'], true) || count($jobs) !== 2 || DB::transactionLevel() !== 0) {
            throw new RuntimeException('Invalid isolated race setup.');
        }
        $config = config('database.connections.mysql');
        config(['database.connections.race_blocker' => $config]);
        $blocker = DB::connection('race_blocker');
        if ($blocker->selectOne('SELECT DATABASE() AS db', [], false)->db !== MySqlPaymentDatabaseGuard::DATABASE) {
            throw new RuntimeException('Blocker connection is not isolated.');
        }
        $processes = $streams = [];
        try {
            foreach ($jobs as $job) {
                $stream = new InputStream;
                $process = new Process([PHP_BINARY, base_path('tests/Support/payment-concurrency-worker.php')], base_path(), [
                    'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql',
                    'DB_DATABASE' => MySqlPaymentDatabaseGuard::DATABASE, 'RUN_MYSQL_PAYMENT_TESTS' => '1',
                ]);
                $process->setInput($stream)->setTimeout(35)->start();
                $processes[] = $process;
                $streams[] = $stream;
                $stream->write(json_encode(['connection' => $config, 'job' => $job + ['lock_table' => $table]], JSON_THROW_ON_ERROR).PHP_EOL);
            }
            self::until(function () use ($processes): bool {
                foreach ($processes as $process) {
                    if (! self::message($process, 'ready')) {
                        return false;
                    }
                }
                return true;
            }, $processes, 'Workers did not reach the production locking boundary.');
            $ids = array_map(fn ($p) => (int) self::message($p, 'ready')['connection_id'], $processes);
            if ($ids[0] === $ids[1]) {
                throw new RuntimeException('Workers must use independent connections.');
            }

            $blocker->beginTransaction();
            $blocker->table($table)->where('id', $id)->lockForUpdate()->firstOrFail();
            foreach ($streams as $stream) {
                $stream->write("GO\n");
            }
            // This is evidence of actual simultaneous InnoDB waits, not timing guesses.
            self::until(fn () => (int) DB::selectOne(
                'SELECT COUNT(DISTINCT t.PROCESSLIST_ID) AS workers FROM performance_schema.data_lock_waits w '
                .'JOIN performance_schema.threads t ON t.THREAD_ID = w.REQUESTING_THREAD_ID '
                .'WHERE t.PROCESSLIST_ID IN (?, ?)', $ids
            )->workers === 2, $processes, 'Both workers were not simultaneously waiting on InnoDB locks.');
            $blocker->commit();

            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $message = self::message($process, 'done');
                if (! $process->isSuccessful() || ! $message || ! $message['barrier_reached']) {
                    throw new RuntimeException('Worker failed: '.json_encode(self::message($process, 'error')));
                }
                $results[] = $message['result'];
            }

            return ['results' => $results, 'overlapping_connections' => $ids];
        } finally {
            if ($blocker->transactionLevel() > 0) {
                $blocker->rollBack();
            }
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(0);
                }
            }
            foreach ($streams as $stream) {
                $stream->close();
            }
            DB::purge('race_blocker');
        }
    }

    private static function message(Process $process, string $kind): ?array
    {
        foreach (explode("\n", $process->getOutput()) as $line) {
            $data = json_decode($line, true);
            if (is_array($data) && ($data['kind'] ?? '') === $kind) {
                return $data;
            }
        }
        return null;
    }

    private static function until(callable $condition, array $processes, string $failure): void
    {
        $deadline = microtime(true) + 15;
        do {
            if ($condition()) {
                return;
            }
            foreach ($processes as $process) {
                if (! $process->isRunning()) {
                    throw new RuntimeException($failure.' Worker result: '.json_encode(
                        self::message($process, 'error') ?? self::message($process, 'done')));
                }
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException($failure);
    }
}
