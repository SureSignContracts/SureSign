# Google Integration Foundation

Platform-level, not Consultancy-specific. Owned independently of every
commercial module — the Google connection this documents exists so that
**any** future SureSign workflow (Consultancy live booking, Book a Demo,
a future project-calendar feature) can depend on one shared, reusable
Google Calendar/Meet capability, rather than each module building its own
OAuth integration. See `CLAUDE.md`'s Consultancy Live Booking Upgrade
section for how this fits into that programme's overall stage sequence
(Stage 4A of that upgrade).

## Stage 4A — Connection Foundation (built)

Approved following the Consultancy Live Booking Upgrade's Stage 3 (Stripe
Checkout). Stage 4A builds the complete OAuth connection lifecycle,
encrypted token storage, lazy refresh, a real multi-state health model,
Admin diagnostics/connect/disconnect/reconnect/test-connection, and a
readiness service future automation must depend on. **No calendar event,
Google Meet link, invitation, reminder, or calendar synchronisation exists
anywhere in Stage 4A** — those are Stage 4B's own addition, once
Consultancy (or another module) is a real caller.

### Architecture confirmation (written and approved before implementation)

**Ownership**: the Google connection is a platform concern, not a
Consultancy one — `App\Models\GoogleConnection` (table
`google_connections`) has no `consultancy_*` foreign key or namespace
anywhere. `App\Services\Google\*` and `App\Services\Calendar\*` are
top-level service namespaces, siblings of `App\Services\Billing\*`, not
nested under Consultancy.

**Provider architecture — two interfaces, one Google class, one lower
seam.** Two business-shaped interfaces:

- `App\Services\Calendar\CalendarProviderInterface` — `isConnected()`,
  `testConnection()`. Stage 4B will add event create/update/cancel methods
  here once a real caller exists.
- `App\Services\Calendar\MeetingProviderInterface` — `supportsMeetGeneration()`.

