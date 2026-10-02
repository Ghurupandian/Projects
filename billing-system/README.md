# Subscription Billing & Usage Metering

## 1. Overview and stack

A multi-tenant JSON API for recording customer usage, aggregating it by day, billing monthly subscriptions, and reporting merchant dashboard metrics.

- PHP `^8.3`, Laravel `^13.17` (as constrained in `composer.json`)
- MySQL
- Database queue (`jobs`, `job_batches`, and `failed_jobs`)
- File cache by default; cache store can be configured through `.env`
- PHPUnit
- INR amounts stored as integer paise

## 2. Setup and run

Prerequisites: PHP and Composer, MySQL, and the PHP MySQL PDO extension.

```bash
composer install
```

Copy `.env.example` to `.env` (PowerShell: `Copy-Item .env.example .env`), then configure MySQL and the local drivers:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=billing_system
DB_USERNAME=root
DB_PASSWORD=
QUEUE_CONNECTION=database
CACHE_STORE=file
```

Create **both** databases in MySQL:

```sql
CREATE DATABASE billing_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE billing_system_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Generate the application key, migrate the development database, then seed demo data:

```bash
php artisan key:generate
php artisan migrate --force
php artisan db:seed
```

The first seeding prints the demo merchant API key once. Save it for API calls; if the demo merchant already exists, reseeding cannot reveal its original key.

To reset the development database and create fresh demo data/API key, run:

```bash
php artisan migrate:fresh --seed
```

**Warning:** `migrate:fresh` drops all tables in the configured development database and permanently deletes its data. It does not target the separate `.env.testing` database unless you explicitly change the environment.

The checked-in `.env.testing` targets `billing_system_test` on local MySQL as `root` with no password. Update it if your local MySQL credentials differ. Migrate that separate database before running tests:

```bash
php artisan migrate --env=testing --force
php artisan test
```

Run each process in its own terminal:

```bash
php artisan queue:work database
php artisan schedule:work
php artisan serve
```

The API is then available at `http://127.0.0.1:8000`.

## 3. API reference

All API endpoints require `X-API-Key`. Responses are JSON without a `data` wrapper.

The curl examples below use Bash syntax. Set the key in that shell first:

```bash
export API_KEY='your-printed-demo-api-key'
```

On Windows, run `curl.exe` from PowerShell and replace `$API_KEY` with the key, or import the URL, headers, and JSON body into Postman.

### Record usage

`POST /api/usage`

```bash
curl -i -X POST http://127.0.0.1:8000/api/usage \
  -H "X-API-Key: $API_KEY" \
  -H "Idempotency-Key: request-123" \
  -H "Content-Type: application/json" \
  -d '{"customer_reference":"demo-customer-001","units":25}'
```

Body fields: `customer_reference` (merchant-scoped external ID), `units` (integer 1–1,000,000), and optional ISO timestamp `occurred_at` (defaults to now).

| Status | Meaning |
| --- | --- |
| `201 Created` | New usage event recorded |
| `200 OK` | Identical idempotent replay; response includes `Idempotent-Replay: true` |
| `409 Conflict` | Idempotency key reused with a different payload |
| `422 Unprocessable Entity` | Invalid input, timestamp more than five minutes in the future, or no subscription covering the usage date |
| `404 Not Found` | Customer reference is unknown to the authenticated merchant |
| `401 Unauthorized` | Missing or invalid API key |
| `429 Too Many Requests` | Merchant exceeded 120 requests per minute; includes `Retry-After` |

### Health/demo endpoint

`GET /api/ping` is a small authenticated health/demo endpoint. It returns `pong`, the authenticated tenant, the cached plan version, and that merchant's cached active plans. It is not a substitute for an external monitoring/health check.

### Merchant dashboard

`GET /api/merchants/{id}/dashboard`. The path ID must match the authenticated merchant or the API returns `403 Forbidden`.

