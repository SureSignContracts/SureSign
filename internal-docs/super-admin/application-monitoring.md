# Application Monitoring (Super Admin)

`/admin/application-monitoring` gives a Super Admin a live, privacy-conscious
view of who is using SureSign right now and whether the platform's
background operations (queue, AI analyses, document generation) are healthy.

This is **application monitoring, not infrastructure observability** — it
answers "how is SureSign being used and is it working correctly," not
"is the server up." For container/CPU/disk/MySQL/Redis health, see
[production-operations.md](../../production-operations.md#monitoring).

Only Super Admin can see this page and its API — regular Admins and Client
users receive a 403 (`GET /api/admin/application-monitoring` sits in the same
tightened `role:Super Admin` route group as user management, not the broader
`Super Admin|Admin` group most of `/admin/*` uses).

## What it shows

- **Live**: users online now, active organizations now, authenticated
  activity in the last 15 minutes, pending/failed queue jobs, AI analyses
  pending/processing.
- **Today**: active users, logins, application actions, AI analyses
  started/completed/failed, documents uploaded/generated.
- **Rolling periods**: DAU, WAU, MAU, and a 7-day active-user trend.
- **Module usage**: which parts of SureSign (Projects, Contracts, Payment
  Applications, etc.) are used most, for today / last 7 days / last 30 days —
  reported as visit counts and **active user-days**, not "unique users"; see
  [Active user-days](#active-user-days-not-unique-users) below for exactly
  what that means.
- **Online users table**: name, email, role, organization, broad active
  module, and last-active time for every currently online user.
- **Operational health**: queue status, AI analysis status (including
  analyses that appear stuck), document activity, notification counts.

## Definition of "online"

A user is **online** if the platform recorded meaningful authenticated
activity from them in the **last 5 minutes**. Presence is refreshed **at
most once every 60 seconds** per user (not on every request — see
Architecture below), and it is **not** cleared by logout. Practical
consequences:

- Closing a browser tab leaves a user showing as online for up to 5 minutes
  afterwards — there is no way to distinguish "still here" from "just closed
  the tab" without relying on logout, which the platform deliberately does
  not depend on (tabs close unexpectedly; logout is not guaranteed to fire).
- A user who is online but idle-reading a page without triggering a new
  request may still show a "last active" time that's a minute or two old,
  because of the 60-second refresh throttle.
- Health checks, static assets, and known polling endpoints (notifications,
  the monitoring page itself) never count as activity — see
  [Module Usage](#module-usage-and-the-module-key-resolver) below for the
  exact exclusion list. A Super Admin leaving this page open does not
  inflate its own numbers.

## DAU / WAU / MAU definitions

- **DAU** — distinct authenticated users active since the start of the
  current platform day.
- **WAU** — distinct authenticated users active in the last 7 days
  (inclusive of today).
- **MAU** — distinct authenticated users active in the last 30 days
  (inclusive of today).

All three come from the `daily_active_users` table (one row per user per
calendar day — see Architecture), not from live presence, since presence
only reflects the last 5 minutes and cannot answer "was this user active at
some point today."

"Today"/"this week"/"this month" boundaries always use the **platform
default timezone** via `TimezoneResolver` (this is a cross-organization
view, not scoped to any one organization's timezone) — never the server's
own UTC calendar day.

## Active user-days (not "unique users")

Module usage's second number (alongside total visits) is labeled **active
user-days**, deliberately not "unique users" — this is the sum of each
day's distinct-user count over the selected period, not a true
period-distinct count.

**Example**: if James is the only person to use Contracts, and he uses it on
Monday, Tuesday, and Wednesday, the true number of distinct people who used
Contracts that period is **1** — but active user-days for that period is
**3**, because James contributes once per day he was active, not once per
period.

This is a direct, honest consequence of the storage model: only daily
aggregates are retained (`module_usage_daily`, one row per
organization/module/day), with no per-user historical identity kept beyond
a short-lived Redis dedup key — so there is no way to deduplicate a user
across multiple days after the fact. For a single-day period (`today`),
active user-days and true distinct users happen to be the same number,
since there's only one day to sum; they diverge for `last_7_days` and
`last_30_days`.

This is a deliberate terminology choice, not a bug — the underlying
`module_usage_daily.unique_users` column and its daily-scoped meaning are
correct and unchanged; only the field name exposed for multi-day sums
(`active_user_days` in the API) was corrected to avoid implying a
period-distinct count it cannot accurately provide. The API also returns
`module_usage.active_user_days_definition`, a plain-language string with
this same explanation, and the Super Admin UI shows it as help text next to
the Module Usage card.

## Architecture

### Presence (Redis, no database writes)

`App\Services\Monitoring\UserPresenceService` tracks presence entirely in
Redis:

- `monitoring:presence:index` — a sorted set (member: user id, score: unix
  timestamp of last activity). This is the only structure ever scanned to
  answer "who is online" — no `KEYS`/`SCAN` over many small per-user keys.
- `monitoring:presence:data` — a hash (field: user id, value: a small JSON
  payload) holding exactly `user_id, name, email, role, organization_id,
  organization_name, module_key, last_active_at`. Nothing else — no tokens,
  session ids, IP addresses, or request data of any kind.
- `monitoring:presence:throttle:{user_id}` — a 60-second key that gates how
  often the above two structures are written, so a user browsing normally
  updates presence at most once a minute, not once per request.

Stale entries (older than 20 minutes) are pruned lazily on read, and the
sorted set is also used to answer "authenticated activity in the last 15
minutes" via `ZCOUNT`, without a separate structure.

**If Redis is unavailable**, `UserPresenceService` catches every failure and
reports presence as `available: false`. The monitoring page then shows
"presence unavailable" rather than "zero users online" — those are
different facts, and the page is explicit about which one it means. Normal
application requests are never affected by a Redis outage; the tracking
middleware is entirely try/catch-wrapped.

### Module usage and the module-key resolver

`App\Services\Monitoring\ModuleUsageResolver` turns a request path into one
of a small, stable set of module keys (`dashboard`, `projects`, `contracts`,
`payment_applications`, …) — never a raw URL, record id, or query string.
Numeric path segments (record ids) are skipped automatically, so
`/projects/42/contracts` and `/projects/9001/contracts` both resolve to
`contracts`. Routes not yet mapped resolve to `null` and are simply not
counted (not an error) — new modules are added to the resolver's map
explicitly, so historical rows are never reinterpreted.

The following are explicitly excluded (resolve to `null`) and therefore
never affect presence or module usage: health checks (`/up`), notification
polling, `auth/me` session checks, and the monitoring endpoint itself.

Module usage is written to `module_usage_daily` (one row per
organization/module/day):

- A **Redis throttle key** (`monitoring:usage:throttle:{user}:{module}`,
  5-minute TTL) means a visit to the same module by the same user only
  reaches the database once every 5 minutes, not once per request.
- A separate **Redis dedup key**
  (`monitoring:usage:unique:{date}:{module}:{org}:{user}`, ~36h TTL) decides
  whether this user has already been counted in the row's `unique_users`
  column for that module/day — a user contributes to `unique_users` at most
  once per module/day, but can contribute to `total_visits` repeatedly
  (subject to the 5-minute throttle). The column is genuinely a per-day
  unique-user count; it's only when summed across multiple days (for the
  `last_7_days`/`last_30_days` API views) that it stops being a true
  distinct-user count — see [Active user-days](#active-user-days-not-unique-users)
  above, which is why the API exposes that summed figure as
  `active_user_days`, not `unique_users`.
- The actual database write is a single atomic
  `INSERT ... ON DUPLICATE KEY UPDATE` (MySQL/MariaDB) — never a
  read-then-write — so concurrent requests across multiple backend
  replicas cannot race into a duplicate or lost update.

**There is deliberately no platform-wide row** in `module_usage_daily` —
only per-organization rows. Platform totals are derived by summing across
organizations at read time. This was a specific decision to avoid MySQL's
"multiple NULLs are distinct in a unique index" behaviour, which would have
allowed duplicate platform-wide rows to slip past the unique constraint.

### DAU/WAU/MAU marker

`daily_active_users` (one row per user per calendar day, written at most
once per user per day — gated by its own Redis dedup key, independent of
the per-module throttle above) is the durable source for DAU/WAU/MAU. It is
intentionally separate from `module_usage_daily` (which aggregates per
module, not per user across modules) and from `activity_logs` (which only
records specific business actions like "contract created," not general page
usage).

### Aggregation

`App\Services\Monitoring\ApplicationMonitoringService` assembles the full
summary from the above plus existing tables (`activity_logs`, `jobs`,
`failed_jobs`, `contract_ai_analyses`, `trade_package_ai_analyses`,
`file_uploads`, `documents`, `suresign_notifications`). Every section is
independently wrapped: if one data source fails or is unavailable, it is
reported as such (`warnings`, `unavailable_sources`) rather than making the
whole page — or the underlying request — fail.

The summary is cached for **30 seconds** (a plain, driver-agnostic
`Cache::remember`, unrelated to the Redis-only presence/usage tracking
above) so a page full of polling widgets doesn't re-run every aggregate
query on every request.

## Known limitations

- **`contract_ai_analyses` has no per-state timestamps** beyond
  `created_at`/`updated_at` (unlike `trade_package_ai_analyses`, which has
  `started_at`/`completed_at`/`confirmed_at`/`cancelled_at`). Its "completed
  today"/"failed today" figures use `updated_at`, which can be imprecise if
  a record changes state more than once in a day. The monitoring API
  surfaces this explicitly via `ai.timestamp_limitation` rather than
  presenting false precision.
- **Presence is a 5-minute approximation**, not a real-time
  connected/disconnected signal — see [Definition of
  "online"](#definition-of-online) above.
- **Module usage's "active user-days" for `last_7_days`/`last_30_days` is
  not a true distinct-user count** — see [Active user-days](#active-user-days-not-unique-users)
  above for the full explanation and a worked example.
- **Module usage only covers routes explicitly mapped** in
  `ModuleUsageResolver`. An unmapped route silently contributes nothing
  rather than fragmenting into a low-value catch-all bucket; extend the
  resolver's map as new modules are added.
- **`module_usage_daily` and `daily_active_users` have no automatic
  retention/cleanup yet.** Both are small (one row per
  organization/module/day, and one row per user/day, respectively), so
  unbounded growth is expected to be very slow — a retention or archival
  policy can be added later if that changes, but nothing destructive runs
  automatically today.

## Refresh behavior

The page polls the summary endpoint every 60 seconds via React Query, which
automatically pauses while the browser tab is hidden. A manual refresh
button is always available. Module usage and the active-user trend are part
of the same 30-second-cached summary response, so there is no separate
polling loop for them.

## Privacy boundaries

The online-users table shows only name, email, role, organization, broad
active module, and last-active time — never authentication tokens, session
ids, full IP addresses, request payloads, AI prompts/responses, or document
contents. This is deliberately not a surveillance tool: there is no
click-by-click history or per-page timeline, only broad module-level usage
aggregated by day.
