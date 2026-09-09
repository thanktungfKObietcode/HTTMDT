<?php

namespace App\Contracts;

use App\Payments\PaymentUrlRequest;
use App\Payments\QueryTransactionRequest;
use App\Payments\VerifiedPaymentEvent;

interface PaymentGateway
{
    public function gatewayName(): string;

    public function assertConfigured(): void;

    public function buildPaymentUrl(PaymentUrlRequest $request): string;

    /** @param array<string, mixed> $parameters */
    public function verifyReturn(array $parameters): VerifiedPaymentEvent;

    /** @param array<string, mixed> $parameters */
    public function verifyIpn(array $parameters): VerifiedPaymentEvent;

    public function buildQueryRequest(QueryTransactionRequest $request): array;

    public function verifyQueryResponse(array $parameters, QueryTransactionRequest $request): VerifiedPaymentEvent;
}
