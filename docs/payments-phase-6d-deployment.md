# Payment deployment readiness

This checklist prepares the Phase 1–6C payment system for deployment. It does not enable VNPay, run migrations, or reconcile data automatically.

## Read-only preflight

Run before maintenance or migration:

```shell
php artisan payments:deployment-check
```

Use `--json` for CI or release automation. `FAIL` exits non-zero. `WARNING` exits successfully but requires review. The command only issues schema-inspection and `SELECT` queries; it never updates data, migrates, or seeds.

Resolve every failure before deployment. In particular, legacy refund status `pending` needs a business decision and an explicit data remediation plan. The migration intentionally refuses to guess whether each row should become `requested`, `failed`, or another workflow state.

## Isolated MySQL/InnoDB validation

Normal tests remain SQLite in memory. The MySQL-only suite is opt-in and refuses to run migrations unless the selected database is named exactly `httmdt_payment_test`.

Create and authorize that isolated test database outside this repository, then provide explicit process-level test variables. Do not reuse development or production credentials/database names.

```powershell
$env:RUN_MYSQL_PAYMENT_TESTS='1'
$env:DB_CONNECTION='mysql'
$env:DB_DATABASE='httmdt_payment_test'
$env:DB_HOST='<isolated-test-host>'
$env:DB_PORT='3306'
$env:DB_USERNAME='<isolated-test-user>'
$env:DB_PASSWORD='<provided-securely>'
php artisan test --no-coverage tests/Feature/MySqlPaymentSchemaTest.php
```

The suite uses test-only `migrate:fresh` behavior and therefore must never target a development or production database. It validates InnoDB tables, nullable external gateway IDs, uniqueness, audit foreign keys, and deterministic `FOR UPDATE` payment-row ordering. Real multi-process race tests remain a separate acceptance requirement.

## Deployment sequence

1. Take and verify a restorable database backup.
2. Confirm the release commit, maintenance plan, queue handling, and rollback owner.
3. Run `php artisan payments:deployment-check`; archive the redacted result.
4. Stop on every `FAIL`; review every `WARNING`. Prepare reviewed, separately approved legacy-data remediation if needed.
5. Configure VNPay environment values through the deployment secret/configuration system. Keep `VNPAY_ENABLED=false` until migration and endpoint smoke checks pass. Required QueryDR values include `VNPAY_QUERY_URL` and `VNPAY_QUERY_SERVER_IP`.
6. During the approved deployment window, run the four pending migrations in timestamp order. Do not modify already-deployed historical migrations.
7. Re-run the preflight. Require no failures, verify MySQL 8+, InnoDB, and the intended database session timezone.
8. Clear stale configuration and route caches, then rebuild production caches using the project deployment procedure.
9. Verify route middleware and permissions for checkout, VNPay initiation, read-only Return, IPN, and admin reconciliation. Confirm the expiry command remains an intentional operator action/schedule.
10. Run database-backed smoke checks for COD checkout, VNPay attempt creation, Return read-only behavior, signed IPN settlement, QueryDR reconciliation, paid cancellation rejection, and exactly-once stock restoration.
11. Verify HTTPS reachability and allow-listing for Return/IPN/QueryDR, application logs/alerts, queue/command observability, and clock synchronization.
12. Only after those checks, enable VNPay in sandbox and perform controlled end-to-end acceptance. Production enablement requires a separate approval.

## Pending migration review

- `2026_09_08_000001_add_checkout_token_to_orders_table.php`: nullable UUID with a unique index; existing rows remain null and MySQL permits multiple nulls.
- `2026_09_08_000002_add_workflow_fields_to_refunds_table.php`: refuses legacy `pending` or unknown statuses before changing the default and adding actor/timestamp workflow fields.
- `2026_09_09_000001_prepare_payment_transactions_for_vnpay.php`: refuses missing, oversized, or trim/case-normalized duplicate merchant references before applying identity constraints and expiry fields.
- `2026_09_09_000002_create_payment_gateway_events_table.php`: append-only journal schema with restricted foreign-key deletion and indexed reconciliation fields.

Rollback is a release decision, not an automatic response: dropping workflow/audit columns can destroy operational evidence. Back up and reconcile data before any down migration.
