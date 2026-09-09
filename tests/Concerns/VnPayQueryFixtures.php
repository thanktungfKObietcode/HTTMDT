<?php

namespace Tests\Concerns;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\User;
use App\Payments\VnPayGateway;
use App\Services\PaymentService;
use App\Support\VnPayAmount;
use Illuminate\Support\Str;

trait VnPayQueryFixtures
{
    private function configureVnPay(): void
    {
        config()->set([
            'vnpay.enabled' => true, 'vnpay.tmn_code' => 'TEST1234',
            'vnpay.hash_secret' => 'phase6c-fixture-only',
            'vnpay.payment_url' => 'https://sandbox.vnpayment.vn/paymentv2/vpcpay.html',
            'vnpay.return_url' => 'https://shop.example.test/thanh-toan/vnpay/return',
            'vnpay.ipn_url' => 'https://shop.example.test/thanh-toan/vnpay/ipn',
            'vnpay.query_url' => 'https://sandbox.vnpayment.vn/merchant_webapi/api/transaction',
            'vnpay.query_server_ip' => '127.0.0.1', 'vnpay.version' => '2.1.0',
            'vnpay.currency' => 'VND', 'vnpay.timezone' => 'Asia/Ho_Chi_Minh',
        ]);
    }

    /** Independent fixture: official QueryDR pipe order, including empty promotion slots. */
    private function queryResponse(string $reference, array $overrides = []): array
    {
        $response = array_replace([
            'vnp_ResponseId' => 'RESPONSE123', 'vnp_Command' => 'querydr',
            'vnp_ResponseCode' => '00', 'vnp_Message' => 'Success', 'vnp_TmnCode' => 'TEST1234',
            'vnp_TxnRef' => $reference, 'vnp_Amount' => '21000000', 'vnp_BankCode' => 'NCB',
            'vnp_PayDate' => '20260909091000', 'vnp_TransactionNo' => '1234567',
            'vnp_TransactionType' => '01', 'vnp_TransactionStatus' => '00',
            'vnp_OrderInfo' => 'Test query', 'vnp_PromotionCode' => '', 'vnp_PromotionAmount' => '',
        ], $overrides);
        $keys = ['vnp_ResponseId', 'vnp_Command', 'vnp_ResponseCode', 'vnp_Message', 'vnp_TmnCode',
            'vnp_TxnRef', 'vnp_Amount', 'vnp_BankCode', 'vnp_PayDate', 'vnp_TransactionNo',
            'vnp_TransactionType', 'vnp_TransactionStatus', 'vnp_OrderInfo', 'vnp_PromotionCode', 'vnp_PromotionAmount'];
        $response['vnp_SecureHash'] = hash_hmac('sha512', implode('|', array_map(
            fn ($key) => (string) ($response[$key] ?? ''), $keys)), 'phase6c-fixture-only');

        return array_filter($response, fn ($value) => $value !== null);
    }

    private function paymentContext(): array
    {
        $user = User::factory()->create(['is_active' => true]);
        $order = Order::create(['user_id' => $user->id, 'order_number' => 'SA-'.Str::random(16),
            'status' => 'pending', 'payment_method' => 'vnpay', 'payment_status' => 'pending',
            'customer_name' => 'Private Customer', 'customer_phone' => '0900000000',
            'shipping_address' => 'Private address', 'subtotal' => '210000.00', 'total_amount' => '210000.00']);
        $product = Product::create(['name' => 'Ring', 'slug' => Str::random(16), 'sku' => Str::random(16),
            'price' => '105000.00', 'stock' => 3, 'is_active' => true]);
        OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'product_name' => 'Ring',
            'quantity' => 2, 'unit_price' => '105000.00', 'total_price' => '210000.00']);
        $tx = app(PaymentService::class)->createOrGetPendingTransaction($order);

        return [$user, $order->fresh(), $tx->fresh(), $product];
    }

    private function ipnPayload(PaymentTransaction $tx, array $overrides = []): array
    {
        $fields = array_replace(['vnp_TmnCode' => 'TEST1234', 'vnp_TxnRef' => $tx->transaction_id,
            'vnp_Amount' => VnPayAmount::toGatewayAmount((string) $tx->amount), 'vnp_TransactionNo' => '1234567',
            'vnp_ResponseCode' => '00', 'vnp_TransactionStatus' => '00', 'vnp_PayDate' => '20260909091000'], $overrides);
        $fields['vnp_SecureHash'] = app(VnPayGateway::class)->sign($fields);

        return $fields;
    }
}
