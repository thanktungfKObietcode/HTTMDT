<?php

namespace App\Payments;

use App\Support\Money;
use App\Support\PaymentMethod;
use DateTimeImmutable;
use RuntimeException;

/** Sandbox-only protocol adapter. Never log a request, response, or signature. */
final class MoMoGateway
{
    private const BASE = 'https://test-payment.momo.vn';

    public function assertConfigured(): void
    {
        if (config('momo.enabled') !== true
            || preg_match('/^[A-Za-z0-9_-]{1,50}$/D', (string) config('momo.partner_code')) !== 1
            || trim((string) config('momo.access_key')) === ''
            || trim((string) config('momo.secret_key')) === ''
            || config('momo.request_type') !== 'captureWallet'
            || ! in_array(config('momo.lang'), ['vi', 'en'], true)
            || (int) config('momo.timeout_seconds') < 30
            || (int) config('momo.timeout_seconds') > 45) {
            throw new RuntimeException('MoMo Sandbox configuration is incomplete.');
        }
        foreach (['create' => '/v2/gateway/api/create', 'query' => '/v2/gateway/api/query',
            'refund' => '/v2/gateway/api/refund', 'refund_query' => '/v2/gateway/api/refund/query'] as $name => $path) {
            if (config('momo.'.$name.'_url') !== self::BASE.$path) {
                throw new RuntimeException('MoMo Sandbox endpoint is invalid.');
            }
        }
        $base = rtrim((string) config('app.url'), '/');
        foreach (['redirect' => '/thanh-toan/momo/return', 'ipn' => '/thanh-toan/momo/ipn'] as $name => $path) {
            if (config('momo.'.$name.'_url') !== $base.$path || ! $this->httpsUrl($base)) {
                throw new RuntimeException('MoMo callback URL must match the public HTTPS application URL.');
            }
        }
    }

    public function amount(string $decimal): int
    {
        $minor = Money::toMinorUnits($decimal);
        if ($minor % 100 !== 0) {
            throw new RuntimeException('MoMo requires a whole-VND amount.');
        }
        $amount = intdiv($minor, 100);
        if ($amount < 1000 || $amount > 50000000) {
            throw new RuntimeException('MoMo Sandbox amount is outside the supported wallet range.');
        }
        return $amount;
    }

    /** @return array<string, int|string> */
    public function createPayload(string $orderId, string $requestId, string $amount): array
    {
        $this->assertConfigured();
        $fields = [
            'partnerCode' => (string) config('momo.partner_code'), 'requestId' => $requestId,
            'amount' => $this->amount($amount), 'orderId' => $orderId,
            'orderInfo' => 'Silver Atelier '.$orderId,
            'redirectUrl' => (string) config('momo.redirect_url'),
            'ipnUrl' => (string) config('momo.ipn_url'),
            'requestType' => 'captureWallet', 'extraData' => '', 'lang' => (string) config('momo.lang'),
        ];
        $fields['signature'] = $this->sign($this->raw($fields, [
            'accessKey', 'amount', 'extraData', 'ipnUrl', 'orderId', 'orderInfo',
            'partnerCode', 'redirectUrl', 'requestId', 'requestType',
        ]));
        return $fields;
    }

    /** @return array<string, string> */
    public function queryPayload(string $orderId, string $requestId): array
    {
        $this->assertConfigured();
        $fields = ['partnerCode' => (string) config('momo.partner_code'), 'requestId' => $requestId,
            'orderId' => $orderId, 'lang' => (string) config('momo.lang')];
        $fields['signature'] = $this->sign($this->raw($fields, ['accessKey', 'orderId', 'partnerCode', 'requestId']));
        return $fields;
    }

    /** @return array<string, int|string> */
    public function refundPayload(string $refundId, string $requestId, string $amount, string $transId): array
    {
        $this->assertConfigured();
        if (preg_match('/^[1-9][0-9]{0,18}$/D', $transId) !== 1) {
            throw new RuntimeException('MoMo original transaction identity is invalid.');
        }
        $fields = ['partnerCode' => (string) config('momo.partner_code'), 'orderId' => $refundId,
            'requestId' => $requestId, 'amount' => $this->amount($amount), 'transId' => (int) $transId,
            'lang' => (string) config('momo.lang'), 'description' => 'Silver Atelier refund'];
        $fields['signature'] = $this->sign($this->raw($fields, [
            'accessKey', 'amount', 'description', 'orderId', 'partnerCode', 'requestId', 'transId',
        ]));
        return $fields;
    }

