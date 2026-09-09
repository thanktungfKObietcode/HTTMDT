<?php

namespace App\Payments;

use DateTimeImmutable;

final readonly class PaymentUrlRequest
{
    public function __construct(
        public string $merchantReference,
        public string $amount,
        public string $currency,
        public string $orderInfo,
        public string $clientIp,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $expiresAt,
        public ?string $bankCode = null,
    ) {}
}
