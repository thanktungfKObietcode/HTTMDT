<?php

namespace App\Services;

use App\Payments\GatewayEventType;
use App\Support\PaymentMethod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class PaymentDeploymentPreflight
{
    private const ORDER_STATUSES = ['pending', 'confirmed', 'processing', 'shipped', 'delivered', 'cancelled', 'refunded'];

    private const PAYMENT_STATUSES = ['pending', 'paid', 'failed', 'refunded'];

    private const REFUND_STATUSES = ['requested', 'approved', 'rejected', 'processing', 'completed', 'failed'];

    /** @return array{verdict:string,summary:array{pass:int,warning:int,fail:int},checks:array<int,array{id:string,severity:string,count:int,message:string}>} */
    public function run(): array
    {
        $checks = [];
        foreach (['orders', 'payment_transactions', 'refunds'] as $table) {
            $exists = Schema::hasTable($table);
            $this->check($checks, 'table_'.$table, $exists ? 'pass' : 'fail',
                $exists ? 0 : 1, 'Required baseline table: '.$table.'.');
        }
        if (collect($checks)->contains(fn (array $check): bool => $check['severity'] === 'fail')) {
            return $this->result($checks);
        }

        $length = DB::connection()->getDriverName() === 'sqlite' ? 'LENGTH' : 'CHAR_LENGTH';
        $missingRefs = DB::table('payment_transactions')->whereNull('transaction_id')
            ->orWhereRaw("TRIM(transaction_id) = ''")->count();
        $oversizedRefs = DB::table('payment_transactions')->whereRaw($length.'(transaction_id) > 100')->count();
        $duplicateRefs = $this->groupCount(
            DB::table('payment_transactions')->selectRaw('LOWER(TRIM(transaction_id)) AS normalized_reference')
                ->whereNotNull('transaction_id')->whereRaw("TRIM(transaction_id) <> ''")
                ->groupByRaw('LOWER(TRIM(transaction_id))')->havingRaw('COUNT(*) > 1')
        );
        $this->countCheck($checks, 'transaction_reference_missing', $missingRefs, 'fail', 'Null or blank merchant references.');
        $this->countCheck($checks, 'transaction_reference_oversized', $oversizedRefs, 'fail', 'Merchant references longer than VARCHAR(100).');
        $this->countCheck($checks, 'transaction_reference_duplicate_normalized', $duplicateRefs, 'fail', 'Merchant references collide after trim/case normalization.');

        $invalidTxStatus = DB::table('payment_transactions')->whereNotIn('payment_status', self::PAYMENT_STATUSES)->count();
        $invalidGateway = DB::table('payment_transactions')->whereNotIn('gateway', PaymentMethod::known())->count();
        $invalidAmount = DB::table('payment_transactions')->where('amount', '<=', 0)->count();
        $this->countCheck($checks, 'transaction_status_invalid', $invalidTxStatus, 'fail', 'Unsupported payment transaction statuses.');
        $this->countCheck($checks, 'transaction_gateway_unknown', $invalidGateway, 'warning', 'Unknown payment gateway values require review.');
        $this->countCheck($checks, 'transaction_amount_nonpositive', $invalidAmount, 'fail', 'Non-positive payment transaction amounts.');

        if (Schema::hasColumn('payment_transactions', 'currency')) {
            $invalidCurrency = DB::table('payment_transactions')->whereNull('currency')
                ->orWhere('currency', '<>', 'VND')->count();
            $this->countCheck($checks, 'transaction_currency_invalid', $invalidCurrency, 'fail', 'Currency must be VND for current gateways.');
        } else {
            $this->check($checks, 'transaction_currency_column', 'warning', 1, 'Phase 6A currency column migration is pending.');
        }

        if (Schema::hasColumn('payment_transactions', 'gateway_transaction_id')) {
            $duplicates = $this->groupCount(
                DB::table('payment_transactions')
                    ->selectRaw('LOWER(TRIM(gateway)) AS normalized_gateway, LOWER(TRIM(gateway_transaction_id)) AS normalized_gateway_id')
                    ->whereNotNull('gateway_transaction_id')->whereRaw("TRIM(gateway_transaction_id) <> ''")
                    ->groupByRaw('LOWER(TRIM(gateway)), LOWER(TRIM(gateway_transaction_id))')
                    ->havingRaw('COUNT(*) > 1')
            );
            $blankIds = DB::table('payment_transactions')->whereNotNull('gateway_transaction_id')
                ->whereRaw("TRIM(gateway_transaction_id) = ''")->count();
            $this->countCheck($checks, 'gateway_transaction_id_duplicate', $duplicates, 'fail', 'Gateway transaction IDs collide within a gateway.');
            $this->countCheck($checks, 'gateway_transaction_id_blank', $blankIds, 'fail', 'Blank non-null gateway transaction IDs.');
        } else {
            $this->check($checks, 'gateway_transaction_id_column', 'warning', 1, 'Phase 6A gateway identity migration is pending.');
        }

        $legacyRefunds = DB::table('refunds')->where('status', 'pending')->count();
        $unknownRefunds = DB::table('refunds')->whereNotIn('status', array_merge(self::REFUND_STATUSES, ['pending']))->count();
        $this->countCheck($checks, 'refund_status_legacy_pending', $legacyRefunds, 'fail', 'Legacy pending refunds require an explicit business review before migration.');
        $this->countCheck($checks, 'refund_status_unknown', $unknownRefunds, 'fail', 'Unknown refund statuses.');
        if (! Schema::hasColumn('refunds', 'requested_by')) {
            $this->check($checks, 'refund_workflow_columns', 'warning', 1, 'Refund workflow migration is pending.');
        }

        $invalidOrderStatus = DB::table('orders')->whereNotIn('status', self::ORDER_STATUSES)->count();
        $invalidOrderPayment = DB::table('orders')->whereNotIn('payment_status', self::PAYMENT_STATUSES)->count();
        $ambiguousMethod = DB::table('orders')->whereNull('payment_method')->orWhereRaw("TRIM(payment_method) = ''")
            ->orWhereNotIn('payment_method', PaymentMethod::known())->count();
        $invalidOrderAmount = DB::table('orders')->where('total_amount', '<', 0)->count();
        $this->countCheck($checks, 'order_status_invalid', $invalidOrderStatus, 'fail', 'Unsupported order statuses.');
        $this->countCheck($checks, 'order_payment_status_invalid', $invalidOrderPayment, 'fail', 'Unsupported order payment statuses.');
        $this->countCheck($checks, 'order_payment_method_ambiguous', $ambiguousMethod, 'warning', 'Missing or unknown order payment methods.');
        $this->countCheck($checks, 'order_total_negative', $invalidOrderAmount, 'fail', 'Negative authoritative order totals.');

        $paidCountMismatch = $this->groupCount(
            DB::table('orders as o')->leftJoin('payment_transactions as pt', function ($join): void {
                $join->on('pt.order_id', '=', 'o.id')->where('pt.payment_status', '=', 'paid');
            })->where('o.payment_status', 'paid')->selectRaw('o.id, COUNT(pt.id) AS paid_count')
                ->groupBy('o.id')->havingRaw('COUNT(pt.id) <> 1')
        );
        $paidAmountMismatch = DB::table('orders as o')->join('payment_transactions as pt', function ($join): void {
            $join->on('pt.order_id', '=', 'o.id')->where('pt.payment_status', '=', 'paid');
        })->where('o.payment_status', 'paid')->whereColumn('o.total_amount', '<>', 'pt.amount')->count();
        $cancelledPaid = DB::table('orders as o')->join('payment_transactions as pt', 'pt.order_id', '=', 'o.id')
            ->where('o.status', 'cancelled')->where('pt.payment_status', 'paid')->count();
        $this->countCheck($checks, 'paid_order_transaction_count', $paidCountMismatch, 'fail', 'Paid orders without exactly one paid transaction.');
        $this->countCheck($checks, 'paid_order_amount_mismatch', $paidAmountMismatch, 'fail', 'Paid transaction amount differs from its order total.');
        $this->countCheck($checks, 'cancelled_order_paid_transaction', $cancelledPaid, 'fail', 'Cancelled orders still contain paid transactions.');

        if (Schema::hasColumn('orders', 'checkout_token')) {
            $blankTokens = DB::table('orders')->whereNotNull('checkout_token')->whereRaw("TRIM(checkout_token) = ''")->count();
            $duplicateTokens = $this->groupCount(DB::table('orders')->select('checkout_token')
                ->whereNotNull('checkout_token')->groupBy('checkout_token')->havingRaw('COUNT(*) > 1'));
            $this->countCheck($checks, 'checkout_token_blank', $blankTokens, 'fail', 'Blank non-null checkout tokens.');
            $this->countCheck($checks, 'checkout_token_duplicate', $duplicateTokens, 'fail', 'Duplicate checkout tokens.');
        } else {
            $this->check($checks, 'checkout_token_column', 'warning', 1, 'Checkout token migration is pending.');
        }

        $this->eventChecks($checks, $length);
        $this->mysqlChecks($checks);

        return $this->result($checks);
    }

    /** @param array<int,array{id:string,severity:string,count:int,message:string}> $checks */
    private function eventChecks(array &$checks, string $length): void
    {
        if (! Schema::hasTable('payment_gateway_events')) {
            $this->check($checks, 'payment_gateway_events_table', 'warning', 1, 'Gateway event journal migration is pending.');

            return;
        }
        $invalidTypes = DB::table('payment_gateway_events')
            ->whereNotIn('event_type', array_map(fn (GatewayEventType $type): string => $type->value, GatewayEventType::cases()))->count();
        $badFingerprints = DB::table('payment_gateway_events')
            ->whereNull('event_fingerprint')->orWhereRaw($length.'(event_fingerprint) <> 64')->count();
        $orphanTransactions = DB::table('payment_gateway_events as e')->leftJoin('payment_transactions as pt', 'pt.id', '=', 'e.payment_transaction_id')
            ->whereNotNull('e.payment_transaction_id')->whereNull('pt.id')->count();
        $orphanOrders = DB::table('payment_gateway_events as e')->leftJoin('orders as o', 'o.id', '=', 'e.order_id')
            ->whereNotNull('e.order_id')->whereNull('o.id')->count();
        $metadataText = DB::connection()->getDriverName() === 'sqlite' ? 'CAST(metadata AS TEXT)' : 'CAST(metadata AS CHAR)';
        $sensitiveMetadata = DB::table('payment_gateway_events')->where(function ($query) use ($metadataText): void {
            foreach (['securehash', 'hash_secret', 'app_key', 'https://'] as $needle) {
                $query->orWhereRaw('LOWER('.$metadataText.') LIKE ?', ['%'.$needle.'%']);
            }
        })->count();
        $this->countCheck($checks, 'gateway_event_type_invalid', $invalidTypes, 'fail', 'Unknown gateway event types.');
        $this->countCheck($checks, 'gateway_event_fingerprint_invalid', $badFingerprints, 'fail', 'Malformed gateway event fingerprints.');
        $this->countCheck($checks, 'gateway_event_transaction_orphan', $orphanTransactions, 'fail', 'Gateway events reference missing transactions.');
        $this->countCheck($checks, 'gateway_event_order_orphan', $orphanOrders, 'fail', 'Gateway events reference missing orders.');
        $this->countCheck($checks, 'gateway_event_sensitive_metadata', $sensitiveMetadata, 'fail', 'Gateway metadata appears to contain prohibited secrets or URLs.');
    }

    /** @param array<int,array{id:string,severity:string,count:int,message:string}> $checks */
    private function mysqlChecks(array &$checks): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->check($checks, 'mysql_innodb_runtime', 'warning', 1, 'Current check is not running on MySQL/InnoDB.');

            return;
        }
        $version = (string) DB::connection()->getPdo()->getAttribute(\PDO::ATTR_SERVER_VERSION);
        preg_match('/^(\d+)\.(\d+)/', $version, $matches);
        $supported = isset($matches[1]) && (int) $matches[1] >= 8;
        $this->check($checks, 'mysql_server_version', $supported ? 'pass' : 'fail', $supported ? 0 : 1, 'MySQL 8.0 or newer is required.');
        $tables = ['orders', 'payment_transactions', 'refunds'];
        if (Schema::hasTable('payment_gateway_events')) {
            $tables[] = 'payment_gateway_events';
        }
        $nonInnoDb = DB::table('information_schema.TABLES')->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())
            ->whereIn('TABLE_NAME', $tables)->where('ENGINE', '<>', 'InnoDB')->count();
        $this->countCheck($checks, 'mysql_storage_engine', $nonInnoDb, 'fail', 'Payment tables not using InnoDB.');
        $timezone = DB::selectOne('SELECT @@session.time_zone AS session_timezone')?->session_timezone;
        $timezoneOkay = in_array($timezone, ['+00:00', 'UTC'], true);
        $this->check($checks, 'mysql_session_timezone', $timezoneOkay ? 'pass' : 'warning', $timezoneOkay ? 0 : 1,
            'Use an explicit UTC database session timezone; gateway dates are converted at protocol boundaries.');
    }

    private function groupCount($query): int
    {
        return (int) DB::query()->fromSub($query, 'preflight_groups')->count();
    }

    /** @param array<int,array{id:string,severity:string,count:int,message:string}> $checks */
    private function countCheck(array &$checks, string $id, int $count, string $failureSeverity, string $message): void
    {
        $this->check($checks, $id, $count > 0 ? $failureSeverity : 'pass', $count, $message);
    }

    /** @param array<int,array{id:string,severity:string,count:int,message:string}> $checks */
    private function check(array &$checks, string $id, string $severity, int $count, string $message): void
    {
        $checks[] = compact('id', 'severity', 'count', 'message');
    }

    /** @param array<int,array{id:string,severity:string,count:int,message:string}> $checks */
    private function result(array $checks): array
    {
        $summary = ['pass' => 0, 'warning' => 0, 'fail' => 0];
        foreach ($checks as $check) {
            $summary[$check['severity']]++;
        }
        $verdict = $summary['fail'] > 0 ? 'FAIL' : ($summary['warning'] > 0 ? 'WARNING' : 'PASS');

        return compact('verdict', 'summary', 'checks');
    }
}