    /** @param array<string, mixed> $response */
    public function verifiedPayUrl(array $response, string $orderId, string $requestId, int $amount): string
    {
        $this->assertConfigured();
        $this->assertIdentity($response, $orderId, $requestId, $amount);
        if (($response['resultCode'] ?? null) !== 0 || ! is_string($response['payUrl'] ?? null)
            || ! $this->payUrlAllowed($response['payUrl'])) {
            throw new RuntimeException('MoMo create response is not an approved payment redirect.');
        }
        $raw = $this->raw($response, [
            'accessKey', 'amount', 'message', 'orderId', 'partnerCode', 'payUrl',
            'requestId', 'responseTime', 'resultCode',
        ]);
        if (! $this->validSignature($raw, $response['signature'] ?? null)) {
            throw new RuntimeException('MoMo create response signature is invalid.');
        }
        return $response['payUrl'];
    }

    /** @param array<string, mixed> $values */
    public function verifyResult(array $values, string $type): VerifiedPaymentEvent
    {
        $this->assertConfigured();
        $raw = $this->raw($values, [
            'accessKey', 'amount', 'extraData', 'message', 'orderId', 'orderInfo',
            'orderType', 'partnerCode', 'payType', 'requestId', 'responseTime',
            'resultCode', 'transId',
        ]);
        if (! $this->validSignature($raw, $values['signature'] ?? null)
            || preg_match('/^[A-Za-z0-9_-]{1,100}$/D', (string) ($values['orderId'] ?? '')) !== 1
            || preg_match('/^[A-Za-z0-9_-]{1,50}$/D', (string) ($values['requestId'] ?? '')) !== 1
            || ! $this->numeric($values['amount'] ?? null)
            || ! $this->numeric($values['resultCode'] ?? null)
            || ! $this->numeric($values['transId'] ?? null)
            || ! $this->numeric($values['responseTime'] ?? null)
            || (string) $values['partnerCode'] !== (string) config('momo.partner_code')
            || ($values['orderType'] ?? null) !== 'momo_wallet'
            || ($values['extraData'] ?? null) !== ''
            || ($values['orderInfo'] ?? null) !== 'Silver Atelier '.($values['orderId'] ?? '')) {
            throw new RuntimeException('MoMo result authentication failed.');
        }
        $paid = (int) $values['resultCode'] === 0;
        if ($paid && (int) $values['transId'] <= 0) {
            throw new RuntimeException('MoMo paid transaction identity is invalid.');
        }
        return new VerifiedPaymentEvent(
            $type, PaymentMethod::MOMO, (string) $values['orderId'], (string) $values['transId'],
            Money::fromMinorUnits((int) $values['amount'] * 100), 'VND',
            (string) $values['resultCode'], (string) $values['resultCode'], $paid,
            (new DateTimeImmutable('@'.intdiv((int) $values['responseTime'], 1000))),
            ['request_id' => (string) $values['requestId']],
        );
    }

    /** @param array<string, mixed> $values */
    public function assertIdentity(array $values, string $orderId, string $requestId, int $amount): void
    {
        if (($values['partnerCode'] ?? null) !== config('momo.partner_code')
            || ($values['orderId'] ?? null) !== $orderId || ($values['requestId'] ?? null) !== $requestId
            || ! $this->numeric($values['amount'] ?? null) || (int) $values['amount'] !== $amount) {
            throw new RuntimeException('MoMo response does not match the payment attempt.');
        }
    }

    /** Query replies are authenticated by TLS to the pinned sandbox endpoint; MoMo does not document a response signature. */
    public function queryEvent(array $values, string $orderId, string $requestId, int $amount): ?VerifiedPaymentEvent
    {
        $this->assertIdentity($values, $orderId, $requestId, $amount);
        if (! $this->numeric($values['resultCode'] ?? null)
            || ! $this->numeric($values['transId'] ?? null)
            || ! $this->numeric($values['responseTime'] ?? null)) {
            throw new RuntimeException('MoMo query response is malformed.');
        }
        if ((int) $values['resultCode'] !== 0) {
            return null;
        }
        if ((int) $values['transId'] <= 0) {
            throw new RuntimeException('MoMo successful query has no transaction identity.');
        }
        return new VerifiedPaymentEvent(
            VerifiedPaymentEvent::TYPE_QUERY, PaymentMethod::MOMO, $orderId,
            (string) $values['transId'], Money::fromMinorUnits($amount * 100), 'VND',
            '0', '0', true, new DateTimeImmutable('@'.intdiv((int) $values['responseTime'], 1000)),
            ['request_id' => $requestId],
        );
    }