Both are implemented by **one** class, `App\Services\Calendar\GoogleCalendarProvider`
— correct specifically for Google (Google Meet is created via the Calendar
API's `conferenceData` field, not a separate API), not a general
architectural shortcut. A future non-Google provider implementing only one
of the two interfaces remains fully possible without any change to either
interface.

Beneath both sits a third, lower-level seam introduced specifically for
Google's OAuth flow:

- `App\Services\Google\GoogleApiClientInterface` — the ONE place any real
  HTTP call to Google is made: `buildAuthorizationUrl()`,
  `exchangeAuthorizationCode()`, `decodeIdToken()`, `refreshAccessToken()`,
  `revokeToken()`, `listPrimaryCalendarEvents()`. `App\Services\Google\GoogleClientAdapter`
  is the real implementation (the only class in this codebase that
  constructs a `\Google\Client`). `App\Services\Google\FakeGoogleApiClient`
  is the deterministic test fake, mirroring `FakeBillingProvider`'s exact
  conventions (public arrays/toggles a test manipulates directly, no
  network calls, no wall-clock reliance).

This is a slightly lower-level seam than `BillingProviderInterface`'s
business-shaped methods, because Google's flow has a genuine OAuth
token-exchange step Stripe's server-side API-key model doesn't — the token
lifecycle itself (not just "create an object on the provider") had to be
mockable. Every other Google service (`GoogleOAuthService`,
`GoogleTokenRefreshService`, `GoogleCalendarProvider`) depends on
`GoogleApiClientInterface`, never on `\Google\Client`/`\Google\Service\Calendar`
directly — enforced by `App\Providers\GoogleServiceProvider`, which binds
`GoogleApiClientInterface` to `FakeGoogleApiClient` whenever
`app()->environment('testing')` is true, and to `GoogleClientAdapter`
otherwise (the same environment-based, boot-time-fixed pattern
`BillingServiceProvider` uses for Stripe). **No automated test in this
codebase can make a real HTTP call to Google.**

**Connection storage — a dedicated table, not `suresign_settings`.**
`google_connections` exists because a connection carries multiple
encrypted secrets, a granted-scope list, and health/lifecycle metadata — a
real row shape, not a flat configuration value like the Anthropic/Brevo
API keys already stored in `suresign_settings`. `provider`/`purpose`
columns exist specifically so a future second connection (a different
Google account, a different consultant, or an entirely different provider
such as Microsoft 365) is simply a new row — never a column addition or a
schema redesign. Disconnecting never deletes a row; it marks it
`disconnected` and clears its live secrets, so the table doubles as its
own connection history. Only one row is ever active at a time in Stage 4A
(`GoogleConnectionService::current()` always resolves the most recent row
with `status = 'connected'` for `provider = 'google'`/`purpose = 'primary'`),
but the schema does not assume that.

**The official Google client — `google/apiclient` (composer, `^2.18`,
resolved to `2.19.4` + `google/apiclient-services` `0.452.0` + `google/auth`
+ `firebase/php-jwt`).** Documented as architecturally equivalent in
significance to `stripe/stripe-php` — the first Google SDK dependency in
this codebase. `composer audit` was run after installation and reported no
new vulnerabilities introduced.

**OAuth requirements.**

- Scopes requested: `https://www.googleapis.com/auth/calendar.events`
  (the only scope `App\Support\Google\GoogleScopes::REQUIRED` lists) plus
  `openid` and `email`. The latter two are a deliberate, narrow, documented
  addition beyond a single-scope position stated during planning — Google
  does not include `sub`/`email` claims in the ID token unless those
  scopes are also requested, and the approved diagnostics requirement
  ("connected account", "connected email") needs them. `openid`/`email`
  grant no calendar/file/contact access of any kind — this is not silent
  scope creep.
- `access_type=offline` and `prompt=consent` are set unconditionally on
  every authorization URL (`GoogleClientAdapter::baseClient()`) — Google
  only issues a `refresh_token` on the very first consent for a given
  client+account pair otherwise, which would silently break reconnection
  without `prompt=consent` forcing re-issuance every time.
- CSRF/replay protection: `GoogleOAuthService::buildAuthorizationUrl()`
  generates a random `state` value and stores it via `Cache::put()` (not
  the PHP session — this API is a Bearer-token SPA backend,
  `withCredentials: false`, not cookie/session-based) for a bounded 10
  minute TTL. `completeConnection()` consumes it via `Cache::pull()` — an
  atomic get-and-delete — so a replayed or duplicated callback finds
  nothing on a second attempt and is rejected outright (proven by
  `test_state_cannot_be_replayed`).
- Redirect pattern: `config('google.redirect_uri')` (env
  `GOOGLE_OAUTH_REDIRECT_URI`) is a **frontend** URL
  (`/admin/google/callback`), mirroring the existing
  `billing.checkout_success_url`/`consultancy.checkout_success_url`
  convention exactly. Google redirects the bare browser there; that
  already-authenticated page then calls
  `POST /admin/google/oauth/callback` with the returned `code`/`state` —
  Google never hits an unauthenticated backend route directly.

**Token lifecycle.**

- `access_token`/`refresh_token` are stored via Laravel's native
  `encrypted` Eloquent cast on `GoogleConnection` — the same mechanism
  already used for `suresign_settings.brevo_api_key`/`anthropic_api_key`.
  No new encryption mechanism was introduced (proven by
  `test_access_and_refresh_tokens_are_encrypted_at_rest`, which reads the
  raw database row directly and confirms it never matches the plaintext
  value).
- Refresh is **lazy** — `App\Services\Google\GoogleTokenRefreshService::ensureFreshAccessToken()`
  only attempts a refresh immediately before an actual outbound Google API
  call needs a token, and only when the stored token is at or past its
  recorded expiry. It is never scheduled and never runs on every request.
  Reading stored diagnostics for the Admin page never triggers a refresh
  or any live Google call — `GoogleHealthService` reports the last-known
  state instead.
- Repeated refresh failures increment `consecutive_refresh_failures`; at
  `App\Support\Google\GoogleConnectionHealth::REFRESH_FAILURE_THRESHOLD`
  (3), the connection's health becomes `refresh_failed` rather than
  retrying indefinitely. A subsequent successful refresh resets the
  counter to 0 and records an `google.refresh_recovered` Activity Log
  entry. There is no background retry loop to run away in the first
  place — a refresh only ever happens when a real caller genuinely needs
  a fresh token.

**Health model — a real multi-state model, never a boolean.**
`App\Support\Google\GoogleConnectionHealth`: `not_connected`, `connected`
(connected, but never yet verified by a real API call — deliberately not
assumed healthy), `token_expired`, `refresh_failed`, `permissions_missing`
(the actually-granted scopes, as returned by Google, are compared against
`GoogleScopes::REQUIRED` — Google may grant a narrower set than requested),
`calendar_unavailable`, `healthy`. `GoogleHealthService::currentHealth()`
checks these in a fixed priority order (not_connected > refresh_failed >
permissions_missing > calendar_unavailable > token_expired > healthy,
falling back to `connected`) and never makes a live Google call itself.

**Diagnostics and readiness.**

- `GET /admin/google/diagnostics` (`role:Super Admin|Admin` — matches the
  `ai-telemetry`/`ai-credits` read-only precedent, since both roles are
  platform-wide, not customer-org scoped, in this codebase's role model)
  returns connection identity (never raw tokens —
  `App\Support\Google\GoogleConnectionPresenter::diagnostics()` is the
  single place a `GoogleConnection` is shaped for a response), health
  state, and readiness. Never triggers a live Google call.
- `POST /admin/google/oauth/connect`, `/oauth/callback`, `/disconnect`,
  `/test-connection` are all `role:Super Admin` ONLY (with the
  `throttle:30,1` precedent from `AiCreditsGrantController`) — every
  mutating or live-call action is gated more tightly than the read-only
  diagnostics, mirroring the AI Credits grant/adjust/expire precedent for
  high-consequence platform actions.
- `App\Services\Google\GoogleIntegrationReadinessService::check()` is the
  single authoritative answer to "may downstream Google automation execute
  right now" — `{connected, healthy, health_state, meet_available, ready}`.
  Future Consultancy (Stage 4B) automation must depend on this service
  rather than checking connection state directly, mirroring
  `ConsultancyBookingReadinessService`'s identical role for Consultancy's
  own configuration readiness. Cheap and read-only — reads
  `GoogleHealthService`'s cached-state computation, never makes a live
  call.

**Security.** Access/refresh tokens are never logged anywhere — every
Activity Log entry (`google.connected`, `google.disconnected`,
`google.refresh_recovered`, `google.refresh_failed`) records only
non-secret metadata (connected email, granted scopes), proven by
`test_activity_log_never_contains_raw_tokens`, which asserts the raw
secret values submitted in a test never appear in any persisted Activity
Log row. `GoogleCalendarProvider::classifyFailure()` stores only a coarse,
safe classification string (`refresh_failed: ...`, `calendar_unavailable: ...`,
`permissions_missing: ...`, `unknown_error: ...`) — never the raw exception
message from Google verbatim into a column an Admin diagnostics page
displays without review.

### Frontend

- `frontend/src/app/admin/google-integration/page.tsx` — the Admin Google
  Integration page (nav: System → Google Integration, visible to both
  Super Admin and Admin, matching the "AI Usage & Cost" precedent).
  Connection status, health badge, diagnostics fields, and readiness
  checklist are visible to both roles; Connect/Reconnect/Test
  Connection/Disconnect buttons are rendered only for a Super Admin
  (mirroring `ConsultancySettingsPage`'s identical `isSuperAdmin` gate
  pattern), with a confirmation dialog before disconnecting.
- `frontend/src/app/admin/google/callback/page.tsx` — the OAuth callback
  landing page `config('google.redirect_uri')` points to. Reads `code`/
  `state`/`error` from the URL, makes the authenticated
  `POST /admin/google/oauth/callback` call, and redirects back to the
  Google Integration page on success.

### Testing — what was verified, and under what

**Automated (SQLite + `FakeGoogleApiClient`/`FakeCalendarProvider`, this
environment)**: 36 tests in
`tests/Feature/GoogleIntegrationFoundationTest.php` covering the OAuth
lifecycle (authorization URL construction, successful exchange with ID
token claims, unknown/replayed state rejection, rejected authorization
codes, reconnection superseding a previous connection and clearing its
secrets), encrypted-at-rest token storage, lazy refresh (valid-token
short-circuit, successful refresh, missing-refresh-token failure,
threshold-crossing repeated failure, recovery after failure), disconnect
(token clearing, best-effort revoke including revoke-failure tolerance,
history preservation, no-op when nothing is connected), every one of the
seven health states (including priority ordering between refresh-failed
and token-expired), `GoogleCalendarProvider::testConnection()`'s own logic
exercised directly (not just via the fake) against `FakeGoogleApiClient`
for both success and failure outcomes, the readiness service in both the
not-ready and ready cases, diagnostics-endpoint authorization for all
three roles (Super Admin, Admin, Client), mutating-endpoint rejection for
Admin, the full HTTP-level connect/callback/disconnect flow, and the
Activity Log secret-exclusion guarantee. Full backend regression: 2117
tests, 2056 passing, 4 failing — all 4 pre-existing and unrelated to
Google Integration (confirmed by re-running them in isolation and by
inspecting the failures directly: two are `storage/app` not existing at
all in this sandbox — an Adjudication-document-upload and an Excel
generation test both fail on a Flysystem "unable to create directory"
error that has nothing to do with this stage — and two are
`ReportsCommercialSummaryReportTest` cases unrelated to any file touched
in Stage 4A).

**Real Google OAuth/Calendar API — NOT validated in this environment** (no
Google Cloud OAuth client credentials configured, no live Google account
available here). This proves the OAuth/token/health STATE MACHINE logic is
correct against a faithful deterministic fake; it does NOT and cannot
prove that `GoogleClientAdapter`'s real `google/apiclient` SDK calls behave
identically against live Google. Before production activation, a manual
procedure should be run once a real Google Cloud OAuth 2.0 client (Web
application type, with `GOOGLE_OAUTH_REDIRECT_URI` registered as an
authorized redirect URI) exists:

1. **Consent screen and scopes** — confirm the real Google consent screen
   requests exactly `calendar.events`/`openid`/`email` and no more, and
   that declining still redirects back with an `error` parameter this
   codebase's callback page handles gracefully.
2. **Token exchange** — confirm a real authorization code exchanges
   successfully, that a `refresh_token` is present (both on first consent
   and, thanks to `prompt=consent`, on every subsequent reconnection), and
   that the ID token's `sub`/`email` claims populate `google_account_id`/
   `connected_email` correctly.
3. **Real refresh** — force (or wait for) a real token expiry and confirm
   `GoogleTokenRefreshService` successfully refreshes against live Google,
   updating `token_expires_at` correctly.
4. **Real revoke** — disconnect and confirm the token is genuinely revoked
   at Google (e.g. via the connected Google Account's "Third-party apps
   with account access" page no longer listing SureSign, or a subsequent
   API call with the old token failing).
5. **Real Calendar read** — with a connected account that has at least one
   primary-calendar event, confirm `testConnection()` reports `healthy:
   true` with a real latency figure, and that a 404/403 from a
   deliberately misconfigured/restricted account is classified correctly
   by `GoogleCalendarProvider::classifyFailure()`.
6. **Scope narrowing** — approve a narrower scope set than requested (if
   Google's consent screen allows partial grant for this scope
   combination) and confirm `permissions_missing` health state and
   `missing_scopes` are reported correctly.

Do not treat any of the above as performed until it is actually run against
a real Google Cloud OAuth client and a real Google account.

### Known limitations / deliberately deferred

- **Only the primary calendar** is supported — `listPrimaryCalendarEvents()`
  and any future Stage 4B event-creation method operate against `'primary'`
  only. A selectable, non-primary calendar is a documented future
  expansion, not built.
- **Only one active connection at a time** — `provider`/`purpose` exist to
  support a future second connection (a different account, a different
  provider), but no UI, resolver, or business logic anywhere selects
  between multiple simultaneously-connected rows today.
- **No scheduled health check or alerting** — health is computed on
  demand (diagnostics read, or an explicit Test Connection action). A
  connection could sit in `token_expired`/`refresh_failed` for an
  extended period without anyone noticing, short of visiting the Admin
  page. Deliberately not built in Stage 4A — would require an invented
  polling cadence with no real caller depending on it yet.
- **No calendar event, Google Meet link, invitation, reminder, or
  calendar synchronisation** — all Stage 4B, deliberately not started.

### A documented, not implemented, future generalisation

A future `ExternalConnection` concept — a provider-agnostic connection
model generalising what `GoogleConnection` does today, so a future
Microsoft 365, Zoom, Slack, or Dropbox integration doesn't need its own
bespoke `{provider}_connections` table and OAuth-lifecycle service from
scratch — is worth designing **when a second real provider integration is
actually being built**, not now. Building it speculatively today, with
only one real provider to generalise from, risks guessing wrong about
which parts of Google's OAuth shape are universal (offline access,
refresh tokens, ID token claims) versus Google-specific, and would add
indirection with no second caller to justify it. `GoogleConnection` is
deliberately a clean, single-provider domain model in Stage 4A; this note
exists so that decision is revisited deliberately, not forgotten.

## Stage 4B.1 — Google Calendar Event Synchronisation (built)

Approved following Stage 4A verification and the Consultancy Live Booking
Activation Hardening pass. Builds real Calendar-event creation for a
confirmed Consultancy Appointment, with reconciliation, bounded retry,
scheduled recovery, and Admin diagnostics — Appointment remains the sole
source of truth throughout; Google Calendar is an external representation
only, and Google failure never rolls back payment, Appointment creation,
or reservation consumption. **Google Meet, event update/deletion,
cancellation/rescheduling synchronisation, reminders, and customer
communications remain entirely unbuilt** — Meet is planned as Stage 4B.2
using this same architecture.

### Domain ownership

`App\Models\AppointmentExternalSync` (table `appointment_external_syncs`)
is a new, provider-neutral record of one Appointment's external-
representation lifecycle — it owns synchronisation state only.
`App\Models\Appointment::isEligibleForExternalSync()` (added during
Activation Hardening, unchanged here) is the single eligibility gate every
sync pass consults. Google never owns Appointment state — a `synced` sync
row and a `cancelled` Appointment can both be true at once (see
"Cancellation boundary" below), and the two facts are always shown
independently, never collapsed into one.

### Data model

`appointment_external_syncs`: `appointment_id` (FK, `restrictOnDelete`),
`google_connection_id` (FK, nullable, `nullOnDelete`), `provider`
(default `'google'`), `external_resource_type` (default
`'calendar_event'`), `state`, `provider_event_id`, `correlation_key`
(unique, 40-char random), `payload_version`/`payload_hash`,
`attempt_count`, `processing_started_at`/`last_attempted_at`/
`last_success_at`/`next_retry_at`, `failure_category`/`failure_message`,
`outcome_uncertain`. Unique constraints: `(appointment_id, provider,
external_resource_type)`, `(correlation_key)`, `(provider,
provider_event_id)` (MySQL permits multiple `NULL`s, so this only enforces
uniqueness once a real event ID is known). Indexes on `state` and
`next_retry_at` back the reconciliation command's queries.

Building this migration surfaced the SAME class of interrupted-multi-
statement issue documented for
`2026_08_16_000001_add_context_to_appointment_availability_tables.php`
above — a `Schema::create()` blueprint with several `unique()`/`index()`
calls compiles to a SEQUENCE of separate ALTER statements after the
initial CREATE TABLE, not one atomic statement, so an interrupted run can
leave a table with only some of its constraints. This migration is
written defensively (every step guarded by `Schema::hasTable()`/
`Schema::hasIndex()`) for exactly that reason. One auto-generated index
name also exceeded MySQL's 64-character limit (the same recurring class of
bug documented elsewhere in this codebase for
`billing_entitlement_snapshots`) and was given an explicit short name.

