<?php

namespace App\Payments;

use DateTimeImmutable;

final readonly class QueryTransactionRequest
{
    public function __construct(
        public string $requestId,
        public string $merchantReference,
        public DateTimeImmutable $transactionDate,
        public DateTimeImmutable $createdAt,
        public string $serverIp,
    ) {}
}