```bash
curl -i http://127.0.0.1:8000/api/merchants/1/dashboard \
  -H "X-API-Key: $API_KEY"
```

Example response shape (the daily trend contains 30 date entries):

```json
{
  "merchant_id": 1,
  "period": {
    "as_of_date": "2026-10-02",
    "cycle_start": "2026-10-01",
    "cycle_end": "2026-10-31",
    "elapsed_days": 2,
    "cycle_days": 31
  },
  "top_customers": [
    {
      "customer_reference": "demo-customer-004",
      "name": "Dev Mehta",
      "usage_units": 5000,
      "included_units": 50000,
      "allowance_percent": 10
    }
  ],
  "projected_overage_revenue_paise": 0,
  "current_cycle_usage": {
    "used_units": 8200,
    "included_units": 100000
  },
  "churn_risk_customers": [
    {
      "customer_reference": "demo-customer-001",
      "name": "Asha Sharma",
      "current_usage_units": 0,
      "previous_usage_units": 900,
      "drop_percent": 100
    }
  ],
  "daily_usage_trend": [
    {"date": "2026-09-03", "usage_units": 0},
    {"date": "2026-10-02", "usage_units": 8200}
  ],
  "active_plans": [
    {
      "id": 1,
      "name": "Starter",
      "billing_cycle": "monthly",
      "base_price_paise": 100000,
      "included_units": 10000,
      "overage_rate_paise": 15
    }
  ]
}
```

The numbers above illustrate the response shape; actual values depend on current data and date.

## 4. Architecture and request flow

Merchant system → `POST /api/usage` → `usage_events` → `AggregateDailyUsageJob` → `daily_usages` → `BillingCalculationService` → invoices and line items. Dashboard analytics also read `daily_usages`.

Relevant folders and responsibilities:

- `app/Http/Controllers` — HTTP entry points; controllers remain thin.
- `app/Http/Requests` and `app/Http/Resources` — request validation and JSON representation.
- `app/Actions` — workflow orchestration and transactional use cases (usage, subscriptions, invoicing, dashboard).
- `app/Services` — reusable domain/services; `BillingCalculationService` is pure and has no database access.
- `app/DTOs` — data passed between application and domain layers.
- `app/Jobs` — queued daily aggregation and cycle invoice work.
- `app/Events` — domain notifications, including `InvoiceGenerated` and plan-change/cache invalidation events.
- `app/Models` — Eloquent persistence and relationships.
- `database/migrations`, `database/seeders`, `tests` — schema, demo data, and PHPUnit coverage.

The usage endpoint authenticates the merchant, validates a request, maps it to a DTO, and delegates to an action. The action resolves the tenant-scoped customer, checks subscription date coverage, then inserts one raw event. Jobs aggregate raw events into daily totals; invoice actions pass DTOs into the calculator and persist invoice snapshots.

## 5. Schema and scale notes

Core tables:

- `merchants`, `plans`, `customers`
- `subscriptions`, `subscription_segments`
- `usage_events`, `daily_usages`
- `invoices`, `invoice_line_items`
- Laravel queue tables: `jobs`, `job_batches`, `failed_jobs`

Important indexes include:

- `merchants.api_key_hash` unique
- `plans(merchant_id, is_active)` and unique `(merchant_id, name)`
- `customers(merchant_id, customer_reference)` unique
- `usage_events(merchant_id, idempotency_key)` unique
- `usage_events(customer_id, occurred_at)` and `(occurred_at, customer_id)`
- `daily_usages(customer_id, usage_date)` unique and `(merchant_id, usage_date)`
- `subscription_segments(subscription_id, starts_at)`
- `invoices(subscription_id, cycle_start, cycle_end)` unique

At 5M+ raw usage rows, tenant/idempotency and time/customer indexes support direct lookups and time-bounded aggregation. Keeping raw events separate from compact daily aggregates avoids repeatedly scanning raw history for dashboard and billing queries. Actual throughput and query plans should be measured against production-like data; the index does not itself guarantee a particular scale limit.