### State machine

`pending → processing → {synced | retry_pending | failed | manual_review
| disconnected | cancelled}` — see `App\Support\Google\CalendarSyncState`
for the full constant list, the transition table, and why each state is
distinct (never a boolean):

- **synced** — a Calendar event is confirmed to exist. Remains true even
  after the local Appointment is later cancelled (approved correction 5) —
  this is a statement about external reality, not a customer-facing "is
  this booking active" flag.
- **retry_pending** — a recoverable failure, automatic retry still within
  budget (`CalendarSyncState::MAX_RECOVERABLE_ATTEMPTS = 4`, backoff `[5,
  15, 60, 240]` minutes).
- **failed** — the recoverable retry budget is exhausted; terminal until
  an explicit Admin retry.
- **manual_review** — a configuration failure (missing scopes, calendar
  access denied, a rejected/malformed request) or multiple correlation
  matches found during reconciliation — never auto-retried.
- **disconnected** — Google integration itself isn't connected/healthy;
  auto-recoverable the moment readiness improves (every reconciliation
  tick re-checks readiness before attempting again).
- **cancelled** — the Appointment became ineligible before any Calendar
  event was confirmed to exist.

### Failure classification

`App\Support\Google\CalendarSyncFailureCategory` — normalised categories
(`transport_uncertain`, `provider_server_error`, `rate_limited`,
`calendar_temporarily_unavailable`, `calendar_access_denied`,
`permissions_missing`, `disconnected`, `rejected_request`,
`ambiguous_reconciliation`) resolved by `App\Services\Calendar\GoogleCalendarProvider`
from the real exception type/HTTP status (`Google\Service\Exception`'s
code, or a transport-level exception with no response at all) —
`App\Services\Calendar\AppointmentCalendarSyncService` never parses a raw
exception message to decide a state transition, only ever the category.

