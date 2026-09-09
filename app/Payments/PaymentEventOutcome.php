<?php

namespace App\Payments;

enum PaymentEventOutcome: string
{
    case Processed = 'processed';
    case AlreadyProcessed = 'already_processed';
    case UnknownReference = 'unknown_reference';
    case AmountMismatch = 'amount_mismatch';
    case InvalidSignature = 'invalid_signature';
    case InvalidEvent = 'invalid_event';
    case ReconciliationRequired = 'reconciliation_required';
    case TemporaryFailure = 'temporary_failure';
}
