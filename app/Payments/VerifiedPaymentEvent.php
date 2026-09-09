<?php

namespace App\Payments;

use DateTimeImmutable;

final readonly class VerifiedPaymentEvent
{
    public const TYPE_RETURN = 'return';

    public const TYPE_IPN = 'ipn';

    public const TYPE_QUERY = 'query';

    // 01 is unfinished; 04 reversal and 07 suspected fraud are NOT proof of unpaid.
    public function provesUnpaid(): bool
    {
        return $this->eventType === self::TYPE_QUERY
            && $this->responseCode === '00' && $this->transactionStatus === '02' && ! $this->paid;
    }

    /**
     * @param  array<string, string>  $metadata
     */
    public function __construct(
        public string $eventType,
        public string $gateway,
        public string $merchantReference,
        public string $gatewayTransactionId,
        public string $amount,
        public string $currency,
        public string $responseCode,
        public string $transactionStatus,
        public bool $paid,
        public ?DateTimeImmutable $occurredAt,
        public array $metadata = [],
    ) {}
}
