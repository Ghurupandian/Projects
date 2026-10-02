You are my senior Laravel engineering partner. I am completing a take-home assignment for a Senior Laravel Developer role. Read this entire brief and my decisions carefully.

IMPORTANT WORKING RULES
1. Do NOT write any code, create any files, or run any commands until I explicitly say "start Phase X".
2. If anything is unclear or you would have to guess, STOP and ask me. Never silently assume.
3. We will work in small phases to avoid hitting usage limits. After each phase, stop and wait for my approval.
4. Do not invent table, column, class, or route names beyond what we agree. Propose the names first and wait for my approval.
5. Keep responses concise. No long explanations unless I ask.

PROJECT: Subscription Billing & Usage-Metering System (multi-tenant SaaS backend)

TECH STACK
- PHP + Laravel (latest stable version available in the project), MySQL
- Queue: database driver. Cache: file driver by default, configurable to Redis through .env
- Tests: PHPUnit (Laravel default)
- Currency: INR. Store money as integer paise to avoid floating-point errors.

DOMAIN
- Merchants (tenants) each define one or more Plans: name, base price, billing cycle, included usage units, overage rate per unit.
- Customers subscribe to a merchant's plan.
- Usage Events are recorded per customer per day (e.g. API calls). Assume very high write volume.
- At cycle end the system generates an invoice: base price + overage beyond the included allowance, with proration if the subscription started mid-cycle.

HOW IT WORKS IN PRACTICE
The merchant's own product is not part of this project. When a merchant's customer uses the merchant's service, the merchant's system calls OUR POST /usage endpoint to report that usage. We store it and bill from it.

FUNCTIONAL REQUIREMENTS
1. Normalized, indexed schema. Document how it holds up with 5 million+ usage-event rows, and note denormalization/partitioning options.
2. POST /usage: safe at high throughput and idempotent (a retried request must never double-count).
3. A queued, chunked job that aggregates a customer's daily usage and, at cycle end, generates the invoice with correct proration and overage math.
4. Cache plan/pricing lookups and document the invalidation strategy.
5. GET /merchants/{id}/dashboard returning: top 5 customers by usage this month, projected overage revenue for the current cycle, and customers whose usage dropped more than 50% month-over-month (churn risk).
6. Rate limiting on the usage endpoint.
7. Tests for aggregation and billing, including proration and overage edge cases.
8. Mid-cycle plan upgrade/downgrade: usage before the change is billed at the original plan's rate, usage after at the new plan's rate, with proration reflecting both segments.

QUALITY EXPECTATIONS
- Deliberate choices for chunking, idempotency and cache invalidation.
- Clean architecture: thin controllers, Services/Actions, DTOs, Form Requests, API Resources. No business logic in controllers.
- A README that reads like a team handoff: architecture summary, setup/run steps, assumptions, trade-offs, and what I would do with more time.
- Use Events/Listeners where they fit (e.g. InvoiceGenerated, PlanChanged, a cache-invalidation listener on plan updates), and Jobs for queued work.

MY DECISIONS (treat as final, and document them in the README as assumptions)
- Dashboard is a JSON API. A simple web page is optional at the very end only if time allows.
- Billing cycles are calendar months. Only "monthly" is implemented; keep the billing_cycle field extensible.
- Proration: base price AND included allowance are both prorated by days_remaining / days_in_cycle.
- Plan change: the change is effective from a specific date. Usage on or after that date is billed at the new plan. Each segment is calculated separately: segment base = base price x segment days / cycle days; segment allowance = included units x segment days / cycle days; segment overage = max(0, segment usage - segment allowance) x that plan's overage rate. The invoice is the sum of the segments, shown as separate line items.
- Authentication: each merchant has an API key sent in the X-API-Key header. It identifies the tenant and is the rate-limit key (120 requests/minute).
- Idempotency: the client sends an idempotency key; a unique index prevents duplicates. A repeated request returns the original result and does not count again.
- Dashboard definitions: "this month" is the current calendar month to date. Projected overage = linear projection of month-to-date usage to cycle end, then overage math. Churn risk compares month-to-date usage with the previous month's usage over the same number of days, flagging drops greater than 50%.
- Cache invalidation: explicit key forgetting on plan create/update/delete, using versioned keys (no cache tags, since the file driver does not support them).

