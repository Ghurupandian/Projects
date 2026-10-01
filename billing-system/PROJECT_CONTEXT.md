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