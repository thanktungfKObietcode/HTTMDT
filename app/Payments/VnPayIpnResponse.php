<?php

namespace App\Payments;

final class VnPayIpnResponse
{
    /** @return array{RspCode: string, Message: string} */
    public static function body(PaymentEventOutcome $outcome): array
    {
        [$code, $message] = match ($outcome) {
            PaymentEventOutcome::Processed => ['00', 'Confirm Success'],
            PaymentEventOutcome::AlreadyProcessed => ['02', 'Order already confirmed'],
            PaymentEventOutcome::UnknownReference => ['01', 'Order not found'],
            PaymentEventOutcome::AmountMismatch => ['04', 'Invalid amount'],
            PaymentEventOutcome::InvalidSignature => ['97', 'Invalid signature'],
            PaymentEventOutcome::ReconciliationRequired => ['99', 'Reconciliation required'],
            default => ['99', 'Unknown error'],
        };

        return ['RspCode' => $code, 'Message' => $message];
    }
}
