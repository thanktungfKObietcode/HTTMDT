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

Normal tests remain SQLite in memory. The MySQL-only suite is opt-in. Before migration it checks the testing environment, refuses cached config and URL/read/write overrides, checks the resolved connection, and verifies `SELECT DATABASE()` on the write connection returns exactly `httmdt_payment_test` with InnoDB as the default engine.

Create and authorize that isolated test database manually outside this repository. The development database `silver_jewelry` is forbidden for writing tests. A database administrator can run the following ONLY after checking the name does not already exist:

```sql
CREATE DATABASE `httmdt_payment_test` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

There is deliberately no `DROP DATABASE` or silent reuse of an existing schema. Use a dedicated test account restricted to this exact schema (escape underscores if granting with MySQL database wildcard patterns). Do not copy development/customer data. Provide its password through the local secret mechanism, not a committed file. Start a fresh PowerShell session for testing:

```powershell
$env:RUN_MYSQL_PAYMENT_TESTS='1'
$env:DB_CONNECTION='mysql'
$env:DB_DATABASE='httmdt_payment_test'
$env:DB_HOST='<isolated-test-host>'
$env:DB_PORT='3306'
$env:DB_USERNAME='<isolated-test-user>'
$env:DB_URL=''
$env:APP_ENV='testing'
$env:DB_TIMEZONE='+00:00'
# Supply DB_PASSWORD securely before the next commands; never echo it.

# Read-only effective-connection proof; this does not migrate.
@'
<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
try {
    Tests\Support\MySqlPaymentDatabaseGuard::assertIsolated();
    echo json_encode(Illuminate\Support\Facades\DB::selectOne(
        'SELECT VERSION() AS version, DATABASE() AS database_name, @@session.time_zone AS session_timezone, @@global.time_zone AS global_timezone, @@default_storage_engine AS storage_engine', [], false
    ));
} catch (Throwable) { fwrite(STDERR, 'Isolation proof failed; do not run MySQL tests.'); exit(1); }
'@ | php
if ($LASTEXITCODE -ne 0) { throw 'STOP: MySQL isolation not proven' }