The additional `(occurred_at, customer_id)` index supports date-scoped aggregation scans and grouping by customer. It consumes storage and slightly slows inserts. Monthly partitioning by `occurred_at` is a longer-term option for pruning and archival, but MySQL requires every unique key on a partitioned table to include its partitioning column. That conflicts with current global uniqueness on `(merchant_id, idempotency_key)` and the primary key, so partitioning requires a deliberate key/idempotency redesign rather than a direct toggle. In addition, MySQL does not support foreign keys on partitioned InnoDB tables; partitioning `usage_events` would therefore also require dropping its foreign keys. Other future options include archiving older raw events to cheaper storage and retaining daily aggregates for reporting.

## 6. Idempotency

Usage ingestion makes one insert and relies on the unique index `(merchant_id, idempotency_key)`—there is no check-then-insert race. On a unique collision it loads the original event:

- Same customer and units, and matching `occurred_at` when the client supplied it: return the original result with `200` and `Idempotent-Replay: true`.
- Different customer, units, or explicitly supplied timestamp: return `409 Conflict`.
- If the original request omitted `occurred_at`, a replay compares only customer and units; a newly defaulted “now” timestamp is not compared.

Invoices are idempotent on `(subscription_id, cycle_start, cycle_end)`, with an existing-invoice check before calculation and a unique index as the final concurrency guard. Invoice numbers are deterministic by subscription and cycle. Duplicate generation is a no-op; only a newly committed invoice dispatches `InvoiceGenerated`.

## 7. Billing math

Billing cycles are calendar months, with inclusive dates and actual month length. The subscription’s active dates and plan segments are clipped to the cycle. A plan change is effective on its start date; usage that date belongs to the new plan.

For cycle length `D`, segment length `d`, base price `B` paise, included units `I`, segment usage `U`, and overage rate `R` paise per unit:

- Base = `B × d / D`.
- Exact allowance = `I × d / D`.
- Exact overage units = `max(0, U × D − I × d) / D`.
- Overage charge = exact overage units × `R`.

Allowance and overage fractions are retained as integer numerators over `days_in_cycle` in line items; the existing whole-unit columns are half-up rounded display values. Base and overage amounts are independently rounded half-up to integer paise per segment, then segment amounts are summed. Calculations use integer arithmetic, not floating point; overflow fails explicitly.

**Worked October example:** Starter, Oct 1–15, base 50,000 paise, 5,000 included units, rate 20 paise, usage 2,000: base rounds to 24,194 paise and overage is zero. Growth, Oct 16–31, base 100,000 paise, 10,000 included units, rate 10 paise, usage 6,000: base rounds to 51,613 paise and overage to 8,387 paise. Total: **84,194 paise**.

## 8. Caching

`PlanService` caches merchant plan lookups and active plan lists for 10 minutes using versioned keys:

- `merchant:{merchant_id}:plan:{plan_id}:v{version}`
- `merchant:{merchant_id}:active_plans:v{version}`

The version counter `merchant:{merchant_id}:plans_version` is retained indefinitely. `PlanObserver` dispatches `PlanChanged` on plan create/update/delete; `InvalidatePlanCacheListener` increments the version. `PlanService` is used by `/api/ping` to get the plan version and active plans, and by `GetMerchantDashboardAction` both to get the dashboard cache version and to load active plans through the cached active-plan list. Subscription create/change actions also invalidate the merchant version after their transaction commits.

The dashboard response has a 600-second TTL and key `merchant:{merchant_id}:dashboard:v{plans_version}:{as_of_date}`. Usage aggregates may therefore be stale for up to 10 minutes. Invoice calculations deliberately load persisted plan snapshots referenced by subscription segments from the database; they must use the segment's historical plan, not the currently active-plan cache. File-cache increment is not atomic under concurrent invalidations; Redis is the stronger option when that matters.

## 9. Queues and scheduling

