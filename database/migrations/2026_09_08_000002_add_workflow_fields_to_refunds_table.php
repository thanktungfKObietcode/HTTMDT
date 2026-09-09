<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $hasLegacyPending = DB::table('refunds')->where('status', 'pending')->exists();
        $hasUnknownStatus = DB::table('refunds')
            ->whereNotIn('status', ['pending', 'requested', 'approved', 'rejected', 'processing', 'completed', 'failed'])
            ->exists();

        if ($hasLegacyPending || $hasUnknownStatus) {
            throw new \RuntimeException(
                'Refund statuses must be reviewed before enabling the requested/approved/processing workflow.'
            );
        }

        Schema::table('refunds', function (Blueprint $table) {
            $table->string('status')->default('requested')->change();
            $table->foreignId('requested_by')->nullable()->after('payment_transaction_id')->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->after('requested_by')->constrained('users')->nullOnDelete();
            $table->foreignId('processed_by')->nullable()->after('reviewed_by')->constrained('users')->nullOnDelete();
            $table->text('admin_note')->nullable()->after('reason');
            $table->timestamp('reviewed_at')->nullable()->after('status');
            $table->timestamp('processing_at')->nullable()->after('reviewed_at');
            $table->timestamp('completed_at')->nullable()->after('processing_at');
            $table->timestamp('failed_at')->nullable()->after('completed_at');
            $table->index(['order_id', 'status'], 'refunds_order_status_index');
        });
    }

    public function down(): void
    {
        // InnoDB may replace its implicit FK index with our composite index.
        // Restore FK coverage BEFORE removing that composite during rollback.
        if (DB::getDriverName() === 'mysql') {
            $hasOrderIndex = collect(Schema::getIndexes('refunds'))->contains(
                fn (array $index): bool => $index['name'] !== 'refunds_order_status_index'
                    && ($index['columns'][0] ?? null) === 'order_id'
            );
            if (! $hasOrderIndex) {
                Schema::table('refunds', fn (Blueprint $table) => $table->index('order_id', 'refunds_order_id_foreign'));
            }
        }

        Schema::table('refunds', function (Blueprint $table) {
            $table->string('status')->default('pending')->change();
            $table->dropIndex('refunds_order_status_index');
            $table->dropConstrainedForeignId('processed_by');
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropConstrainedForeignId('requested_by');
            $table->dropColumn([
                'admin_note',
                'reviewed_at',
                'processing_at',
                'completed_at',
                'failed_at',
            ]);
        });
    }
};
