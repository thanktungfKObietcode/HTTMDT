<?php

namespace App\Payments;

use RuntimeException;

final class GatewayVerificationException extends RuntimeException
{
    public function __construct(public readonly PaymentEventOutcome $outcome)
    {
        parent::__construct('Gateway response could not be verified.');
    }
}