# The same guard runs again immediately before test schema reset.
php artisan test --no-coverage tests/Feature/MySqlPaymentSchemaTest.php
php artisan test --no-coverage tests/Feature/MySqlPaymentConcurrencyTest.php
```

These dedicated suites use test-only `migrate:fresh` and rollback behavior and therefore must never target a development or production database. The guard re-proves the effective connection immediately before each schema reset. Schema coverage includes nullable unique checkout tokens, merchant/external references, MySQL case-insensitive collation, legacy identity/refund preflight, DECIMAL precision, JSON/FKs/indexes, UTC timestamps, transaction rollback and migration down/up cycles.

Close that test shell before running the normal SQLite suite. Never run the full suite with MySQL overrides: only the two dedicated guarded MySQL files are authorized. Normal SQLite runs intentionally skip their seven tests.

### Real concurrency harness

`MySqlPaymentConcurrencyTest` launches two independent PHP processes per scenario through Symfony Process. Each process bootstraps Laravel, rechecks database isolation and invokes the production checkout controller, lifecycle cancellation, signed IPN controller, verified QueryDR reconciliation or expiry service. QueryDR uses HTTP fakes; stray HTTP requests are prohibited. Connection credentials travel only in an anonymous stdin pipe, never files, command-line arguments or test output.

Before the competing production `FOR UPDATE` queries execute, both workers rendezvous over stdin/stdout. A third, separately verified test connection temporarily holds the shared product/order row. Both workers proceed and must simultaneously appear in `performance_schema.data_lock_waits`, matched to their distinct connection IDs through `performance_schema.threads`. Only then is the blocker released. For expiry, the rendezvous is at the final cancellation decision, after verified unpaid QueryDR has completed. This demonstrates actual overlapping InnoDB transactions, not sequential interleavings or sleep-based assumptions.

The test account needs read access to those performance-schema views and visibility of its worker sessions, in addition to privileges on `httmdt_payment_test`. Missing instrumentation/visibility causes failure rather than a false concurrency pass. Do not grant broad production privileges or change global instrumentation for these tests. Do not run multiple PHPUnit schema-reset suites against this one database simultaneously. The harness stops workers and releases its locks in `finally`; `DatabaseMigrations` cleans up only the guarded test schema. No database is dropped.

Scenarios: A two checkouts compete for stock one; B two cancellations; C signed successful IPN versus final expiry cancellation; D verified successful QueryDR versus signed IPN; E two final expiry decisions; F two distinct successful payment attempts. Assertions cover stock/order/item state, exactly-once restoration, one effective paid settlement and durable duplicate/conflict outcomes. These bounded races are validation, not a sustained load/deadlock stress test.

## Timezone requirement

Laravel uses UTC; gateway protocol dates use Asia/Ho_Chi_Minh. `DB_TIMEZONE` now controls only new Laravel MySQL connection sessions. Unset/blank retains the previous server-derived setting. Set `DB_TIMEZONE=+00:00` in an isolated test environment and, after review, the deployment configuration; no global MySQL setting is required.

MySQL TIMESTAMP converts between the connection timezone and UTC storage, unlike DATETIME. Writing UTC wall-clock values through a UTC+7 connection can store the wrong instant even when same-session readback looks unchanged. Switching existing data to UTC can therefore expose a seven-hour shift. Review/back up historical TIMESTAMP values and any DB-generated defaults before enabling this option for an existing database. Do not automatically backfill them. Reconnect long-running workers and rebuild configuration cache after an approved setting change, then verify `@@session.time_zone = '+00:00'` from Laravel and verify persisted expiry/signing dates. [MySQL timezone semantics](https://dev.mysql.com/doc/refman/8.0/en/time-zone-support.html).

## Sandbox acceptance procedure

These are manual acceptance steps for a separately configured sandbox application/database; they were not executed during Phase 6D-2.

1. Prove database isolation, apply/rehearse migrations there, and resolve preflight failures. Confirm UTC sessions and synchronized clocks. Never use `silver_jewelry` for acceptance writes in this task.
2. Have the developer provision sandbox merchant settings through the secret manager. Confirm the sandbox payment host and QueryDR URL, public HTTPS Return/IPN URLs, and the QueryDR server IP. Register the IPN URL with VNPay. Enable VNPay only in that sandbox instance.
3. Create a test order through checkout. Record only the internal order/attempt references, persisted amount, expiry, and initial pending state. Confirm one inventory decrement and a `payment_initiated` journal event. Do not save signed URLs in tickets or access logs.
4. Complete one payment using VNPay-provided test facilities. Return must only display local confirmation/pending state (it may append an observation). Only verified IPN or verified QueryDR may settle financially. Check the event journal, paid timestamp, external transaction number, amount, and unchanged fulfillment/inventory.
5. Refresh Return; verify no extra attempt or financial mutation. Validate IPN retries idempotently, failure after paid, and failure then valid success. Capture only redacted response codes, local states and event IDs.
6. Exercise admin QueryDR using the persisted transaction selector. Verify matching amount/reference, response signature, shared settlement/no-op, and durable query outcomes. QueryDR HTTP must remain outside financial database transactions.
7. Test retry/expired attempts and terminal-order conflicts only with controlled sandbox fixtures. An ambiguous or failed QueryDR must prevent expiry cancellation; paid orders must reject cancellation. No refund API is authorized.
8. Sign off A-F concurrent scenarios separately using genuinely overlapping independent MySQL connections/processes. Monitor deadlocks, rollbacks, inventory and journal evidence.

Protocol references checked: [VNPay PAY / Return / IPN](https://sandbox.vnpayment.vn/apis/docs/thanh-toan-pay/pay.html), [QueryDR](https://sandbox.vnpayment.vn/apis/docs/truy-van-hoan-tien/querydr%26refund.html). Public documentation reads are not sandbox transactions.

## Phase 6D-2 execution record (2026-09-10)

- Baseline: `8efb8c2`, branch `main`.
- Read-only server probe: `26.7.0`, `MySQL Community Server - GPL`; database `silver_jewelry`; default engine InnoDB. This does not certify MySQL 8 migration compatibility.
- `httmdt_payment_test` was absent; no isolated MySQL migrations, rollback tests or A-F real concurrency tests ran. The normal PHPUnit connection was verified as SQLite `:memory:`.
- Laravel/PHP timezone UTC; MySQL session/global `SYSTEM`, system `SE Asia Standard Time`, observed UTC offset +420 minutes. No global or current development-session change was made.
- VNPay disabled; merchant secret/code, payment/Return/IPN/QueryDR endpoints and QueryDR server IP absent. No actual sandbox transaction or gateway API request ran.
- A URL could override the old test guard's declared database. The guard now fails before migrations for that case and verifies the server's actual selected database; regression tests use mocks/lazy connections only.
- Validation results: targeted tests 250 passed, 1 skipped, 1130 assertions, 0 failures. Full normal suite run once: 499 discovered, 498 passed, 1 intentionally skipped MySQL-only test, 2121 assertions, 0 failures (baseline: 488 discovered / 2083 assertions). These results are SQLite/mocked validation, not MySQL concurrency evidence.
- Before Phase 6D-3: manually provision isolated MySQL and restricted credentials, run schema and genuine concurrency validation, validate timezone/data semantics, configure sandbox HTTPS callbacks, and collect redacted end-to-end evidence. Real gateway refunds remain outside scope.

## Phase 6D-2 continuation: isolated MySQL provisioned (2026-09-10)

This continuation supersedes the earlier MySQL/concurrency execution blockers, not the outstanding sandbox acceptance requirement.

- Before any test write, the existing guard rejected URL/read/write overrides and verified the effective MySQL connection with `SELECT DATABASE() = httmdt_payment_test`. The manually provisioned database initially had zero tables. Neither development schema nor development data was changed.
- Actual server: `26.7.0`, MySQL Community Server - GPL, InnoDB. This is evidence for the installed server, not a separate MySQL 8.0-version test run.
- A genuine rollback defect was reproduced: error 1553 when removing `refunds_order_status_index`, because InnoDB had adopted it as the `order_id` FK's supporting index. The workflow migration's `down()` now restores an independent supporting index if needed before dropping the composite. No forward/payment lifecycle semantics changed. Actual down/up, legacy preflight and full migration rollback now pass.
- MySQL schema and the six concurrent scenarios initially passed together: 7 tests, 90 assertions. The schema test was subsequently extended with actual MySQL legacy-reference preflight/down/up checks and passed: 1 test, 51 assertions. The six race tests contributed 48 assertions; all A-F passed with two simultaneous lock-wait witnesses per scenario.
- Related normal regressions: `PhaseSixDDeploymentReadinessTest`, `PhaseSixDTwoValidationSafetyTest`, `PhaseSixCReconciliationExpiryTest`: 86 passed, 414 assertions, zero failures.
- Final normal full suite, run once: 505 discovered, 498 passed, 7 intentionally skipped MySQL-only tests, 2121 assertions, zero failures. Previous worktree baseline: 499 discovered, 498 passed, 1 skipped, 2121 assertions. MySQL execution is separate from the normal SQLite suite; the added skips are the six opt-in races.
- Laravel/PHP UTC and isolated session `+00:00` verified. TIMESTAMP epoch/readback and Asia/Ho_Chi_Minh gateway conversion passed against MySQL. Global timezone remained `SYSTEM`; no global change or development-data timezone conversion occurred. Deployment must still review historical timestamps before enabling UTC sessions there.
- No live VNPay transaction, QueryDR request or refund API call was performed. QueryDR in worker processes used HTTP fakes with stray-request prevention. Existing missing sandbox credentials/HTTPS callback acceptance remains outstanding.
- Remaining operational checks: real sandbox acceptance with public HTTPS Return/IPN, environment-specific preflight/migration/data validation, approved timezone configuration, monitoring/alerts and smoke checks. Re-run the guarded schema/races if deploying a different server version. No Phase 6D-3 work was started.

## Phase 6D-3: runtime security and production operations

This phase hardens the application boundary; it does **not** enable a real production gateway. VNPay payment and QueryDR endpoint allow-lists remain sandbox-only. SANDBOX TRANSACTION: NOT EXECUTED. VNPAY REFUND: NOT IMPLEMENTED.

### Runtime configuration and secrets

- Set `APP_ENV=production`, `APP_DEBUG=false`, and an HTTPS `APP_URL` for deployed instances. The production request guard fails with a generic 503 for unsafe runtime configuration, and rejects HTTP or mismatched Host requests before routing/session/database work. Local HTTP development is unchanged.
- `SESSION_SECURE_COOKIE` defaults to true in production. Require Secure, `SESSION_HTTP_ONLY=true` and `SESSION_SAME_SITE=lax` (Strict is accepted but may lose the session on the cross-site Return). Use persistent `SESSION_DRIVER` and `CACHE_STORE`; array/null cache and array sessions are not production-safe. Multi-node instances need shared stores. Queue worker requirements remain application-specific; expiry is a scheduled foreground command, not a queued job. Preflight warns about `QUEUE_CONNECTION=sync`.
- Supply `APP_KEY`, `VNPAY_TMN_CODE`, `VNPAY_HASH_SECRET` and database credentials through the deployment secret mechanism. Do not put credentials into tickets, source, browser templates, command output or logs. VNPay signing uses its dedicated secret, never APP_KEY. The legacy APP_KEY-signed simulated callback returns 404 in production.
- Keep `VNPAY_ENABLED=false` until approved readiness/acceptance checks pass. Required enabled-gateway settings remain `VNPAY_PAYMENT_URL`, `VNPAY_RETURN_URL`, `VNPAY_IPN_URL`, `VNPAY_VERSION=2.1.0`, `VNPAY_CURRENCY=VND`, `VNPAY_LOCALE`, `VNPAY_ORDER_TYPE`, `VNPAY_TIMEZONE=Asia/Ho_Chi_Minh`, `VNPAY_QUERY_URL`, and `VNPAY_QUERY_SERVER_IP`. Do not infer production endpoints from sandbox URLs.
- Production Return and IPN URLs must exactly match `APP_URL` plus `/thanh-toan/vnpay/return` and `/thanh-toan/vnpay/ipn`. Both are public GET endpoints. Return displays state only; it never settles. IPN verifies signatures and protocol fields before the shared transactional settlement path. QueryDR uses persisted attempt identity, a separately signed/verified protocol, a configured server IP, a 3-second connection timeout and a bounded request timeout (default 10 seconds). It follows no HTTP redirects and has no automatic network retry loop. It never performs HTTP under financial locks.
- Configure `VNPAY_QUERY_SERVER_IP` to the merchant server address required by VNPay, including the correct public egress identity where applicable; syntax checks cannot prove provider registration or reachability. Use UTC application/database sessions as described above; do not automatically convert historical timestamps.

### HTTPS, proxies, cookies and headers

`TRUSTED_PROXIES` accepts only explicit IP addresses/CIDRs; wildcard, REMOTE_ADDR and catch-all /0 ranges are refused. The request middleware reads config after bootstrap and does not inherit Host-based platform wildcard trust. Only forwarded client IP, scheme and port are trusted; forwarded Host is ignored. Configure the reverse proxy to preserve the public Host and overwrite incoming forwarding headers, and restrict direct origin access at the firewall. Validate the real proxy path during smoke testing. See [Laravel trusted proxies](https://laravel.com/docs/13.x/requests#configuring-trusted-proxies).

Application responses include `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`, and a referrer policy. Payment/order/refund responses use `no-referrer` and `Cache-Control: no-store, private`. Production HTTPS responses include HSTS `max-age=31536000`, without includeSubDomains/preload; local HTTP receives no HSTS. Confirm long-lived HTTPS support before serving this header.

TLS certificates/protocols, HTTP-to-HTTPS redirects, origin access control, access-log redaction and volumetric protection belong at the proxy/platform. Never log payment query strings, full signed redirect URLs, callback bodies, cookies or Authorization headers. No strict CSP was added: existing Blade inline scripts/styles and asset origins require a separate inventory and report-only trial before enforcement. Do not deploy an untested proxy CSP that breaks checkout or VNPay navigation.

### Abuse controls and safe diagnostics

- Checkout POST and VNPay retry share 10 requests/minute per authenticated customer, independent of supplied order IDs. Return allows 60/minute per IP. Admin reconciliation allows 5/minute per admin, in addition to auth/active/backoffice/admin/orders.update and owner-order/transaction scoping.
- IPN has **no IP throttle**: shared gateway infrastructure and legitimate retries must not be rejected by a customer limiter. Instead it uses signature checks, idempotency, financial locks and bounded input: Return/IPN reject queries larger than 16 KiB or more than 64 parameters before journal/settlement work. Configure matching edge request-size limits and monitor abuse without blindly blocking legitimate gateway IPs.
- Customer/admin POST CSRF protection stays in place. The existing narrow legacy simulation exemption was not expanded. GET Return/IPN need no CSRF exemption.
- Payment controllers display generic safe failures instead of raw exception messages. Unexpected payment-route exceptions are logged by class/category only, without traces, SQL bindings, previous exceptions, request data or secrets. Validation messages remain normal field-level errors. Future legacy simulation receipts retain only five validated non-signature fields; existing historical receipts were not modified and may need a separately authorized sensitive-data review.
- The append-only gateway journal remains the financial audit source; required settlement outcomes stay atomic. Monitor `reconciliation_required`, terminal-order success, second success, amount/identity conflicts, invalid signatures, repeated QueryDR failures and missing IPNs. Use safe attempt/order identifiers and aggregate counts, not signed payloads. Set non-debug, retained/rotated logs (for example the existing daily channel), monitor disk/collector health and restrict log access.

### Expiry scheduling

The registered task is `payments:expire-vnpay --limit=25`, every five minutes, production-only and opt-in via `VNPAY_EXPIRY_SCHEDULER_ENABLED=true`. It requires enabled/configured QueryDR, safe production runtime settings and shared database/Redis cache. `withoutOverlapping()` uses the default 24-hour mutex expiry; `onOneServer()` prevents the same due task running on multiple scheduler nodes sharing that cache. Neither option replaces the existing row-lock/idempotency protections.

After deployment review, configure the server scheduler (not performed by this phase). A typical Linux cron entry, adjusted to the actual release path/runtime, is:

```cron
* * * * * cd /path/to/approved-release && php artisan schedule:run >> /var/log/silver-atelier-scheduler.log 2>&1
```

Use the platform-equivalent scheduler on Windows/managed hosting. Verify `php artisan schedule:list`, the heartbeat, cache connectivity and redacted batch output. A dry run is `php artisan payments:expire-vnpay --dry-run --limit=25`. Do not execute expiry against development data as a smoke test. The task does not run in maintenance mode. Release a stale scheduler mutex only after verifying that the original worker has stopped; never clear locks blindly.

Expiry logs counts for scanned/cancelled/skipped-paid/skipped-conflict/failed and the last processed ID. Initial database lookup failure and scheduler command failure produce safe operational errors. Alert on failures, conflicts, stale pending orders and a missing heartbeat. Repeated conflicts among the oldest 25 candidates can delay later candidates: operators must review them and use the existing `--after-id=<last_id>` pagination for approved manual batches. Scheduling intentionally does not bypass ambiguous payment state or introduce a new cursor/queue architecture.

### Preflight and go-live gate

`payments:deployment-check` and `--json` remain read-only: no migrations, row writes, network requests or financial actions. They now report unsafe production debug/HTTPS/cookies/proxy/store settings, enabled-gateway configuration, QueryDR readiness, pending migration records, scheduler/cache requirements and logging/queue warnings, in addition to existing data/schema/engine/timezone checks. Connection failures return generic machine-readable FAIL output, not credentials. Static checks cannot prove cron execution, shared cache topology, public HTTPS reachability or provider registration.

Follow the backup, preflight, approved migration, cache rebuild and rollback procedure below. Smoke-test the deployed proxy path, cookie flags, security headers, authorization, CSRF and throttles; confirm no IPN IP throttle. Perform sandbox acceptance using the existing checklist, including Return-before-IPN and IPN-before-Return, duplicates, QueryDR and terminal conflicts. Do not enable production payment acceptance until these checks, provider-approved endpoint/merchant configuration, alerts, timezone/data review and human approval are complete. Real VNPay refund execution remains fail-closed until separately scoped Phase 6E work and acceptance.

Scheduler semantics follow [Laravel scheduling](https://laravel.com/docs/13.x/scheduling#preventing-task-overlaps); this documentation does not claim that a server cron or sandbox transaction was executed.

### Phase 6D-3 validation record

Starting checkpoint: `8f77b41 checkpoint-phase-6d2-mysql-concurrency-validation`; initial tracked worktree clean, with the three existing helper text files preserved. Added 37 hardening regressions. The related targeted group passed 243 tests / 1539 assertions; the final focused hardening plus payment-lifecycle group passed 61 / 516. The normal full suite was run once: **535 passed, 7 intentionally skipped MySQL tests, 2537 assertions, 0 failures** (542 discovered), versus 498 passed / 7 skipped / 2121 assertions before this phase.

No MySQL/concurrency rerun was required: financial state transitions, locking, transaction ordering, expiry decisions and inventory algorithms were unchanged. The only PaymentService edit redacts the signature from legacy simulation receipt metadata. No development database writes, environment-file changes, server scheduler configuration, live gateway calls, production gateway enablement or refund API implementation occurred. Proxy/server smoke tests, public HTTPS/provider registration and sandbox acceptance still require deployment-operator validation.

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
