<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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