**Approved correction 1**: a definitive 4xx (validation/malformed
request, category `rejected_request`) is the only response specific
enough to prove no event was created. A Google 5xx
(`provider_server_error`) is NOT treated that way — a gateway may have
processed the write before failing on the response leg — so it's
classified alongside `transport_uncertain` (no response at all) as
genuinely **uncertain**, requiring reconciliation before the next create
attempt. `rate_limited` (429) and `calendar_temporarily_unavailable` (a
readiness-driven pre-check skip, not a live API response) are both
definitive-enough to retry directly without reconciling first.

### Retry ownership (approved correction 2)

The sync row owns business retry scheduling — a classified failure is
always persisted as a state (`retry_pending`/`failed`/`manual_review`/
`disconnected`) and `AppointmentCalendarSyncService::attempt()` returns
normally, no exception. `App\Jobs\SyncAppointmentCalendarEventJob`'s own
`$tries=3`/`backoff=[10,60]` are reserved for a genuinely UNCLASSIFIED
failure escaping the service (a database error, an unexpected bug) — an
ordinary provider failure never rethrows to consume a queue-level retry
attempt. `attempt_count` increments exactly once per `attempt()`/
`process()` pass that reaches a real provider call (reconciliation lookup
and/or create), never per queue delivery.

### Correlation and reconciliation

