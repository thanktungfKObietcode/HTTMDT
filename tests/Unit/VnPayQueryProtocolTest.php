<?php

namespace Tests\Unit;

use App\Payments\GatewayVerificationException;
use App\Payments\QueryTransactionRequest;
use App\Payments\VerifiedPaymentEvent;
use App\Payments\VnPayGateway;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\VnPayQueryFixtures;
use Tests\TestCase;

class VnPayQueryProtocolTest extends TestCase
{
    use VnPayQueryFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureVnPay();
    }

    private function input(): QueryTransactionRequest
    {
        return new QueryTransactionRequest('QUERY123', 'ATTEMPT123',
            new DateTimeImmutable('2026-09-09 02:00:00 UTC'),
            new DateTimeImmutable('2026-09-09 02:30:00 UTC'), '127.0.0.1');
    }

    public function test_query_signs_exact_official_pipe_order_without_application_key(): void
    {
        $adapter = app(VnPayGateway::class);
        $request = $adapter->buildQueryRequest($this->input());
        $plain = 'QUERY123|2.1.0|querydr|TEST1234|ATTEMPT123|20260909090000|20260909093000|127.0.0.1|Query ATTEMPT123';
        $this->assertSame(hash_hmac('sha512', $plain, 'phase6c-fixture-only'), $request['vnp_SecureHash']);
        config(['app.key' => 'unrelated-test-application-key']);
        $this->assertSame($request, $adapter->buildQueryRequest($this->input()));
        $this->assertArrayNotHasKey('vnp_Amount', $request);
        $this->assertArrayNotHasKey('vnp_TransactionNo', $request);
    }

    public function test_verified_query_is_normalized_using_signed_response_order(): void
    {
        $raw = $this->queryResponse('ATTEMPT123', ['vnp_TransactionNo' => '0001234567']);
        $event = app(VnPayGateway::class)->verifyQueryResponse(array_reverse($raw, true), $this->input());
        $this->assertSame(VerifiedPaymentEvent::TYPE_QUERY, $event->eventType);
        $this->assertSame('210000.00', $event->amount);
        $this->assertSame('1234567', $event->gatewayTransactionId);
        $this->assertTrue($event->paid);
        $this->assertFalse($event->provesUnpaid());
        $this->assertSame('2026-09-09T09:10:00+07:00', $event->occurredAt->format(DATE_ATOM));
        $this->assertArrayNotHasKey('vnp_SecureHash', $event->metadata);
    }

    #[DataProvider('invalidFields')]
    public function test_signed_but_invalid_protocol_is_rejected(array $overrides): void
    {
        $raw = $this->queryResponse('ATTEMPT123', $overrides);
        $this->expectException(\RuntimeException::class);
        app(VnPayGateway::class)->verifyQueryResponse($raw, $this->input());
    }

    public static function invalidFields(): array
    {
        return [
            [['vnp_TmnCode' => 'EVIL1234']], [['vnp_TxnRef' => 'UNKNOWN']],
            [['vnp_Command' => 'refund']], [['vnp_Version' => '2.0.0']], [['vnp_CurrCode' => 'USD']],
            [['vnp_TransactionType' => '02']], [['vnp_TransactionNo' => null]], [['vnp_TransactionNo' => '0']],
            [['vnp_ResponseId' => null]], [['vnp_ResponseCode' => '91']], [['vnp_ResponseCode' => '94']],
            [['vnp_PayDate' => '20260231010000']], [['vnp_Amount' => '21000000.0']],
            [['vnp_Amount' => '0']], [['vnp_Amount' => '-100']],
        ];
    }

    public function test_tampered_query_response_is_not_verified(): void
    {
        $raw = $this->queryResponse('ATTEMPT123');
        $raw['vnp_Amount'] = '10000';
        $this->expectException(GatewayVerificationException::class);
        app(VnPayGateway::class)->verifyQueryResponse($raw, $this->input());
    }

    #[DataProvider('gatewayStates')]
    public function test_only_clear_failure_proves_unpaid(string $status, bool $unpaid): void
    {
        $event = app(VnPayGateway::class)->verifyQueryResponse(
            $this->queryResponse('ATTEMPT123', ['vnp_TransactionStatus' => $status]), $this->input());
        $this->assertSame($unpaid, $event->provesUnpaid());
    }

    public static function gatewayStates(): array
    {
        return [['00', false], ['01', false], ['02', true], ['04', false], ['05', false], ['06', false], ['07', false], ['09', false]];
    }
}
