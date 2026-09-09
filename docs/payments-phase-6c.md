# VNPay Phase 6C operations (sandbox only)

## Configuration / deployment prerequisites

Keep VNPay disabled until reviewed deployment has applied the pending Phase 6A identity/expiry migration and `2026_09_09_000002_create_payment_gateway_events_table.php`. This implementation does not run migrations or edit `.env`.

Additional environment-backed configuration keys:

- `VNPAY_QUERY_URL`: explicit sandbox QueryDR endpoint; no production inference or fallback.
- `VNPAY_QUERY_SERVER_IP`: merchant server IP, not a customer/admin-submitted address.

The HTTP client accepts only HTTPS `sandbox.vnpayment.vn` at `/merchant_webapi/api/transaction`, rejects redirects, connects within 3 seconds, and uses a bounded request timeout (default 10 seconds). No automatic HTTP retries. No HTTP while a DB transaction is open.

Protocol reference: [official VNPay QueryDR contract](https://sandbox.vnpayment.vn/apis/docs/truy-van-hoan-tien/querydr%26refund.html), checked 2026-09-09. Query signing uses the documented pipe-ordered values, not the payment-URL encoder. API response `00` is query success; payment success additionally requires transaction status `00`. Only verified transaction status `02` is treated as unpaid for expiry. Unfinished (`01`), reversal (`04`), suspected fraud (`07`), refund states, not-found (`91`), malformed responses and transport failures never authorize automatic cancellation.

## Manual reconciliation

An active admin opens the order, chooses a persisted VNPay attempt and submits **Đối soát VNPay**. The POST route requires `auth`, `active`, `backoffice`, `admin`, `permission:orders.update` and CSRF. It never accepts a manual amount/reference/URL or a “mark paid” instruction.

Verified QueryDR and IPN share PaymentService's settlement transaction. Lock order: Order, then all payment attempts ordered by ID. Return remains financially read-only. VNPay refunds remain explicitly blocked.

## Expiry command

Start with a read-only candidate scan after review:

```shell
php artisan payments:expire-vnpay --dry-run --limit=25
```

Actual expiry queries VNPay, so run it only after operational approval/configuration:

```shell
php artisan payments:expire-vnpay --limit=25 --after-id=0
```

The command reports scanned/cancelled/skipped_paid/skipped_conflict/failed and last_id. Advance `--after-id` between bounded batches so unresolved early orders do not starve later orders; restart at zero on a later sweep. Defaults: 25 orders, maximum 100; maximum 20 attempts per order (bounded configurable cap). There is no scheduler registration because the repository has no existing operational scheduler pattern. It is a repeatable expiry worker command, not an activated production schedule.

Every attempt, including old/failed/superseded attempts, is queried before final cancellation. Missing attempt/expiry, new attempt during the query, unknown/ambiguous state or unresolved conflict means skip. Only current verified-unpaid proofs for **all** attempts permit lifecycle cancellation under Order/payment locks. The existing lifecycle alone restores stock. Cancellation/history/stock/expiry-outcome journal commit together. Late paid events after cancellation are retained as reconciliation conflicts, never reopening the order.

## Journal and reconciliation flags

`payment_gateway_events` is an append-only application journal, not event sourcing. Each receipt is preserved, including duplicates. SHA-256 fingerprints correlate normalized safe fields; they are indexed, not unique and are never authentication proof. No hashes, signed URLs, raw HTTP bodies, credentials or customer PII are journaled. `signature_valid=false` also represents events where verification does not apply, such as initiation or expiry checks.

Initiation evidence must persist before a URL is returned. Settlement and expiry outcome rows are mandatory in the financial transaction; audit insert failure rolls back that mutation. Return/IPN receipt observations are best-effort, with sanitized warning logs on failure. A verified query response persists before settlement, preserving evidence if settlement fails.

Existing payload receipts are retained. `payload.vnpay_reconciliation_required` remains the operational flag, avoiding a redundant schema column. Only a verified, consistent QueryDR can clear transient `query_uncertain`. Financial conflicts (including legacy flags without a reason) remain sticky: no manual-clear action is provided. Review amount/identity conflicts, terminal-order collections and second collections operationally; do not force local paid/cancel/refund states. Both automatic and manual cancellation block flagged attempts.

## Validation limits

Tests use fake HTTP only. Phase 6C feature tests require isolated SQLite `:memory:` and standard test migrations without a wrapping transaction, allowing a strict zero-transaction HTTP assertion. They cannot prove MySQL row-lock behavior. A separate isolated MySQL test database, concurrent-worker acceptance, sandbox acceptance, public HTTPS endpoints, monitoring and deployment data validation remain operational prerequisites. Do not point tests at the development database.