A random 40-character `correlation_key` (same primitive as
`GoogleOAuthService`'s OAuth `state`) is generated once, before any Google
call, and written into the Calendar event's
`extendedProperties.private.suresign_correlation_key` — private extended
properties are never shown to attendees. `outcome_uncertain` is set `true`
immediately before every create call and cleared once a definitive
outcome is known (success, or a clean 4xx). While `true`, the next attempt
calls `findEventByCorrelationKey()` (Google's `privateExtendedProperty`
list filter, scoped to the primary calendar) **before** ever calling
create again: zero matches → safe to create; exactly one match → adopted,
marked `synced`; more than one match → `manual_review`, never a third
create.

**Approved correction 5**: if reconciliation runs after the local
Appointment has already been cancelled and finds a real, pre-existing
event, the sync row becomes `synced` — not `cancelled` — because that's
externally true. The Google event is never updated or deleted (deferred
to a later lifecycle stage); Admin diagnostics show `state: synced` and
`appointment_cancelled: true` as two independent facts.

### Provider interface changes

`CalendarProviderInterface` gained `createEvent(array $payload): array`
and `findEventByCorrelationKey(string $key): array` — no update, delete,
cancellation, or Meet method exists. `GoogleApiClientInterface` gained the
two thin, API-level operations these call
(`insertPrimaryCalendarEvent()`/`listPrimaryCalendarEventsByPrivateProperty()`).
`sendUpdates` is fixed to `'none'` inside `GoogleCalendarProvider` itself —
never a caller-supplied value — a deliberate decision (see "Invitation
policy" below). Organiser-duplication (the connected account's own email
never added as a separate attendee) is resolved in `GoogleCalendarProvider::createEvent()`,
the one place that already holds both the payload's attendee list and the
connection's own identity — not in the payload factory, which only knows
Appointment data.

### Payload factory (approved correction 4)

`App\Services\Calendar\ConsultancyAppointmentCalendarEventPayloadFactory`
— deliberately named and scoped as Consultancy-specific, not a
speculative generic `AppointmentCalendarEventPayloadFactory`. Every
Appointment eligible for Calendar sync today is a Consultancy booking, and
the title/description wording says so explicitly (`"SureSign Consultancy —
{service}"`). A future second real caller (e.g. Book a Demo) is the
trigger to introduce genuine generality, not before. Every value
originates only from `Appointment`/`AppointmentType`/`User` — never a
request body, never `internal_notes`/`attendee_message`/any payment
field, and never `consultationEnquiry->description` verbatim (a fixed,
generated template is used instead, so this stays safe even after a
future stage that might let customers submit real free text into that
field).

### Timezone handling (approved correction 6)

`start`/`end` are generated as RFC3339 timestamps in the Appointment's
authoritative IANA `booking_timezone` — with that timezone's actual
offset, not a bare UTC `Z` — using Carbon's own `setTimezone()` +
`toRfc3339String()`, never manual offset arithmetic. The absolute UTC
instant is unchanged; only its string representation carries the local
offset, which Google's Calendar API accepts identically to a UTC
timestamp paired with a separate `timeZone` field.

### Invitation policy

`sendUpdates = 'none'` for every Calendar API call in Stage 4B.1,
unconditionally. Customer communications are explicitly deferred in this
stage — letting Google silently email customers as a side effect of
internal sync infrastructure would be an undisclosed customer-facing
behaviour change, breaking the same "customer journey stays deliberately
unwired until an explicit activation decision" principle every prior
Consultancy stage has followed. Reconciliation lookups are always
read-only (`events.list`) and can never resend an invitation regardless of
this setting.

### Readiness (approved correction resolved during architecture)

`AppointmentCalendarSyncService` reads `GoogleIntegrationReadinessService::check()['health_state']`
directly — **never** the aggregate `ready` field, which today happens to
equal `healthy && meet_available` (and `meet_available` for Google is
currently just `isConnected()`, coincidentally never blocking Calendar-
only sync today). This was a deliberate architectural decision, not a code
change to `GoogleIntegrationReadinessService` itself (frozen per Stage 4A
approval) — once Stage 4B.2 gives Meet a real, independent capability
check, Calendar-only sync must not regress by suddenly depending on it.

| `health_state` | Sync outcome |
|---|---|
| `not_connected` / `refresh_failed` | → `disconnected` |
| `permissions_missing` | → `manual_review` |
| `calendar_unavailable` | → `retry_pending` (readiness-driven skip, does not itself increment `attempt_count` — see the class docblock) |
| `token_expired` / `connected` / `healthy` | proceed (a token refresh, if needed, self-heals inside `createEvent()`) |

### Trigger and transaction boundary

`App\Services\Consultancy\ConsultancyPaymentConversionService::convert()`
— `DB::afterCommit()` registered at exactly the two branches that
genuinely produce/confirm an Appointment in that invocation (the
first-time conversion, and the "already consumed by this same payment,
finishing marking converted" resumed-retry branch) — never on the pure
early-return no-op. By the time the callback fires, payment conversion,
reservation consumption, and the Appointment itself are all committed
facts, and every row lock from that transaction has released. Duplicate
dispatch (e.g. both the webhook processor and the reconciliation command
touching the same payment) is harmless regardless, since
`AppointmentCalendarSyncService::getOrCreateSyncRow()`'s unique constraint
is the true idempotency boundary.

### Queue and scheduler

Dedicated queue: **`google-integrations`** — never `billing-webhooks`,
`consultancy-payments`, or `default`. `docker/entrypoint.sh`'s worker now
consumes `billing-webhooks,consultancy-payments,google-integrations,default`
(added after `consultancy-payments`, before `default`) — a slow/retrying
Google call must never delay billing-webhooks or consultancy-payments, and
a Google outage must never consume worker capacity those two queues need.

`appointments:calendar-sync:reconcile` (Artisan command) recovers due
`retry_pending`, `disconnected`, abandoned `processing` (lease expired,
15 minutes), and any `outcome_uncertain = true` row regardless of state —
scheduled every 5 minutes in `routes/console.php`, `withoutOverlapping()`,
`runInBackground()`, no `onOneServer()` (matches every other scheduled
command in this codebase — the row-locked claim is the actual correctness
boundary, not the scheduler). `--dry-run` supported; manual execution
remains fully safe alongside the schedule.

### Admin diagnostics

`GET /admin/google/calendar-syncs`, `GET /admin/google/calendar-syncs/{sync}`,
`POST .../retry`, `POST .../reconcile` — grouped under the existing
`admin/google` prefix, `role:Super Admin|Admin` for all four (read AND
retry/reconcile — a safe, idempotent, non-destructive action, mirroring
`ConsultancySettingsController::retryConversion()`'s risk profile, not the
stricter Super-Admin-only gate reserved for OAuth connect/disconnect
above). `App\Support\Google\CalendarSyncPresenter::admin()` is the single
shaping point — includes `provider_event_id` (Admin-only, operationally
justified) and, critically, both `state` and `appointment_cancelled` as
independent fields. No event editing, no raw provider payload, and no
customer-facing endpoint exists anywhere in this stage.

### Activity Log

`google.calendar_sync_queued`, `google.calendar_event_created`,
`google.calendar_sync_failed`, `google.calendar_sync_recovered`,
`google.calendar_sync_reconciled`, and one addition —
`google.calendar_sync_manual_review` (mirrors the existing
`consultancy.payment_manual_review_required` precedent — a distinct
operator-actionable state deserves its own audit entry). Never logs
tokens or raw provider payloads.

### Testing

`tests/Feature/GoogleCalendarSyncStage4B1Test.php` — 56 tests / 114
assertions, entirely against `FakeGoogleApiClient`/`FakeCalendarProvider`
(no real Google HTTP call is possible from any test in this codebase).
Covers dispatch/after-commit, idempotency, every state transition,
readiness mapping (including the `ready`-field-must-not-be-consulted
proof), retry classification (including the 5xx/timeout-uncertain vs.
4xx/429-definitive distinction), external-uncertainty reconciliation
(zero/one/multiple matches, including after cancellation), work-claiming/
concurrency (active vs. abandoned leases), cancellation races, payload
correctness (including organiser dedup and internal-content exclusion),
timezone handling (local offset, a second IANA zone), invitation policy,
scheduler/queue registration, and security (Client access denial, no raw
exception leakage, no token leakage). Full Stage 1–4A + Activation
Hardening regression suite re-run green (197 tests) alongside it.

### Known limitations

- **No live Google Calendar validation** — no real Google Cloud OAuth
  client or Calendar in this environment. Everything above is proven
  against faithful fakes, not live Google behaviour.
- **Single shared Google connection** — no multi-consultant routing.
- **No update/delete/cancellation sync** — a `synced` row is accurate only
  until a later stage adds that capability; a cancelled-after-sync
  Appointment leaves its Calendar event exactly as-is.
- **No customer-facing response field exists yet** — this stage is
  sync-infrastructure only.
- **Google's own native invitation is fully suppressed** — customers
  receive no calendar artefact at all until Stage 4B.2/communications work
  makes a deliberate activation decision.

### Manual production validation plan (once real Google credentials exist)

1. Configure a real Google Cloud OAuth client and connect via the Stage 4A
   Admin flow.
2. Convert a real Consultancy payment and confirm the queued job creates
   a real Calendar event on the connected account's primary calendar.
3. Inspect the created event's private extended properties (via the
   Google Calendar API directly, not the UI) to confirm the correlation
   key is present and not customer-visible.
4. Confirm no attendee invitation email is received (sendUpdates=none).
5. Force a timeout (e.g. temporarily block network access mid-call) and
   confirm the sync row lands in `retry_pending` with `outcome_uncertain =
   true`, then confirm the next scheduled reconciliation pass correctly
   finds the real event without creating a duplicate.
6. Revoke Calendar scope from the connected account and confirm the next
   attempt lands in `manual_review` with category `permissions_missing`.
7. Disconnect the Google account entirely and confirm `disconnected`,
   then reconnect and confirm the next scheduled tick recovers
   automatically.
8. Book across a DST boundary (e.g. a slot the weekend UK clocks change)
   and confirm the Calendar event's displayed local time is correct.
9. Dispatch two jobs for the same sync row deliberately (e.g. via manual
   `artisan tinker`) and confirm only one Calendar event exists.
10. Cancel an Appointment after its Calendar event was already created and
    confirm the event is left untouched in Google Calendar.

## Stage 4B.2 — Google Meet Conference Generation (built)

Approved following Stage 4B.1. Extends the SAME Calendar event
synchronisation flow — Meet is requested as part of the same
`createEvent()` call Stage 4B.1 already makes, never a second event, a
second provider call, or a second sync table/model. No Gemini notes,
transcript, recording, event update/delete, cancellation/rescheduling
sync, reminders, or customer email were built — all explicitly deferred.

### Architecture decision — Checkout is never gated on Google

**Google Calendar and Meet readiness are operational readiness signals
only. They do not gate Checkout, payment, reservation confirmation, or
Appointment creation — external-provider availability must not interrupt
payment acceptance or valid Consultancy booking creation.**
`App\Services\Consultancy\ConsultancyBookingReadinessService::checkoutAvailability()`
is unchanged by this stage and still checks only consultant/availability/
service configuration, exactly as its own Stage 1 docblock has always
said. A customer may complete a Consultancy booking even when Google is
fully disconnected — the paid booking and Appointment remain valid
regardless, and Meet delivery becomes a recoverable async concern handled
entirely by the existing external-sync machinery. This was a genuine
tension in the original Stage 4B.2 brief (which initially proposed
gating Checkout on combined Calendar+Meet readiness) — resolved explicitly
in favour of the pre-existing, repeatedly-reaffirmed principle before any
code was written.

### Calendar vs. Meet truth — two independent dimensions

`App\Support\Google\MeetConferenceState` (`not_requested`/`pending`/
`available`/`unavailable`/`failed`/`manual_review`) is a column
(`meeting_state`) on the SAME `appointment_external_syncs` row Stage 4B.1
introduced — never overloaded onto `CalendarSyncState`. A `synced`
Calendar event and a `pending`/`unavailable`/`failed` Meet conference are
simultaneously true, ordinary facts, not a contradiction. `synced` remains
an accurate statement that the external Calendar event exists — even
indefinitely, if Meet never becomes available for that particular
account/appointment.

### Data model (additive to the Stage 4B.1 table)

Migration `2026_08_21_000001_add_meet_fields_to_appointment_external_syncs_table`
added, to the EXISTING `appointment_external_syncs` table (no second
table): `meeting_state` (default `not_requested`), `provider_conference_id`,
`provider_conference_type` (e.g. `hangoutsMeet`), `meeting_join_url`
(only ever populated while `meeting_state = available`, cleared the
instant it regresses), `meeting_created_at`, `meeting_failure_category`
(a normalised category, never a raw message — no separate
`meeting_failure_message` column was added; a safe description is derived
from the category at read time instead, keeping the schema minimal).
Reuses the EXISTING `provider_event_id`/`correlation_key`/`state`/
`google_connection_id`/`appointment_id` columns — no duplicated identity
columns anywhere. Guarded with `Schema::hasColumn()`/`Schema::hasIndex()`
per this repository's established interrupted-migration handling; applied
cleanly on the first attempt in this environment.

### Readiness split — honest, not manufactured

`GoogleIntegrationReadinessService::check()` is UNCHANGED — still exactly
what `AppointmentCalendarSyncService` reads (via `health_state`). A new
`checkDetailed()` method returns `{calendar_ready, meet_ready,
google_overall_ready, blockers, warnings}` for Super Admin/Admin
diagnostics and operational monitoring ONLY — never a Checkout gate (see
above). Google exposes no independent pre-flight "can this account create
Meet" endpoint, so `meet_ready` is honestly derived from the SAME
connection health as `calendar_ready`, minus any **persisted Meet
capability blocker** — a real prior `meet_not_supported`/
`conference_creation_forbidden` result recorded on an
`appointment_external_syncs` row for the CURRENT connection, with no
later `available` result since superseding it (a query ordered by
`updated_at` then `id` as a tiebreaker, since same-second `timestamp`
columns can otherwise tie unpredictably).

**A real design bug caught by the test suite and fixed before this
stage was considered complete**: the first implementation gated whether
to even REQUEST a conference on `checkDetailed()['meet_ready']` — which,
once any appointment recorded a persistent blocker, would have made
`meet_ready` permanently false forever (nothing would ever try again to
discover the capability had recovered). Fixed by always requesting a
conference on every Calendar-ready pass regardless of the diagnostic
`meet_ready` value — the system now self-heals the instant Google's real
capability changes, and `meet_ready` stays purely a reported fact, never
a gate on the sync service's own behaviour.

### Stable conference request ID

Approved: the existing `correlation_key` (Str::random(40), alphanumeric)
IS Google's `conferenceData.createRequest.requestId` — no new column.
Verified against the `google/apiclient-services` SDK's own field
definition (a plain string, Google's documented behaviour is that a
repeated `requestId` on a retry is recognised as the same request rather
than creating a fresh conference) — this satisfies the requirement that
the ID stay stable across every retry without a live Google call to
confirm undocumented length/format limits beyond what the SDK itself
enforces (none). This specific detail remains a live-validation item (see
below) — the SDK accepting the value does not by itself prove Google's
production endpoint behaves identically.

### Provider changes

`CalendarProviderInterface::createEvent()`/`findEventByCorrelationKey()`
both gained a normalised `conference` sub-result
(`status`/`conference_id`/`conference_type`/`join_url`) — Meet-specific
problems NEVER throw here if the Calendar event itself was created; they
are reported via this sub-result instead (a null/failed `conference` is
not a Calendar failure). `GoogleApiClientInterface::insertPrimaryCalendarEvent()`
now sets `conferenceDataVersion: 1` and builds `conferenceData.createRequest`
whenever `$eventBody['request_conference']` is true —
`GoogleClientAdapter::normalizeConferenceData()` is the ONE place a raw
`Google\Service\Calendar\ConferenceData` object is read; everything past
it is a plain array. `GoogleCalendarProvider::normalizeConference()` is
the ONE place a Meet entry point's URI is inspected — `join_url` is set
ONLY when exactly one `video` entry point exists with a URI whose scheme/
host is `https://meet.google.com` (never an arbitrary provider-returned
URL). `MeetingProviderInterface` is UNCHANGED — no new method was needed;
Meet rides entirely on Calendar's own `createEvent()`.

### Payload changes

`ConsultancyAppointmentCalendarEventPayloadFactory::build()` gained one
new parameter, `$requestConference` — title/description/attendee content
is otherwise byte-for-byte unchanged from Stage 4B.1 (the description was
deliberately NOT broadened).

### Creation/reconciliation flow (extends, never replaces, Stage 4B.1's algorithm)

Meet is requested on the SAME `createEvent()` call Calendar already uses.
On success, `AppointmentCalendarSyncService::applyConferenceResult()` maps
Google's own `conferenceData.createRequest.status.statusCode` to
`meeting_state`: `success` + a trusted join URL → `available`; `success`
with no trustworthy join URL → `manual_review`/`malformed_conference_response`
(never trusted as available); `pending` → `pending`; `failure` →
`failed`/`conference_solution_unavailable`; no `conferenceData` at all →
`unavailable`/`meet_not_supported`. During reconciliation (outcome
uncertain, or an Admin-initiated reconcile), exactly the same mapping
applies to whatever the adopted event's own conference data shows — never
a second create, never a second conference request.

### Pending-conference recovery

A `synced`-Calendar/`pending`-Meet row is NOT claimable by `process()`
(`SYNCED` isn't an `AUTO_CLAIMABLE` Calendar state) — a new method,
`AppointmentCalendarSyncService::refreshPendingMeet()`, handles this
narrower case: re-reads via the same correlation-key lookup and re-applies
whatever conference result it finds, touching ONLY Meet fields, never
Calendar `state`/`provider_event_id`/`outcome_uncertain`. `SyncAppointmentCalendarEventJob`
(no new job class) routes to this method instead of `attempt()` when it
detects this exact row shape.

### Reconciliation command — one new category, same cadence

`appointments:calendar-sync:reconcile` gained exactly one new category —
`state = synced AND meeting_state = pending` — dispatched at the SAME
5-minute cadence, `withoutOverlapping()`, no `onOneServer()`, `--dry-run`
supported. No new command, no high-frequency Meet-polling loop (Google
conference generation is normally near-instant; this is a safety net).

### Cancellation boundary

Unchanged in spirit, extended in mechanics: `refreshPendingMeet()` also
checks `Appointment::isEligibleForExternalSync()` and silently declines to
keep polling Google for a cancelled booking's Meet status (respects
provider quotas). A `synced`+`available` row is never rewritten because
the Appointment was cancelled afterward — Admin diagnostics
(`CalendarSyncPresenter::admin()`) show `appointment_cancelled` and
`meeting_state` as fully independent fields.

### Customer-facing Meet link

`App\Support\Consultancy\ConsultationMeetingPresenter` — a dedicated
presenter, deliberately NOT merged into `App\Support\Consultancy\ConsultationPresenter::customerFacing()`
(shared by both `ConsultationController::index()` and `show()` — merging
would leak the link into the customer's LIST endpoint). Only `show()`
appends its result under a `meeting` key. Exposes exactly one of four
customer-safe statuses (`available`/`pending`/`temporarily_unavailable`/
`unavailable`) and a `join_url` that is non-null ONLY when `available`. A
cancelled Appointment always reports `unavailable` here regardless of the
sync row's true Meet state (no "Join" affordance is ever shown for a
cancelled consultation) — Admin diagnostics remain the place true
independent facts are visible. Never exposes provider event ID,
correlation key, conference request ID, or Google connection identity.
**Consultancy Communications Upgrade, Batch 3** reuses this exact same
presenter, unchanged, for the new public no-account "view your
consultation" page (`PublicConsultationViewController::show()`) — its
output was already customer-safe by construction (no provider
identifiers, authentication-agnostic), so no second Meet-status presenter
was needed for the public case. See `consultancy.md`'s own Batch 3
section.

Authorization is the existing, unchanged `authorizeOwnOrganization()`
check — no new authorization boundary was introduced.

### Admin diagnostics

`CalendarSyncPresenter::admin()` (same `/admin/google/calendar-syncs*`
routes, unchanged authorization) gained `meeting_state`,
`provider_conference_id`, `provider_conference_type`, `meeting_known`,
`meeting_link_known` (never the URL itself — "does a link exist," not
"what is it"), and `meeting_failure_category`. No new endpoint, no event
editing, no Meet regeneration action — retry/reconcile are the same two
existing actions, now also correctly handling the Meet dimension.

### Activity Log

`google.meet_requested`, `google.meet_available`, `google.meet_pending`,
`google.meet_failed`, `google.meet_recovered`, `google.meet_manual_review`
— logged only on a genuine `meeting_state` TRANSITION, never repeated on
an unchanged reconciliation pass. Metadata includes `has_join_url`
(boolean presence only) — never the URL itself, never a token, never a
raw provider payload.

### Testing

`tests/Feature/GoogleMeetConferenceStage4B2Test.php` — 37 tests / 90
assertions, entirely against `FakeGoogleApiClient`/`FakeCalendarProvider`
(both extended with conference simulation — success/pending/failure/no-
conference-at-all, malformed/untrusted URLs, multiple entry points, lost-
response-with-conference). Covers every category from the approved
implementation prompt: successful creation, pending conference recovery,
lost-response reconciliation, 5xx/timeout uncertainty, definitive Meet
failures, reconciliation (0/1/many matches, with/without Meet),
cancellation independence, customer-facing security (org isolation, list-
endpoint exclusion, no internal-ID leakage), retry/attempt-count
discipline (including the "Meet-only refresh never increments
attempt_count" and "unclassified failure propagates" cases), and
readiness (including the self-healing blocker-clearing behaviour, once
the design bug above was fixed). Full Stage 1–4B.1 + Activation Hardening
regression suite (234 tests) re-run green alongside it.

### Known limitations

Everything from Stage 4B.1's own list still applies. Additionally: `meet_ready`
is a best-effort derived signal, not a live Google capability check (Google
provides none); the persisted-blocker mechanism only ever learns from this
platform's own prior attempts, never from Google directly; no update/
delete/cancellation sync exists for Meet either — an `available` row stays
exactly as recorded even if the underlying Google event/conference is later
changed or removed directly in Google Calendar by a human.

### Manual production validation plan (extends Stage 4B.1's — do not repeat what's already listed there)

1. Confirm Meet readiness (`checkDetailed()`) passes for a real connected
   account.
2. Complete a real Consultancy booking end to end and confirm exactly one
   Calendar event with exactly one Meet conference is created.
3. Confirm the stored `meeting_join_url` matches the real event's actual
   Meet link exactly.
4. Confirm no Google invitation email arrives (`sendUpdates=none` holds
   under real Meet requests too).
5. If reproducible, observe a real `pending` conference response and
   confirm the next scheduled reconciliation tick correctly adopts the
   resolved link with no duplicate conference.
6. Revoke/restrict Meet creation for the test account (if the Google
   Workspace test environment allows it) and confirm `meet_not_supported`/
   `conference_creation_forbidden` classification.
7. Cancel a booking after a real Meet link exists and confirm the Google
   Calendar event/Meet conference is untouched.
8. Confirm the customer-facing Consultation detail page shows the link
   only to the correct authenticated account.

### Deferred — Google Gemini "Take notes for me" and related capabilities

Explicitly out of scope for Stage 4B.2 and not designed against: Gemini
meeting notes, Google Docs notes import, Drive API integration, transcript
upload/import, automatic meeting summaries, action-item extraction, a
construction-specific AI consultation record, or a customer PDF
consultation record. No dormant table, interface, job, or endpoint exists
for any of these — this stage leaves a clean extension path (the same
`appointment_external_syncs` row, the same provider-boundary-normalisation
discipline) without building unused infrastructure ahead of a real,
separately-approved future stage.

## Stage 4B.2 Live Validation (real Google Workspace account)

Performed after a real infrastructure defect was found and fixed: the
`queue`/`scheduler` dev containers' anonymous `vendor/` Docker volumes were
stale (missing `google/apiclient` entirely), so every real Calendar/Meet
call made from a queued job — the only place they're ever made — was
silently failing at `new \Google\Client()` with a fatal "Class not found"
before even reaching Google. `docker compose up -d --force-recreate` alone
does **not** reseed an anonymous volume; `--renew-anon-volumes` (`-V`) is
required. Documented here so this doesn't get rediscovered from scratch:
if `queue`/`scheduler` disagree with `backend` on installed Composer
packages after a dependency was added, this is the first thing to check.

With the environment genuinely healthy (confirmed via a real
`testConnection()` round-trip, 903ms, against `tech@suresigncontracts.com`),
two real, independent live bookings were created (one public/no-account,
one authenticated) and validated end to end:

- Exactly one real Google Calendar event and one real `hangoutsMeet`
  conference per booking, confirmed both via the stored
  `AppointmentExternalSync` row AND a direct live
  `findEventByCorrelationKey()` lookup against Google itself (never
  trusting only the local record).
- `retry()`/`reconcileOnly()`/the real scheduled
  `appointments:calendar-sync:reconcile` command all correctly no-op
  against an already-`synced` row — confirmed no duplicate event or
  conference is ever created.
- `sendUpdates='none'` confirmed against Google's own documented
  behaviour (not merely assumed): it suppresses **all** guest
  notification, not only Meet-specific information — see
  [Events: insert](https://developers.google.com/workspace/calendar/v3/reference/events/insert).
- **Root cause of the originally-reported symptom** ("received a Calendar
  email with no Meet link"): that email was never actually one of ours —
  it was SureSign's own pre-existing branded confirmation email
  (`AppointmentEmailService`/Brevo), whose `.ics` attachment and body only
  ever read `Appointment.meeting_url` (a generic, pre-existing field never
  populated for Consultancy bookings), with zero knowledge of
  `AppointmentExternalSync.meeting_join_url`. This is exactly the gap the
  Communications Upgrade below closes.
- Confirmed the customer-facing "Join Google Meet" button did not
  previously exist anywhere in the frontend despite the backend
  (`ConsultationMeetingPresenter`) exposing the data since Stage 4B.2 —
  wired into `frontend/src/app/app/consultations/[id]/page.tsx` (a
  three-state "Meeting Status" card: available/pending/hidden, no provider
  internals) and validated against both live bookings, including
  cross-organisation/unauthenticated rejection (403/401).

Manual visual confirmation inside the real Google Calendar web UI (event
title, description, attendee list, "Join with Google Meet" button
rendering) was **not** performed by the assistant — no browser/email-client
access exists in this environment — and remains an operator action.

## Consultancy Communications & Global Email Experience Upgrade — Batch 1

See [consultancy.md](consultancy.md)'s own Batch 1 section for the full
communications architecture. The only Google-integration-side change: the
one new hook point in `App\Services\Calendar\AppointmentCalendarSyncService::applyConferenceResult()`
— on a genuine transition into `MeetConferenceState::AVAILABLE` (never on
an unchanged reconciliation re-observation), it dispatches
`SendConsultationCommunicationJob` (`meeting_link_ready`). This is a pure
addition after the existing `$sync->update()`/Activity Log calls — no
Calendar/Meet sync behaviour, state machine, or failure handling changed
in this class.
