<?php

namespace App\Payments;

final readonly class ReconciliationResult
{
    public function __construct(public PaymentEventOutcome $outcome, public ?VerifiedPaymentEvent $event = null) {}

    public function provesUnpaid(): bool
    {
        return in_array($this->outcome, [PaymentEventOutcome::Processed, PaymentEventOutcome::AlreadyProcessed], true)
            && $this->event?->provesUnpaid() === true;
    }
}
