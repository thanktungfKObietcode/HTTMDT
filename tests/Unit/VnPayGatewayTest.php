<?php

namespace Tests\Unit;

use App\Contracts\PaymentGateway;
use App\Payments\PaymentUrlRequest;
use App\Payments\VerifiedPaymentEvent;
use App\Payments\VnPayGateway;
use App\Support\PaymentAttemptReference;
use DateTimeImmutable;
use RuntimeException;
use Tests\TestCase;

class VnPayGatewayTest extends TestCase
{
    private const TEST_SECRET = 'phase-6a-test-secret-only';

    public function test_vnpay_is_disabled_by_default(): void
    {
        $this->assertFalse((bool) config('vnpay.enabled'));
        $this->assertInstanceOf(VnPayGateway::class, app(PaymentGateway::class));
    }

    public function test_canonicalization_sorts_encodes_and_excludes_hash_fields(): void
    {
        $gateway = app(VnPayGateway::class);
        $parameters = [
            'vnp_TxnRef' => '01JTESTREFERENCE00000000000',
            'vnp_OrderInfo' => 'Thanh toán đơn A B',
            'vnp_Amount' => '12500000',
            'vnp_SecureHash' => str_repeat('a', 128),
            'vnp_SecureHashType' => 'SHA512',
            'vnp_Empty' => '',
            'unrelated' => 'ignored',
        ];

        $this->assertSame(
            'vnp_Amount=12500000&vnp_OrderInfo='.urlencode('Thanh toán đơn A B').'&vnp_TxnRef=01JTESTREFERENCE00000000000',
            $gateway->canonicalize($parameters)
        );
    }

    public function test_signing_uses_hmac_sha512_and_not_application_key(): void
    {
        $gateway = $this->configuredGateway();
        $parameters = [
            'vnp_TmnCode' => 'TEST1234',
            'vnp_Amount' => '10000',
            'vnp_TxnRef' => '01JTESTREFERENCE00000000000',
        ];
        $canonical = $gateway->canonicalize($parameters);
        $expected = hash_hmac('sha512', $canonical, self::TEST_SECRET);

        config()->set('app.key', 'first-unrelated-application-key');
        $first = $gateway->sign($parameters);
        config()->set('app.key', 'second-unrelated-application-key');
        $second = $gateway->sign($parameters);

        $this->assertSame($expected, $first);
        $this->assertSame($first, $second);
        $this->assertSame(128, strlen($first));

        $parameters['vnp_Amount'] = '10100';
        $this->assertNotSame($first, $gateway->sign($parameters));
    }

    public function test_build_payment_url_uses_vnpay_amount_timezone_and_verified_signature(): void
    {
        $gateway = $this->configuredGateway();
        $request = new PaymentUrlRequest(
            merchantReference: PaymentAttemptReference::generate(),
            amount: '125000.00',
            currency: 'VND',
            orderInfo: 'Thanh toan don hang ORD100',
            clientIp: '127.0.0.1',
            createdAt: new DateTimeImmutable('2026-09-09 02:00:00 UTC'),
            expiresAt: new DateTimeImmutable('2026-09-09 02:15:00 UTC'),
            bankCode: 'NCB',
        );

        $url = $gateway->buildPaymentUrl($request);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $parameters);