WHAT I WANT FROM YOU NOW
1. Confirm you understood the project by summarizing it back in 8 to 10 lines.
2. List every ambiguity or open question you still have (numbered). If none, say "No open questions".
3. Propose the phase plan (for example: schema, usage endpoint, caching, billing logic, jobs, dashboard, tests, README).
Then STOP and wait. Do not write code.



## Confirmed Decisions
## Questions
1. **Idempotency Key & Customer Identifier Location**: Should the idempotency key be passed as an HTTP header (e.g., `Idempotency-Key`) or in the JSON payload body (`idempotency_key`)? Similarly, is the customer identifier an internal database ID or a merchant's external string identifier (e.g., `merchant_customer_id`)?
2. **Dashboard Tenant Authorization**: Should `GET /merchants/{id}/dashboard` enforce that `{id}` matches the merchant authenticated by `X-API-Key` (returning `403 Forbidden` on mismatch)?
3. **Usage Ingestion Semantics**: Does `POST /usage` accept an arbitrary positive unit delta for a given timestamp/date (e.g. `{"units": 25, "recorded_at": "..."}`), with raw event logging and separate daily aggregation, or does it increment a daily customer usage record directly?
4. **Subscription Structure**: Is a customer restricted to at most one active subscription at a time per merchant, and is the plan change triggered via an API endpoint or via a dedicated service/action?
5. **Segment Persistence**: For mid-cycle plan switches, do you prefer a dedicated table (e.g., `subscription_segments` or `subscription_histories`) tracking `[subscription_id, plan_id, starts_at, ends_at]`?

## Answers to questions:
1. Idempotency key goes in the HTTP header "Idempotency-Key". The customer is identified by the merchant's own external string ID (field: customer_reference), unique per merchant. Our internal IDs are never exposed to merchants.

2. Yes. GET /merchants/{id}/dashboard must enforce that {id} matches the merchant authenticated by X-API-Key. Return 403 on mismatch.

3. POST /usage accepts a positive integer "units" and an "occurred_at" timestamp (defaults to now). Every call is stored as a raw usage event. A separate job aggregates raw events into a daily usage table per customer. Billing reads from the daily table.

4. A customer has at most one active subscription per merchant at a time. Plan changes are done through a dedicated ChangePlanAction (used by tests and seeders). No public endpoint for it unless we have time at the end.

5. Yes, use a dedicated table for segments (e.g. subscription_segments with subscription_id, plan_id, starts_at, ends_at), as you proposed.


## Phase Plan
- **Phase 1: Database Schema & Architecture Design**
  - Propose table names, columns, indexes, and foreign keys.
  - Formulate the 5M+ row scalability and partitioning strategy for usage events.
  - Review and approve naming before migration creation.
- **Phase 2: Authentication, Tenant Context & Plan Caching**
  - API key middleware with rate limiting (120 req/min).
  - Plan retrieval service with versioned file-cache keys and invalidation event listeners.
- **Phase 3: High-Throughput Usage Ingestion (`POST /usage`)**
  - Form request, DTO, and API resource.
  - Idempotent event ingestion pipeline (handling duplicates and replay responses).
- **Phase 4: Billing & Proration Calculation Engine**
  - Pure domain service calculating cycle segments, prorated base fees, prorated allowances, and overage math.
- **Phase 5: Queued Invoicing & Usage Aggregation Jobs**
  - Chunked background jobs for aggregation and invoice generation with itemized line items.
  - Domain events (e.g., `InvoiceGenerated`).
- **Phase 6: Merchant Dashboard API (`GET /merchants/{id}/dashboard`)**
  - Analytics action computing top 5 customers MTD, linear overage projection, and >50% MoM churn comparison.
- **Phase 7: Automated Test Suite (PHPUnit)**
  - Unit & Feature tests covering proration, overage, plan transitions, idempotency replays, and dashboard metrics.
- **Phase 8: Documentation & README**
  - Architecture overview, setup/execution steps, assumptions, trade-offs, and future scaling considerations.



## Phase 1 Approved Schema & Architectural Decisions

### 1. Schema Definition

#### `merchants`
- `id`: `BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY`
- `name`: `VARCHAR(255)`
- `api_key_hash`: `VARCHAR(64) UNIQUE` (SHA-256 hash of API key; plain key shown only once)
- `created_at`, `updated_at`: `DATETIME / TIMESTAMP`