- `AggregateDailyUsageJob` handles one usage date; selects customer IDs in batches of 5,000 and executes a grouped `INSERT ... SELECT ... ON DUPLICATE KEY UPDATE` per batch. It recomputes rather than increments and implements `ShouldBeUnique` keyed by usage date. It has three attempts with backoff of 60, 300, and 900 seconds.
- Every five minutes, the scheduler dispatches aggregation for the current and previous UTC dates. The schedule uses `withoutOverlapping()`.
- On cycle close, `PrepareCycleInvoicesJob` selects overlapping subscriptions and dispatches `GenerateSubscriptionInvoicesJob` chunks of 100. Each chunk recomputes daily usage for its customers, then invoices subscriptions in separate transactions.
- Chunk processing logs individual failures and continues through the chunk; it then throws with failed IDs so Laravel retries. The `failed()` hook logs after retry exhaustion. Already-invoiced subscriptions are no-ops on retry.
- Monthly invoice preparation is scheduled for day 1 at 00:10. Start the worker and scheduler as described in Setup.
- `ShouldBeUnique` job locks and scheduler `withoutOverlapping()` locks use the configured cache store. The default file-cache locks/versioning are suitable for a single server; a multi-server deployment needs a shared lock-capable store such as Redis.

Late events belonging to an already-invoiced cycle are excluded from aggregation and logged by the aggregation job. The usage endpoint does not query invoices.

## 10. Scope

Merchants, plans, customers, and subscriptions are created through models, actions, and the seeder; there are no HTTP CRUD endpoints for them. `ChangePlanAction` is exercised by tests and seeded data and has no HTTP endpoint.

## 11. Tests

The suite currently contains **60 tests**. It covers usage-ingestion idempotency and tenant behavior, billing/proration math, plan changes, daily aggregation, invoice generation and idempotency, dashboard metrics, dashboard tenant isolation, and demo data. Tests use the separate MySQL database `billing_system_test`, configured in `.env.testing`; create it and run its migrations before `php artisan test`.

## 12. Demo walkthrough

The seeder derives dates from the current UTC date. It creates raw events for every day in the previous month and from the first day of the current month through today, creates invoices for the previous month, and recomputes current-month daily usage. Mira's plan change is effective on the 16th of the previous month. These dates avoid passing a future effective date to `ChangePlanAction`.

The four demo customers are:

- Asha Sharma (`demo-customer-001`) and Ravi Patel (`demo-customer-002`) have current-month daily usage more than 50% below their previous-month daily usage and should appear in churn risk after at least one completed current-month day.
- Mira Iyer (`demo-customer-003`) changes from Starter to Growth on the 16th of the previous month. Her previous-month invoice has two line items, one for each plan segment.
- Dev Mehta (`demo-customer-004`) provides another high-usage customer on the Pro plan.

Usage rows are inserted in bulk rather than as a large synthetic event stream. To reset and seed a clean development database, run:

```bash
php artisan migrate:fresh --seed
```

Copy the printed API key, then start the queue worker, scheduler, and web server in separate terminals:

```bash
php artisan queue:work database
php artisan schedule:work
php artisan serve
```

Find the seeded merchant ID and inspect Mira's invoice rows in MySQL:

```sql
SELECT id FROM merchants WHERE name = 'Acme Cloud Corp';

SELECT i.invoice_number, i.cycle_start, i.cycle_end, li.plan_id,
       li.segment_start, li.segment_end, li.base_amount_paise,
       li.overage_amount_paise, li.subtotal_paise
FROM invoices AS i
JOIN customers AS c ON c.id = i.customer_id
JOIN invoice_line_items AS li ON li.invoice_id = i.id
WHERE c.customer_reference = 'demo-customer-003'
ORDER BY i.cycle_start, li.segment_start;
```

Use the captured values in these requests (replace `1` with the query result):

