<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_gateway_events', function (Blueprint $table): void {
            $table->id();
            // Preserve audit history: no cascading deletion of journal entries.
            $table->foreignId('payment_transaction_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('gateway', 30);
            $table->string('event_type', 40);
            $table->string('merchant_reference', 100)->nullable()->index();
            $table->string('gateway_transaction_id', 100)->nullable();
            // Not unique: each delivery is evidence, including genuine replays.
            $table->char('event_fingerprint', 64)->index();
            $table->boolean('signature_valid');
            $table->string('response_code', 20)->nullable();
            $table->string('transaction_status', 20)->nullable();
            $table->boolean('reconciliation_required')->default(false)->index();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_gateway_events');
    }
};
