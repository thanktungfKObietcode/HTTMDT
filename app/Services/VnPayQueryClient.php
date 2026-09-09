<?php

namespace App\Services;

use App\Contracts\PaymentGateway;
use App\Payments\QueryTransactionRequest;
use App\Payments\VerifiedPaymentEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use LogicException;
use RuntimeException;

final class VnPayQueryClient
{
    public function __construct(private readonly PaymentGateway $gateway) {}

    public function query(QueryTransactionRequest $request): VerifiedPaymentEvent
    {
        // Refuse even accidental nested invocation while a caller holds locks.
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('QueryDR must run outside database transactions.');
        }
        $url = (string) config('vnpay.query_url');
        if (filter_var($url, FILTER_VALIDATE_URL) === false
            || parse_url($url, PHP_URL_SCHEME) !== 'https'
            || parse_url($url, PHP_URL_HOST) !== 'sandbox.vnpayment.vn'
            || parse_url($url, PHP_URL_PATH) !== '/merchant_webapi/api/transaction'
            || parse_url($url, PHP_URL_QUERY) !== null || parse_url($url, PHP_URL_FRAGMENT) !== null
            || parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null
            || parse_url($url, PHP_URL_PORT) !== null) {
            throw new RuntimeException('VNPay QueryDR sandbox endpoint is not configured.');
        }
        $payload = $this->gateway->buildQueryRequest($request);
        $timeout = max(1, min(30, (int) config('vnpay.query_timeout_seconds', 10)));
        $response = Http::acceptJson()->asJson()->connectTimeout(3)->timeout($timeout)
            ->withoutRedirecting()->post($url, $payload);
        // Do not throw RequestException: it can include a raw response in logs.
        if (! $response->successful() || ! is_array($response->json())) {
            throw new RuntimeException('VNPay QueryDR transport failed.');
        }

        return $this->gateway->verifyQueryResponse($response->json(), $request);
    }
}