#### `plans`
- `id`: `BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY`
- `merchant_id`: `BIGINT UNSIGNED` (FK -> `merchants.id` ON DELETE CASCADE)
- `name`: `VARCHAR(255)`
- `billing_cycle`: `VARCHAR(50) DEFAULT 'monthly'`
- `base_price_paise`: `BIGINT UNSIGNED`
- `included_units`: `BIGINT UNSIGNED`
- `overage_rate_paise`: `BIGINT UNSIGNED`
- `is_active`: `BOOLEAN DEFAULT TRUE`
- `created_at`, `updated_at`: `DATETIME / TIMESTAMP`
- **Indexes**: `[merchant_id, is_active]`, Unique: `[merchant_id, name]`

#### `customers`
- `id`: `BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY`
- `merchant_id`: `BIGINT UNSIGNED` (FK -> `merchants.id` ON DELETE CASCADE)
- `customer_reference`: `VARCHAR(100)` (Merchant's external customer string ID)
- `name`: `VARCHAR(255) NULLABLE`
- `email`: `VARCHAR(255) NULLABLE`
- `created_at`, `updated_at`: `DATETIME / TIMESTAMP`
- **Indexes**: Unique: `[merchant_id, customer_reference]`, Index: `[merchant_id]`

#### `subscriptions`
- `id`: `BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY`
- `merchant_id`: `BIGINT UNSIGNED` (FK -> `merchants.id` ON DELETE CASCADE)
- `customer_id`: `BIGINT UNSIGNED` (FK -> `customers.id` ON DELETE CASCADE)
- `current_plan_id`: `BIGINT UNSIGNED` (FK -> `plans.id` ON DELETE RESTRICT; denormalized shortcut)
- `status`: `VARCHAR(50) DEFAULT 'active'`
- `starts_at`: `DATE`
- `ends_at`: `DATE NULLABLE`
- `created_at`, `updated_at`: `DATETIME / TIMESTAMP`
- **Indexes**: `[customer_id, status]`, `[merchant_id, status]`

#### `subscription_segments`
- `id`: `BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY`
- `subscription_id`: `BIGINT UNSIGNED` (FK -> `subscriptions.id` ON DELETE CASCADE)
- `plan_id`: `BIGINT UNSIGNED` (FK -> `plans.id` ON DELETE RESTRICT)
- `starts_at`: `DATE`
- `ends_at`: `DATE NULLABLE`
- `created_at`, `updated_at`: `DATETIME / TIMESTAMP`
- **Indexes**: `[subscription_id, starts_at]`

#### `usage_events`
- `id`: `BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY`
- `merchant_id`: `BIGINT UNSIGNED` (FK -> `merchants.id` ON DELETE CASCADE)
- `customer_id`: `BIGINT UNSIGNED` (FK -> `customers.id` ON DELETE CASCADE)
- `idempotency_key`: `VARCHAR(100)`
- `units`: `INT UNSIGNED`
- `occurred_at`: `DATETIME` (UTC timezone, preventing year 2038 overflow)
- `created_at`: `DATETIME / TIMESTAMP`
- **Indexes**:
  - Unique: `[merchant_id, idempotency_key]`
  - Index: `[customer_id, occurred_at]`

#### `daily_usages`
- `id`: `BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY`
- `merchant_id`: `BIGINT UNSIGNED` (FK -> `merchants.id` ON DELETE CASCADE)
- `customer_id`: `BIGINT UNSIGNED` (FK -> `customers.id` ON DELETE CASCADE)
- `usage_date`: `DATE`
- `total_units`: `BIGINT UNSIGNED`
- `created_at`, `updated_at`: `DATETIME / TIMESTAMP`
- **Indexes**:
  - Unique: `[customer_id, usage_date]`
  - Index: `[merchant_id, usage_date]`

#### `invoices`
- `id`: `BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY`
- `merchant_id`: `BIGINT UNSIGNED` (FK -> `merchants.id` ON DELETE CASCADE)
- `customer_id`: `BIGINT UNSIGNED` (FK -> `customers.id` ON DELETE CASCADE)
- `subscription_id`: `BIGINT UNSIGNED` (FK -> `subscriptions.id` ON DELETE CASCADE)
- `invoice_number`: `VARCHAR(50) UNIQUE`
- `cycle_start`: `DATE`
- `cycle_end`: `DATE`
- `base_amount_paise`: `BIGINT UNSIGNED`
- `overage_amount_paise`: `BIGINT UNSIGNED`
- `total_amount_paise`: `BIGINT UNSIGNED`
- `currency`: `CHAR(3) DEFAULT 'INR'`
- `status`: `VARCHAR(50) DEFAULT 'issued'`
- `created_at`, `updated_at`: `DATETIME / TIMESTAMP`
- **Indexes**: Unique: `[subscription_id, cycle_start, cycle_end]`

#### `invoice_line_items`
- `id`: `BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY`
- `invoice_id`: `BIGINT UNSIGNED` (FK -> `invoices.id` ON DELETE CASCADE)
- `plan_id`: `BIGINT UNSIGNED` (FK -> `plans.id` ON DELETE RESTRICT)
- `description`: `VARCHAR(255)`
- `segment_start`: `DATE`
- `segment_end`: `DATE`
- `days_in_segment`: `SMALLINT UNSIGNED`
- `days_in_cycle`: `SMALLINT UNSIGNED`
- `units_used`: `BIGINT UNSIGNED`
- `units_included`: `BIGINT UNSIGNED`
- `overage_units`: `BIGINT UNSIGNED`
- `overage_rate_paise`: `BIGINT UNSIGNED`
- `base_amount_paise`: `BIGINT UNSIGNED`
- `overage_amount_paise`: `BIGINT UNSIGNED`
- `subtotal_paise`: `BIGINT UNSIGNED`
- `created_at`, `updated_at`: `DATETIME / TIMESTAMP`
- **Indexes**: `[invoice_id]`

---

### 2. Approved Architectural Decisions

1. **Timezone & Timestamps**: Store and compute everything in UTC. Use `DATETIME` (not `TIMESTAMP`) for `occurred_at` in `usage_events` to avoid the year 2038 limit.
2. **Subscription Source of Truth**: `subscriptions.current_plan_id` is a denormalized shortcut for fast lookup. `subscription_segments` is the single source of truth for billing. Both are kept in sync inside `ChangePlanAction` within a single database transaction.
3. **One Active Subscription Rule**: Because MySQL lacks partial unique indexes, "at most one active subscription per customer" is enforced inside application actions within a transaction using row-level locking (`lockForUpdate`).
4. **API Key Security**: Store `merchants.api_key_hash` as a SHA-256 hash, never in plain text. Plaintext keys are revealed only once upon creation/seeding.
5. **Daily Usage Freshness & Late Events**: A scheduled job recomputes `daily_usages` for current and previous days from `usage_events` every 5 minutes using an idempotent upsert (recompute, not increment). Right before invoice generation, the system always performs a full recompute of `daily_usages` for the entire cycle from raw `usage_events`, ensuring late events for any day in the cycle are included. After invoice generation, late events for that cycle are ignored and logged.
6. **Plan Immutability**: Plans are immutable for billing purposes. Any pricing changes create a new plan row (marking the prior row inactive) so past segments and invoices are never mutated retroactively.
7. **Line Item Historical Snapshots**: `invoice_line_items` persists exact snapshots of rates, units, and prorated amounts, ensuring invoices remain permanently accurate even if plans are modified or deactivated in the future.

8. **Plan Caching & Invalidation Architecture**:
   - Version counter key (`merchant:{merchant_id}:plans_version`) is stored forever.
   - Plan data cache keys (`merchant:{merchant_id}:plan:{plan_id}:v{version}` and `merchant:{merchant_id}:active_plans:v{version}`) have a 10-minute TTL.
   - `PlanObserver` dispatches `PlanChanged` on create, update, and delete, triggering `InvalidatePlanCacheListener` to increment the version counter.
   - Note on driver atomicity: `Cache::increment` on the file driver is non-atomic and subject to race conditions under concurrent updates, but becomes fully atomic when configured to Redis via `.env`.
9. **Authentication & Rate Limiting Priority**:
   - `AuthenticateApiKey` runs ahead of `ThrottleRequests` in middleware priority, ensuring the merchant is authenticated and bound to `$request->attributes` before the rate limiter evaluates.
   - The rate limiter enforces 120 requests/minute per merchant (falling back to API key / IP) and returns a JSON 429 response accompanied by a `Retry-After` header.

---


## Phase 3 (complete) - POST /api/usage

### Endpoint
- Route: POST /api/usage ONLY. The /usage alias was removed because web.php applies CSRF/session middleware and API clients would get 419.
- Middleware: auth.api_key (X-API-Key) then throttle:api-key (120 req/min per merchant).
- Headers: X-API-Key, Idempotency-Key (required, max 100 chars), Content-Type: application/json.
- Body: customer_reference (string), units (integer, 1 to 1,000,000), occurred_at (optional ISO timestamp).
- Responses are NOT wrapped in "data" (JsonResource::withoutWrapping()). Output hides internal merchant_id and customer_id.

### Behaviour
- 201 Created: first successful call.
- 200 OK + header Idempotent-Replay: true: retry with the same Idempotency-Key and same payload. Nothing is counted twice.
- 409 Conflict: same Idempotency-Key reused with a different payload (different customer or units, or a different occurred_at if the client sent one).
- 422: missing/too-long Idempotency-Key, validation failure, occurred_at more than 5 minutes in the future, or no subscription covering the occurred_at date.
- 404: unknown customer_reference for this merchant (customers are NOT auto-created).
- 401: missing or invalid API key. 429: rate limit exceeded (with Retry-After).

### Design decisions and assumptions (use in README)
1. Idempotency uses ONE insert and catches UniqueConstraintViolationException on the unique index [merchant_id, idempotency_key]. No check-then-insert (avoids race conditions). After a collision, the existing row is loaded and compared with the new payload.
2. On replay, occurred_at is compared only if the client explicitly sent it. If omitted, only customer and units are compared. Otherwise a retry would get a new now() and wrongly return 409.
3. occurred_at is normalized to UTC in UsageEventData. config/app.php timezone is UTC. Stored as DATETIME.
4. Subscription coverage check uses dates only (starts_at <= date AND (ends_at IS NULL OR ends_at >= date)). It does NOT filter on status, so late events inside a cancelled subscription's valid period are still accepted and billed.
5. Future timestamps beyond 5 minutes are rejected (allows for clock skew). Units limit of 1,000,000 per call is a sanity guard.
6. Customer lookup uses the unique index [merchant_id, customer_reference] and selects only the id.
7. Tenant isolation: a merchant cannot post usage for another merchant's customer (treated as unknown customer, 404).

### Files
Models: Customer, Subscription, UsageEvent. Request: RecordUsageRequest. DTOs: UsageEventData, RecordUsageResult. Action: RecordUsageAction. Resource: UsageEventResource. Controller: Api/UsageController. Factories: CustomerFactory, SubscriptionFactory. Test: tests/Feature/UsageIngestionTest.php (10 tests passing: first call, retry, conflict, missing header, unknown customer, no subscription, future timestamp, tenant isolation, replay with omitted occurred_at, offset timestamp stored as UTC).

### Test setup
- Tests run on a separate MySQL database, billing_system_test, configured in .env.testing and phpunit.xml (the php build has no pdo_sqlite). Reviewers must create this empty database before running php artisan test.

---

Phase 3 complete: idempotent POST /api/usage.

## Phase 4 decisions

1. The billing calculator is a pure domain service in `app/Services/Billing/BillingCalculationService.php`; it performs no database access and accepts DTOs only.
2. Billing DTOs live under `app/DTOs/Billing` to match the existing Phase 3 `app/DTOs` convention.
3. Cycle dates are the first and last date of one calendar month, inclusive. Day counts use the actual calendar month length (28, 29, 30, or 31 days). All calculations use date-only values in `YYYY-MM-DD` format.
4. The active billing interval is the intersection of the cycle and subscription dates. Subscription start and end dates are inclusive. If there are no active dates in the cycle, return zero totals and no segments.
5. Plan segments apply on inclusive dates; a plan change is effective on its start date and usage that date belongs to the new plan. Plan segments must continuously cover the active billing interval with no gaps or overlaps; otherwise calculation fails.
6. Segment usage is the sum of `daily_usages.total_units` rows whose `usage_date` falls within that segment. Duplicate usage dates in the input are rejected.
7. For cycle days `D`, segment days `d`, segment usage `U`, plan allowance `I`, base price `B` paise, and overage rate `R` paise:
   - Allowance is the exact fraction `I * d / D`.
   - Overage units are the exact fraction `max(0, U * D - I * d) / D`.
   - Allowance and overage numerators share the denominator `days_in_cycle`; quantities are not rounded.
   - Segment base amount is `B * d / D` paise, rounded to the nearest paise using half-up rounding.
   - Segment overage amount is `R * max(0, U * D - I * d) / D` paise, rounded to the nearest paise using half-up rounding.
   - Half-up rounding is implemented using non-negative integer division and remainder only; no floating-point values/functions are used.
   - Base and overage amounts are rounded independently per segment; segment amounts are summed without further invoice-level rounding.
8. Arithmetic uses checked non-negative integer operations. Calculations that exceed the supported integer range fail explicitly.
9. The Phase 4 service, input/result DTOs, and unit tests are in:
   - `app/Services/Billing/BillingCalculationService.php`
   - `app/DTOs/Billing/BillingCalculationInput.php`
   - `app/DTOs/Billing/BillingSegmentInput.php`
   - `app/DTOs/Billing/DailyUsageInput.php`
   - `app/DTOs/Billing/BillingCalculationResult.php`
   - `app/DTOs/Billing/BillingSegmentResult.php`
   - `tests/Unit/Billing/BillingCalculationServiceTest.php`
10. Invoice persistence must be proposed separately in Phase 5: the approved schema’s integer allowance and overage unit columns cannot represent the exact fractional quantities returned by this calculator.

## Phase 5A decisions

1. Part 5A adds a new migration only; existing migrations are not edited. The migration adds `units_included_numerator` and `overage_units_numerator` to `invoice_line_items`. Exact included and overage quantities are stored as numerator divided by the existing `days_in_cycle`; existing integer unit columns remain rounded display values.
2. `CreateSubscriptionAction` locks the customer, enforces at most one overlapping active subscription, verifies the plan is active and belongs to the same merchant, then creates the subscription and its first open segment in one transaction.
3. `ChangePlanAction` locks the subscription and open segment; the effective date must be strictly after the open segment start, cannot be in an already-invoiced cycle, and cannot be in the future. A valid change closes the old segment on the previous day, opens the new segment on the effective date, and updates `current_plan_id` in one transaction.
4. `AggregateDailyUsageJob` uses the database queue, with three attempts and a backoff of 60, 300, and 900 seconds. It selects customers for a usage date in chunks of 5,000 and issues one `INSERT ... SELECT ... GROUP BY ... ON DUPLICATE KEY UPDATE` recompute per customer batch; existing totals are replaced, never incremented.
5. Aggregation excludes a usage date for a customer if that date falls inside a cycle for which one of the customer's subscriptions already has an invoice. Excluded late events are counted and logged by the aggregation job. The usage-ingestion endpoint does not query invoices and does not log late events.
6. A scheduler callback dispatches aggregation jobs for the current and previous UTC calendar dates every five minutes and uses `withoutOverlapping()`.
7. The existing migration `0001_01_01_000002_create_jobs_table.php` creates `jobs`, `job_batches`, and `failed_jobs`. Locally, run the database queue worker with `php artisan queue:work database` and the scheduler with `php artisan schedule:work`.
8. Phase 5A files:
   - Migration: `database/migrations/2026_10_02_000010_add_exact_unit_numerators_to_invoice_line_items.php`
   - Models: `app/Models/DailyUsage.php`, `app/Models/SubscriptionSegment.php`
   - Actions: `app/Actions/CreateSubscriptionAction.php`, `app/Actions/ChangePlanAction.php`
   - Job: `app/Jobs/AggregateDailyUsageJob.php`
   - Scheduler: `routes/console.php`
   - Tests: `tests/Feature/AggregationAndSubscriptionActionsTest.php`
9. Phase 5B invoice jobs, invoice creation/idempotency, `InvoiceGenerated`, demo seeding, and end-to-end tests are complete.
10. Add index `[occurred_at, customer_id]` to `usage_events` to support date-scoped aggregation scans and customer grouping. The additional index slightly slows usage-event inserts and increases index storage; monthly partitioning on `occurred_at` is the longer-term scaling option.
11. `AggregateDailyUsageJob` implements `ShouldBeUnique`, keyed by usage date, so overlapping duplicate jobs for the same date are not queued. It checks cheaply for any invoice cycle covering the date before running the per-event late-event count query.
12. `ChangePlanAction` rejects selecting the plan already assigned to the open segment.

## Last completed step

Phase 5A complete: exact invoice quantity numerator migration, subscription creation and plan-change actions, chunked idempotent daily usage aggregation, five-minute scheduling, and tests.

## Phase 5B decisions

1. `PrepareCycleInvoicesJob` receives a completed calendar cycle, finds subscriptions whose active dates overlap it, and dispatches `GenerateSubscriptionInvoicesJob` chunks of 100 subscriptions.
2. Each chunk job first fully recomputes `daily_usages` for that cycle scoped to the chunk's customers. It then processes each subscription independently through `GenerateSubscriptionInvoiceAction` in its own database transaction.
3. Chunk jobs catch and log each subscription's invoice failure, then continue with the remaining subscriptions. After processing all subscriptions, a chunk with failures throws an exception listing the failed subscription IDs so Laravel retries it. Already-invoiced subscriptions are skipped on retry. Both invoice jobs use three attempts and backoff `[60, 300, 900]`; the chunk job's `failed()` hook logs after final retry exhaustion.
4. Invoice generation checks for an existing subscription/cycle invoice before loading usage or calculating. It creates the invoice and every line item in one transaction. If calculation produces no billable segments (for example, the subscription has no active days in that cycle), it returns without creating an invoice. The unique key `[subscription_id, cycle_start, cycle_end]` remains the final concurrency guard; if a duplicate insert loses a race, the already-created matching invoice is treated as a no-op.
5. Invoice numbers are deterministic: `INV-{subscription_id}-{YYYYMM}`. `InvoiceGenerated` is dispatched only for a newly created invoice, after the invoice transaction has committed; existing-invoice and empty-calculation no-op paths do not dispatch it.
6. `invoice_line_items.units_included_numerator` and `overage_units_numerator` contain exact quantity numerators over `days_in_cycle`. Existing `units_included` and `overage_units` columns store whole-unit display values rounded half-up using integer division/remainder.
7. `RecomputeCycleDailyUsageAction` deletes stale daily aggregate rows only for the requested cycle/customer scope and upserts grouped raw events. It excludes rows for customer dates covered by an already-invoiced cycle, preserving billed daily aggregates.
8. The existing daily aggregation job keeps its already-invoiced-cycle exclusion and warning log for late events. Usage ingestion remains unchanged and performs no invoice lookup.
9. `routes/console.php` schedules completed-cycle invoice preparation for the first day of each month at 00:10, and continues current/previous-day aggregation every five minutes. The named schedules use `withoutOverlapping()`.
10. `DemoDataSeeder`, called by `DatabaseSeeder`, uses bulk `insertOrIgnore` for September and October 2026 usage. It creates four customers across Starter, Pro, and Growth plans; two have October usage more than 50% below September; one has a plan change effective October 2. It generates September invoices and recomputes October daily usage for dashboard data.
11. The seeder is designed to be rerunnable: merchant, plans, customers, subscription creation, usage-event keys, and invoice uniqueness avoid duplicate demo records.
12. The seeder's historical September invoice test exposed a calculator edge case: an open plan segment beginning after the billed cycle must be clipped out, not rejected for ending before its start. `BillingCalculationService` now skips valid segments whose start is after the cycle end.
13. Phase 5B files:
   - Models: `app/Models/Invoice.php`, `app/Models/InvoiceLineItem.php`
   - Actions: `app/Actions/RecomputeCycleDailyUsageAction.php`, `app/Actions/GenerateSubscriptionInvoiceAction.php`
   - Jobs: `app/Jobs/PrepareCycleInvoicesJob.php`, `app/Jobs/GenerateSubscriptionInvoicesJob.php`
   - Event: `app/Events/InvoiceGenerated.php`
   - Seeders: `database/seeders/DemoDataSeeder.php`, `database/seeders/DatabaseSeeder.php`
   - Tests: `tests/Feature/InvoiceGenerationTest.php`, `tests/Feature/DemoDataSeederTest.php`
14. Run locally with `php artisan queue:work database` and `php artisan schedule:work`.
15. Integration tests verify invoice chunk failure isolation and retry: successful subscriptions are invoiced in the first run, the chunk reports failed subscription IDs, and a later run invoices repaired subscriptions without duplicates. Tests also verify no invoice is created for a subscription with no active cycle days.

## Last completed step

Phase 5B complete: chunked cycle invoice preparation and generation, idempotent invoice persistence with after-commit event, demo billing data, and end-to-end tests.