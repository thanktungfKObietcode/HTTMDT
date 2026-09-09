<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Services\OrderLifecycleService;
use App\Services\PaymentService;
use App\Support\PaymentAttemptReference;
use App\Support\PaymentMethod;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class PhaseSixAPaymentGatewayFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_method_classification_keeps_vnpay_out_of_checkout_and_callbacks(): void
    {
        $service = app(PaymentService::class);

        $this->assertSame([PaymentMethod::COD], $service->checkoutPaymentMethods());
        $this->assertSame([PaymentMethod::SIMULATED_ONLINE], $service->callbackPaymentMethods());
        $this->assertContains(PaymentMethod::VNPAY, $service->supportedPaymentMethods());
        $this->assertNotContains(PaymentMethod::VNPAY, $service->checkoutPaymentMethods());
        $this->assertNotContains(PaymentMethod::VNPAY, $service->callbackPaymentMethods());
        $this->assertTrue(PaymentMethod::isCod(PaymentMethod::COD));
        $this->assertTrue(PaymentMethod::isOnline(PaymentMethod::SIMULATED_ONLINE));
        $this->assertTrue(PaymentMethod::isOnline(PaymentMethod::VNPAY));
        $this->assertFalse(PaymentMethod::isOnline(PaymentMethod::COD));
    }

    public function test_multiple_attempts_for_one_order_receive_distinct_merchant_references(): void
    {
        $order = $this->createPendingOrder();
        $service = app(PaymentService::class);

        $first = $service->createOrGetPendingTransaction($order);
        $first->update(['payment_status' => PaymentService::PAYMENT_FAILED]);
        $second = $service->createOrGetPendingTransaction($order->fresh());

        $this->assertNotSame($first->transaction_id, $second->transaction_id);
        $this->assertTrue(PaymentAttemptReference::isValid((string) $first->transaction_id));
        $this->assertTrue(PaymentAttemptReference::isValid((string) $second->transaction_id));
        $this->assertNotSame($order->order_number, $first->transaction_id);
        $this->assertSame('VND', $first->currency);
        $this->assertSame('VND', $second->currency);
        $this->assertSame(2, $order->paymentTransactions()->count());
    }

    public function test_gateway_transaction_identity_is_separate_from_merchant_reference(): void
    {
        $order = $this->createPendingOrder();
        $transaction = app(PaymentService::class)->createOrGetPendingTransaction($order);
        $merchantReference = $transaction->transaction_id;

        $transaction->update(['gateway_transaction_id' => '1456789012']);

        $this->assertSame($merchantReference, $transaction->refresh()->transaction_id);
        $this->assertSame('1456789012', $transaction->gateway_transaction_id);
    }

    public function test_schema_exposes_vnpay_identity_status_and_expiry_fields(): void
    {
        $this->assertTrue(Schema::hasColumns('payment_transactions', [
            'transaction_id',
            'gateway_transaction_id',
            'currency',
            'gateway_response_code',
            'gateway_transaction_status',
            'paid_at',
            'expires_at',
        ]));
        $this->assertTrue(Schema::hasColumn('orders', 'payment_expires_at'));
    }

    public function test_database_rejects_duplicate_merchant_attempt_reference(): void
    {
        $order = $this->createPendingOrder();
        $reference = PaymentAttemptReference::generate();
        $this->createTransaction($order, $reference);

        $this->expectException(QueryException::class);

        $this->createTransaction($order, $reference);
    }

    public function test_database_rejects_duplicate_gateway_transaction_identity_per_gateway(): void
    {
        $order = $this->createPendingOrder();
        $this->createTransaction($order, PaymentAttemptReference::generate(), '1456789012');

        $this->expectException(QueryException::class);

        $this->createTransaction($order, PaymentAttemptReference::generate(), '1456789012');
    }

    private function createPendingOrder(): Order
    {
        return Order::query()->create([
            'order_number' => 'ORD'.Str::upper(Str::random(16)),
            'status' => OrderLifecycleService::STATUS_PENDING,
            'payment_method' => PaymentMethod::COD,
            'payment_status' => PaymentService::PAYMENT_PENDING,
            'customer_name' => 'Phase Six Customer',
            'customer_phone' => '0900000000',
            'customer_email' => 'phase6@example.test',
            'shipping_address' => 'Test address',
            'subtotal' => '125000.00',
            'shipping_fee' => '0.00',
            'discount_amount' => '0.00',
            'total_amount' => '125000.00',
        ]);
    }

    private function createTransaction(
        Order $order,
        string $merchantReference,
        ?string $gatewayTransactionId = null
    ): PaymentTransaction {
        return PaymentTransaction::query()->create([
            'order_id' => $order->id,
            'gateway' => PaymentMethod::VNPAY,
            'transaction_id' => $merchantReference,
            'gateway_transaction_id' => $gatewayTransactionId,
            'payment_status' => PaymentService::PAYMENT_PENDING,
            'amount' => '125000.00',
            'currency' => 'VND',
        ]);
    }
}