```bash
curl -i http://127.0.0.1:8000/api/merchants/1/dashboard \
  -H "X-API-Key: YOUR_PRINTED_API_KEY"

curl -i -X POST http://127.0.0.1:8000/api/usage \
  -H "X-API-Key: YOUR_PRINTED_API_KEY" \
  -H "Idempotency-Key: demo-walkthrough-001" \
  -H "Content-Type: application/json" \
  -d '{"customer_reference":"demo-customer-001","units":25}'
```

The usage request uses the current timestamp by default. The dashboard's usage metrics become available after scheduled aggregation; the seeder precomputes the previous/current-month daily usage data.

## 13. Assumptions and decisions

- Merchant identity comes from `X-API-Key`; only its SHA-256 hash is stored. Customer references are merchant-owned external strings and are not auto-created by usage ingestion.
- Usage ingestion accepts positive integer units and optional UTC-normalized `occurred_at`; timestamps over five minutes in the future are rejected. Each request is a raw event; daily usage is separately aggregated.
- Idempotency key is the `Idempotency-Key` header, max 100 characters. Usage calls are limited to 120 requests/minute per merchant.
- Subscription coverage for usage is date-based and does not filter status, allowing late events within the recorded subscription dates. Only one active subscription per customer/merchant is enforced by application actions with row locks.
- Billing implements monthly calendar cycles; `billing_cycle` remains extensible. Mid-cycle starts/ends and plan changes are inclusive date segments. Both base price and included allowance are prorated by segment days; prorating allowance alongside base price is the project decision because the brief does not specify allowance proration. Plan immutability for billing is a convention, not enforced in code: pricing changes should create a new plan row. Past invoices are protected from later plan edits by persisted invoice-line snapshots of rates and quantities.
- Plan segments must cover the active billing interval continuously without gaps or overlaps; the calculator rejects invalid coverage and duplicate daily-usage dates. A cycle with no active subscription days returns zero calculation totals; invoice generation skips creating an invoice when there are no billable segments.
- After invoice generation, late events for that cycle are ignored and logged by aggregation; existing invoices are not revised.
- Dashboard is JSON-only. MTD top-five and cycle usage include today. Churn and projection use completed days through yesterday; churn compares matching day ranges, clamped to the prior month, and requires prior usage greater than zero with a drop strictly greater than 50%. Day one has no churn list and zero projection.
- Dashboard included allowance is the full-cycle allowance across plan segments; display units are half-up rounded while its exact numerator is internal. Projection uses actual usage for closed segments; the open segment extrapolates observed completed-day usage across its active segment duration and applies that plan’s overage rate.
- Dashboard reads only `daily_usages`, does not filter by subscription status, and returns zero-filled trend dates. No prior-month usage means not a churn risk.
- Cache version changes after plan changes and subscription create/change; dashboard usage data can be stale for at most ten minutes.
- File cache is the default; file-driver version increments can race under concurrent writes. Redis is configurable.

## 14. Trade-offs and next work

The implementation favors auditable SQL indexes and daily aggregates over a more complex streaming pipeline. Queue chunking limits job size, while per-subscription invoice transactions isolate persistence failures. File cache and MySQL database queues keep local setup simple but are not intended to replace production capacity planning. The dashboard uses a short cache TTL instead of invalidating on every usage write. Monthly invoices are prepared at 00:10 on day 1; later-arriving events for that already-invoiced cycle are dropped from aggregates. A production system would add a configurable late-arrival/grace window before finalizing invoices.

With more time, I would benchmark and inspect query plans at production-like volume, add operational metrics and alerting for queue lag/failures, evaluate MySQL partitioning alongside global idempotency constraints, and add production-grade authorization/key rotation and invoice delivery/payment flows.

## 15. AI usage and project handoff

Development used Antigravity, Copilot, and Claude as coding assistants. Tools were switched when free quotas ran out; Claude chat was used for design review. `PROJECT_CONTEXT.md` is the durable handoff: it records approved schema, decisions, assumptions, and completed phase checkpoints so work can resume consistently. The `/prompts` directory contains the phase prompt/approval artifacts.