        $this->assertStringStartsWith('https://sandbox.vnpayment.vn/paymentv2/vpcpay.html?', $url);
        $this->assertSame('2.1.0', $parameters['vnp_Version']);
        $this->assertSame('pay', $parameters['vnp_Command']);
        $this->assertSame('12500000', $parameters['vnp_Amount']);
        $this->assertSame('20260909090000', $parameters['vnp_CreateDate']);
        $this->assertSame('20260909091500', $parameters['vnp_ExpireDate']);
        $this->assertSame('NCB', $parameters['vnp_BankCode']);
        $this->assertArrayNotHasKey('vnp_SecureHashType', $parameters);
        $this->assertTrue($gateway->verifySignature($parameters));
    }

    public function test_verified_ipn_maps_only_verified_normalized_data(): void
    {
        $gateway = $this->configuredGateway();
        $parameters = $this->signedResponse($gateway);

        $event = $gateway->verifyIpn($parameters);

        $this->assertSame(VerifiedPaymentEvent::TYPE_IPN, $event->eventType);
        $this->assertSame('vnpay', $event->gateway);
        $this->assertSame($parameters['vnp_TxnRef'], $event->merchantReference);
        $this->assertSame('14567890', $event->gatewayTransactionId);
        $this->assertSame('125000.00', $event->amount);
        $this->assertSame('VND', $event->currency);
        $this->assertSame('00', $event->responseCode);
        $this->assertSame('00', $event->transactionStatus);
        $this->assertTrue($event->paid);
        $this->assertSame('20260909091500', $event->occurredAt?->format('YmdHis'));
        $this->assertSame([
            'bank_code' => 'NCB',
            'pay_date' => '20260909091500',
            'order_info' => 'Thanh toan don hang',
        ], $event->metadata);
        $this->assertArrayNotHasKey('vnp_SecureHash', $event->metadata);
    }

    public function test_invalid_signature_is_rejected(): void
    {
        $gateway = $this->configuredGateway();
        $parameters = $this->signedResponse($gateway);
        $parameters['vnp_Amount'] = '12600000';

        $this->assertFalse($gateway->verifySignature($parameters));
        $this->expectException(RuntimeException::class);

        $gateway->verifyReturn($parameters);
    }

    public function test_signed_response_with_wrong_terminal_is_rejected(): void
    {
        $gateway = $this->configuredGateway();
        $parameters = $this->signedResponse($gateway, ['vnp_TmnCode' => 'OTHER123']);

        $this->expectException(RuntimeException::class);

        $gateway->verifyIpn($parameters);
    }

    public function test_signed_response_with_wrong_version_is_rejected(): void
    {
        $gateway = $this->configuredGateway();
        $parameters = $this->signedResponse($gateway, ['vnp_Version' => '2.0.1']);

        $this->expectException(RuntimeException::class);

        $gateway->verifyIpn($parameters);
    }

    public function test_missing_gateway_configuration_fails_closed(): void
    {
        config()->set('vnpay.enabled', true);
        config()->set('vnpay.hash_secret', null);

        $this->expectException(RuntimeException::class);

        app(VnPayGateway::class)->sign(['vnp_Amount' => '10000']);
    }

    private function configuredGateway(): VnPayGateway
    {
        config()->set([
            'vnpay.enabled' => true,
            'vnpay.tmn_code' => 'TEST1234',
            'vnpay.hash_secret' => self::TEST_SECRET,
            'vnpay.payment_url' => 'https://sandbox.vnpayment.vn/paymentv2/vpcpay.html',
            'vnpay.return_url' => 'https://shop.example.test/payment/vnpay/return',
            'vnpay.ipn_url' => 'https://shop.example.test/payment/vnpay/ipn',
            'vnpay.version' => '2.1.0',
            'vnpay.locale' => 'vn',
            'vnpay.currency' => 'VND',
            'vnpay.order_type' => 'other',
            'vnpay.timezone' => 'Asia/Ho_Chi_Minh',
        ]);

        return app(VnPayGateway::class);
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function signedResponse(VnPayGateway $gateway, array $overrides = []): array
    {
        $parameters = array_merge([
            'vnp_Version' => '2.1.0',
            'vnp_TmnCode' => 'TEST1234',
            'vnp_Amount' => '12500000',
            'vnp_TxnRef' => PaymentAttemptReference::generate(),
            'vnp_TransactionNo' => '14567890',
            'vnp_ResponseCode' => '00',
            'vnp_TransactionStatus' => '00',
            'vnp_BankCode' => 'NCB',
            'vnp_PayDate' => '20260909091500',
            'vnp_OrderInfo' => 'Thanh toan don hang',
        ], $overrides);
        $parameters['vnp_SecureHash'] = $gateway->sign($parameters);

        return $parameters;
    }
}
