<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $lengthFunction = DB::connection()->getDriverName() === 'sqlite' ? 'LENGTH' : 'CHAR_LENGTH';
        $hasMissingReference = DB::table('payment_transactions')
            ->where(function ($query): void {
                $query->whereNull('transaction_id')
                    ->orWhereRaw("TRIM(transaction_id) = ''");
            })
            ->exists();

        $hasOversizedReference = DB::table('payment_transactions')
            ->whereRaw($lengthFunction.'(transaction_id) > 100')
            ->exists();

        $hasDuplicateReference = DB::table('payment_transactions')
            ->selectRaw('LOWER(TRIM(transaction_id)) AS normalized_reference')
            ->whereNotNull('transaction_id')
            ->whereRaw("TRIM(transaction_id) <> ''")
            ->groupByRaw('LOWER(TRIM(transaction_id))')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasMissingReference || $hasOversizedReference || $hasDuplicateReference) {
            throw new RuntimeException(
                'Cannot enforce payment-attempt identity until missing, oversized, or duplicate transaction IDs are reconciled.'
            );
        }

        Schema::table('payment_transactions', function (Blueprint $table): void {
            $table->string('transaction_id', 100)->nullable(false)->change();
        });

        Schema::table('payment_transactions', function (Blueprint $table): void {
            $table->string('gateway_transaction_id', 100)->nullable()->after('transaction_id');
            $table->char('currency', 3)->default('VND')->after('amount');
            $table->string('gateway_response_code', 20)->nullable()->after('currency');
            $table->string('gateway_transaction_status', 20)->nullable()->after('gateway_response_code');
            $table->timestamp('paid_at')->nullable()->after('gateway_transaction_status');
            $table->timestamp('expires_at')->nullable()->after('paid_at');

            $table->unique('transaction_id', 'payment_transactions_attempt_reference_unique');
            $table->unique(
                ['gateway', 'gateway_transaction_id'],
                'payment_transactions_gateway_transaction_unique'
            );
            $table->index(
                ['gateway', 'payment_status', 'expires_at'],
                'payment_transactions_reconciliation_index'
            );
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->timestamp('payment_expires_at')->nullable()->after('paid_at');
            $table->index('payment_expires_at', 'orders_payment_expires_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_payment_expires_at_index');
            $table->dropColumn('payment_expires_at');
        });

        Schema::table('payment_transactions', function (Blueprint $table): void {
            $table->dropIndex('payment_transactions_reconciliation_index');
            $table->dropUnique('payment_transactions_gateway_transaction_unique');
            $table->dropUnique('payment_transactions_attempt_reference_unique');
            $table->dropColumn([
                'gateway_transaction_id',
                'currency',
                'gateway_response_code',
                'gateway_transaction_status',
                'paid_at',
                'expires_at',
            ]);
        });

        Schema::table('payment_transactions', function (Blueprint $table): void {
            $table->string('transaction_id')->nullable()->change();
        });
    }
};