    /** @param array<string, mixed> $values */
    public function refundResponseSucceeded(array $values, string $orderId, string $requestId, int $amount): bool
    {
        $this->assertIdentity($values, $orderId, $requestId, $amount);
        if (! $this->numeric($values['resultCode'] ?? null) || ! $this->numeric($values['transId'] ?? null)) {
            throw new RuntimeException('MoMo refund response is malformed.');
        }
        return (int) $values['resultCode'] === 0 && (int) $values['transId'] > 0;
    }

    /** @param array<string, mixed> $values */
    public function refundQuerySucceeded(array $values, string $refundOrderId, string $queryRequestId, int $amount): bool
    {
        if (($values['partnerCode'] ?? null) !== config('momo.partner_code')
            || ($values['requestId'] ?? null) !== $queryRequestId
            || ! $this->numeric($values['resultCode'] ?? null)
            || ! is_array($values['refundTrans'] ?? null)) {
            throw new RuntimeException('MoMo refund query response is malformed.');
        }
        foreach ($values['refundTrans'] as $item) {
            if (is_array($item) && ($item['orderId'] ?? null) === $refundOrderId) {
                if (! $this->numeric($item['amount'] ?? null) || (int) $item['amount'] !== $amount
                    || ! $this->numeric($item['resultCode'] ?? null)
                    || ! $this->numeric($item['transId'] ?? null)) {
                    throw new RuntimeException('MoMo refund query identity mismatch.');
                }
                return (int) $values['resultCode'] === 0 && (int) $item['resultCode'] === 0
                    && (int) $item['transId'] > 0;
            }
        }
        return false;
    }

    public function payUrlAllowed(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && parse_url($url, PHP_URL_SCHEME) === 'https'
            && parse_url($url, PHP_URL_HOST) === 'test-payment.momo.vn'
            && parse_url($url, PHP_URL_PATH) === '/v2/gateway/pay'
            && parse_url($url, PHP_URL_PORT) === null && parse_url($url, PHP_URL_USER) === null
            && parse_url($url, PHP_URL_PASS) === null && parse_url($url, PHP_URL_FRAGMENT) === null;
    }

    /** @param array<string, mixed> $values @param array<int, string> $keys */
    private function raw(array $values, array $keys): string
    {
        $parts = [];
        foreach ($keys as $key) {
            $value = $key === 'accessKey' ? config('momo.access_key') : ($values[$key] ?? null);
            if (! is_string($value) && ! is_int($value)) {
                throw new RuntimeException('MoMo signed field is missing or invalid.');
            }
            $parts[] = $key.'='.$value;
        }
        return implode('&', $parts);
    }

    private function sign(string $raw): string
    {
        return hash_hmac('sha256', $raw, (string) config('momo.secret_key'));
    }

    private function validSignature(string $raw, mixed $signature): bool
    {
        return is_string($signature) && preg_match('/^[a-fA-F0-9]{64}$/D', $signature) === 1
            && hash_equals($this->sign($raw), strtolower($signature));
    }

    private function numeric(mixed $value): bool
    {
        return (is_int($value) || is_string($value)) && preg_match('/^\d+$/D', (string) $value) === 1;
    }

    private function httpsUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        return filter_var($url, FILTER_VALIDATE_URL) !== false && parse_url($url, PHP_URL_SCHEME) === 'https'
            && is_string($host) && ! in_array(strtolower($host), ['localhost', '127.0.0.1', '::1'], true)
            && parse_url($url, PHP_URL_USER) === null && parse_url($url, PHP_URL_PASS) === null
            && parse_url($url, PHP_URL_QUERY) === null && parse_url($url, PHP_URL_FRAGMENT) === null;
    }
}
