<?php

namespace App\Payments;

enum GatewayEventType: string
{
    case PaymentInitiated = 'payment_initiated';
    case ReturnReceived = 'return_received';
    case IpnReceived = 'ipn_received';
    case IpnSettled = 'ipn_settled';
    case IpnDuplicate = 'ipn_duplicate';
    case IpnConflict = 'ipn_conflict';
    case QueryRequested = 'query_requested';
    case QueryResponse = 'query_response';
    case QuerySettled = 'query_settled';
    case QueryConflict = 'query_conflict';
    case ExpiryChecked = 'expiry_checked';
    case ExpiryCancelled = 'expiry_cancelled';
    case ExpirySkipped = 'expiry_skipped';
    case ReconciliationRequired = 'reconciliation_required';
    case RefundRequested = 'refund_requested';
    case RefundSettled = 'refund_settled';
    case RefundInconclusive = 'refund_inconclusive';
}
