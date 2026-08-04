# SureSign Communications Platform — Batch 4, Phase 1 Audit

**Read-only.** No code, migration, styling, or other documentation was
changed to produce this report — every claim below was verified directly
against the current repository (file/line references given throughout),
not assumed or inferred from prior batch write-ups. This document is the
"audit report itself" Phase 1 permits as its one documentation exception.

---

## 1. Executive summary

There is exactly **one** email-sending mechanism in SureSign:
`App\Services\EmailNotificationService`, which is the sole caller of
Brevo's `POST /v3/smtp/email` anywhere in the codebase, and the sole owner
of the one shared HTML wrapper (`buildHtml()`). No Mailable classes, no
`Illuminate\Notifications` mail channel, no `Mail::` facade usage, and no
second provider integration exist. **The platform is not fragmented at the
provider/wrapper level — it never was.** Every one of the ~13 call sites
audited below funnels through this one service.

What *is* genuinely fragmented is **content quality and dispatch
discipline**, and it splits along one clean line: **Consultancy (Batches
1–3) vs. everything else.** Consultancy is the only family that:
- uses the premium `EmailComponents` button/meta/hairline library (used
  nowhere else in the entire codebase — verified, zero other callers),
- sends a genuine plaintext `textContent` alternative to Brevo (the only
  caller anywhere that ever sets `sendPlainTextAlternative: true`),
- has real per-send idempotency (`consultation_communication_deliveries`),
- and renders its action links as styled buttons instead of raw URLs.

Every other family — including `AppointmentEmailService`, Consultancy's
own sibling from before Batch 1 — still renders `nl2br(e($bodyText))` with
raw URLs as plain text, no plaintext alternative, and no per-send
idempotency.

Two structural findings **outside the "make it look nicer" scope** turned
up during this audit and are called out prominently in §16 and §17:
a confirmed, currently-live HTML-injection gap in one caller
(`DemoRequestController`), and an inconsistent dispatch discipline for
account emails (password reset / email verification run synchronously,
inline in the request, unlike every other family).

Two of the families explicitly named in the brief **do not exist** as
communications at all today: **billing/subscription has zero customer
email**, and there is **no "invitation" email concept** (project or
organisation) — see §2.7/§2.8. Reporting that honestly rather than
inventing scope to match the brief's assumption.

---

## 2. Communications inventory, by family

For each family: purpose, trigger, rendering path, queue, wrapper/component
usage, attachments, CTA style, raw URLs, and idempotency — verified
directly, not assumed.

### 2.1 Consultancy (Batches 1–3)

- **Files**: `App\Services\Consultancy\ConsultationCommunicationService`,
  `App\Jobs\SendConsultationCommunicationJob`,
  `App\Support\Email\EmailComponents`,
  `App\Support\Consultancy\ConsultationCommunicationLinks`.
- **Types**: `booking_confirmed`, `meeting_link_ready`,
  `booking_rescheduled`, `booking_cancelled`, `meeting_reminder_{offset}`,
  `consultation_followup`, `summary_published`.
- **Trigger**: booking/reschedule/cancel endpoints, `AppointmentCalendarSyncService::applyConferenceResult()`,
  `SendAppointmentReminders`, `Appointment::status → completed`,
  `ConsultancyOperationsController::publishSummary()`.
- **Queue**: `App\Jobs\SendConsultationCommunicationJob`, `default` queue,
  always dispatched `->afterCommit()` (verified in the Batch 3 audit; still
  true).
- **Wrapper**: `EmailNotificationService::buildHtml()` (shared).
- **Component usage**: `EmailComponents` — the ONLY family using it.
- **Attachments**: ICS, via `AppointmentIcsService` (the one and only ICS
  generator in the codebase — verified, no duplicate `BEGIN:VCALENDAR`
  anywhere else).
- **CTA style**: real buttons (`EmailComponents::button()`), never a raw
  URL in the HTML body.
- **Plaintext alternative**: yes — the only family with a genuine
  `textContent` part.
- **Idempotency**: real DB unique constraint,
  `consultation_communication_deliveries.idempotency_key`.
- **Production readiness**: high. This is the reference implementation
  the rest of the platform should converge toward, not the other way
  round.

### 2.2 Appointments (generic, non-Consultancy — Book a Demo, internal Appointments)

- **File**: `App\Services\AppointmentEmailService`.
- **Types**: created/awaiting-confirmation, confirmed, declined,
  cancelled, reminder.
- **Trigger**: `App\Jobs\SendAppointmentEmailJob` (`default` queue,
  `->afterCommit()` at every call site — verified again this pass).
- **Wrapper**: `EmailNotificationService::sendDirect()` — called with
  **no** `$htmlBody`/`$sendPlainTextAlternative` arguments anywhere in this
  file (verified: `grep` for `htmlBody`/`EmailComponents` in this file
  returns nothing). Falls through to plain `nl2br(e($bodyText))`.
- **CTA style**: raw URLs as plain text lines
  (`appendManageLinks()`: `"Reschedule: {$reschedule}"`,
  `"Cancel: {$cancel}"`; `appendMeetingDetails()`: `"Join: {$appointment->meeting_url}"`).
  No buttons anywhere in this file.
- **Plaintext alternative**: none.
- **Idempotency**: reminders only, via `AppointmentReminderSend`'s DB
  unique constraint; created/confirmed/declined/cancelled have none.
- **This is the family Batch 1's own scope note named as "the global
  (non-Consultancy) email-family visual migration (Batch 4)."** It is the
  most direct, lowest-risk, highest-similarity candidate for this batch —
  same underlying model (`Appointment`), same ICS service, same link
  service, near-identical shape to what Consultancy already has.

### 2.3 Commercial workflow notifications (Variations, EOTs, Final Accounts, Loss & Expense, Pay-less Notices, Payment Applications)

- **Files**: `VariationController`, `EotRequestController`,
  `FinalAccountController`, `LossAndExpenseClaimController`,
  `PayLessNoticeController`, `PaymentApplicationController`.
- **Trigger**: directly inline in the controller action, on a workflow
  transition (e.g. Variation approved/rejected only —
  `VariationController`'s own comment: *"only the approved/rejected
  decision points are important enough to email"*).
- **Rendering path**: `EmailNotificationService::send($event, ...)` — a
  **different method** from Consultancy/Appointments' `sendDirect()`. Not
  gated on a specific recipient; gated on the org-wide
  `notification_settings` toggle list, and fanned out to the
  organisation's own contact email + every Client-role user + the
  platform `admin_email` (verified in `EmailNotificationService::send()`,
  lines 26–101).
- **Queue**: **none** — a plain synchronous call inside the HTTP request
  (verified: no `ShouldQueue`, no Job wraps any of these six call sites).
  `send()`'s own internal `try/catch` means a Brevo failure can't break
  the request, but the outbound HTTP call to Brevo still happens
  synchronously inside the request/response cycle.
- **CTA style**: **none at all** — e.g.
  `PaymentApplicationController` line 552: *"Payment Application #123 has
  been submitted for project: X."* — one sentence, no link, no button. The
  real "go look at this" action exists only as `action_url` on the
  **separate in-app** `NotificationService::sendToOrganization()` call
  made just before it — never surfaced into the email itself.
- **Plaintext alternative**: none. **Idempotency**: none (no delivery
  table; relies entirely on the triggering controller action happening
  once).
- **Note for scope decision**: "add a button" here isn't a pure style
  change like the other families — there is currently no link in the
  email body at all, so adding one is a small content addition, not a
  refactor of an existing link.

### 2.4 Authentication — password reset

- **File**: `App\Models\User::sendPasswordResetNotification()`.
- **Trigger**: Laravel's own `PasswordBroker::sendResetLink()` /
  `AuthController::forgotPassword()` — this method is Laravel's built-in
  notification hook, **overridden** to bypass the default
  `Illuminate\Auth\Notifications\ResetPassword` mail notification (the
  class docblock says why: `MAIL_MAILER=log` would otherwise silently
  swallow it).
- **Rendering path**: `EmailNotificationService::sendDirect()`, no
  `$htmlBody` — plain `nl2br(e($bodyText))`.
- **Queue**: **none** — called synchronously, inline, as part of Laravel's
  password-broker flow inside the live HTTP request. This is the same
  "no queue" pattern as §2.3, but here it's a customer-facing,
  latency-sensitive action (the user is sitting on a "check your email"
  screen waiting).
- **CTA style**: raw URL in the body text —
  `"Reset it here: {$resetUrl}"`. No button.
- **Security**: subject is a static string (`'Reset your SureSign
  password'`), so no injection risk here specifically.

### 2.5 Authentication — email verification

- **File**: `App\Services\EmailVerificationService::sendVerificationLink()`.
- **Trigger**: `AuthController::sendEmailVerification()`,
  `UserController::invite()` (see §2.8).
- **Rendering path / queue / CTA**: identical shape to §2.4 — synchronous,
  `sendDirect()` with no `$htmlBody`, raw URL in plain text
  (`"Verify it here: {$verifyUrl}"`), no button.
- **Token handling**: hashed at rest (`Hash::make($token)`), 60-minute
  expiry checked server-side — sound, unrelated to the communications
  question itself.

### 2.6 Support tickets

- **File**: `App\Http\Controllers\Api\SupportTicketController`.
- **Two directions**: inbound (customer → support team,
  `notifySupportTeam()`) and outbound (`emailTicketOwner()`, shared by
  ticket-resolved and staff-reply paths).
- **Rendering path**: `sendDirect()`, plain text, no button. The
  screenshot is deliberately **never attached or linked by URL** in the
  email (an explicit, documented decision — staff view it in-app,
  authorized the same way as everything else).
- **Security (confirmed, already partially mitigated)**: the class's own
  comment states plainly that `buildHtml()` interpolates `$subject`
  unescaped into `<title>`/`<h1>`, and that this ticket's own subject
  (user-controlled) is escaped with `e()` **at this one call site** to
  close that path locally — explicitly noting the underlying gap in
  `buildHtml()` itself was left unfixed as "out of scope... platform-wide."
  See §16 for why that same gap is *not* closed at every call site.
- **Queue**: none — synchronous, inline.

### 2.7 Billing / subscription — **no email exists**

Verified by grepping every Billing service
(`SubscriptionLifecycleService`, `InvoiceSyncService`,
`SubscriptionAutomationService`, `CheckoutSessionService`,
`WebhookEventProcessor`, etc.) and every Billing-related controller for
`EmailNotificationService`/`sendDirect`/`EmailComponents`/`Notification`:
**zero hits.** No invoice email, no payment-failed email, no
subscription-activated/cancelled/trial-ending email. The only customer
surface for any billing event is in-app (the Billing page, Subscription
Intelligence Centre). This is a real, confirmed absence — not a family
that's merely "inconsistent," it simply isn't built. If Stripe's own
receipt/invoice emails are relied on instead, that's a provider-level
behaviour outside this codebase, not something this repository sends.

### 2.8 Invitations — **no dedicated concept exists**

There is no `Invitation` model, no invitation token table, and no
"you've been invited to join SureSign / this organisation" email.
`UserController::invite()` creates a `User` row with a server-generated
temp password (returned only in the JSON API response, for the inviting
Admin to relay out-of-band) and sends exactly one email: the same
generic "verify your email address" notice from §2.5. There is no
project-level or organisation-level invitation email family for this
batch to standardise — the brief's assumption that one exists doesn't
hold.

### 2.9 Marketing site — demo requests & contact enquiries

- **Files**: `DemoRequestController`, `App\Services\Marketing\SendMarketingContactEnquiryService`.
- **Audience**: internal only — both notify the platform `admin_email`,
  never a customer.
- **Rendering path**: `sendDirect()`, plain text, no button.
- **Security finding — confirmed live**: `DemoRequestController` builds
  its subject as `'New demo request — '.$validated['company']`, where
  `company` is free-text input from the **public, unauthenticated**
  `/book-a-demo` marketing form (max 255 chars, no sanitisation), and
  passes it straight into `sendDirect()` with no `e()` around the
  subject. `buildHtml()` interpolates `$subject` unescaped into both
  `<title>` and `<h1>`. **This is a live, unescaped HTML-injection path
  into an email an admin views** — see §16.

### 2.10 AI analysis completion notices

- **Files**: `AnalyseContractWithAiJob`, `AnalyseTradePackageWithAiJob`.
- **Rendering path**: `EmailNotificationService::send()`, plain text, no
  button — same event-gated/org-wide model as §2.3.
- **Queue**: these calls happen *inside* an already-queued
  `ShouldQueue` job, so the email send itself inherits that job's async
  execution — a materially different (and better) situation than §2.3/§2.4/§2.5,
  even though the `EmailNotificationService` call itself is still
  synchronous relative to the job's own `handle()`.

### 2.11 Deadline reminders

- **File**: `App\Console\Commands\SendDeadlineReminders`.
- **Rendering path**: `EmailNotificationService::send()`, plain text, no
  button, org-wide fan-out — same shape as §2.3.
- **Queue**: runs inside a scheduled Artisan command (background,
  not a live user-facing request) — lower urgency than §2.3/§2.4/§2.5, but
  still not a dedicated queued Job with its own retry/idempotency
  contract; the command's own `DeadlineReminderRun`/`DeadlineReminderSend`
  tables (unrelated to Consultancy) provide the actual duplicate
  prevention here, at the command level, not the email level.

---

## 3. Which families use the shared wrapper, and which don't

**Every single family uses `EmailNotificationService::buildHtml()`.**
There is no second wrapper, no bypass, no competing HTML shell anywhere in
the codebase. In that specific sense, the platform already has one visual
foundation — it always has.

What's actually split is which families render real component-driven
content into that wrapper versus a bare, escaped paragraph of text:

| Uses `EmailComponents` (buttons, meta, hairline) | Plain `nl2br(e($bodyText))` only |
|---|---|
| Consultancy (all 7 types) | Appointments (generic/Book a Demo) |
| | Password reset |
| | Email verification |
| | Support tickets (both directions) |
| | Variations / EOTs / Final Accounts / L&E / Pay-less Notices / Payment Applications |
| | Demo requests / marketing contact |
| | AI analysis completion |
| | Deadline reminders |

Billing and invitations aren't in either column — they don't send email
at all (§2.7, §2.8).

---

## 4. Typography, spacing, visual consistency

Because every family shares the one `buildHtml()` wrapper, the outer
shell (header bar, gold rule, category eyebrow, Georgia serif `<h1>`, dark
footer) is **already identical across all eleven families** — there is no
"looks like a different product" problem at the wrapper level. The
inconsistency customers would actually notice is narrower and entirely a
body-content problem: Consultancy's body reads as a designed layout
(meta rows, hairlines, a real button); everything else reads as a
paragraph of escaped plain text with a URL in it, because that's
literally what it is. This matches Batch 1's own original audit finding
almost exactly, extended now to the rest of the platform.

---

## 5. CTA / button review

- Consultancy: real buttons throughout.
- Appointments (generic), auth (reset/verify), support: real, working
  action URLs — never buttoned.
- Variations/EOTs/Final Accounts/L&E/Pay-less Notices/Payment
  Applications: **no CTA of any kind** — see §2.3's note that this is a
  content gap, not just a style gap.
- Demo requests / marketing contact / AI analysis / deadline reminders:
  internal notices, no customer CTA expected (correctly so — these aren't
  customer-facing actions).

---

## 6. Mobile / accessibility

`buildHtml()`'s wrapper is table-based with a `viewport` meta tag and a
600px max-width card — the same layout Consultancy's own emails already
inherit and that Batch 3 validated (production build, `tsc`, `eslint`,
structural review). No family uses fixed-width text blocks or unwrapped
long strings that would force horizontal scroll on a phone-width client,
as far as static review can determine. **I have not opened any of these
emails in an actual mobile mail client, Outlook, Gmail, or Apple Mail**,
and am not claiming to — see §12 (Manual validation boundaries in the
brief's Phase 2 framing applies equally here: nothing beyond static
code/structure review was performed).

No dark-mode CSS (`prefers-color-scheme`, `color-scheme` meta) exists
anywhere in `buildHtml()`. This is a static light-themed email — not
"broken" by dark mode (no colours invert unexpectedly, since none rely on
system/transparent backgrounds), just not deliberately dark-mode-aware.
Same for every family, since they all share this one wrapper.

---

## 7. Content / tone review

Consultancy's copy (Batches 1–3) was written to a "calm, premium, plain
British English" standard. The other families' copy is functional and
short, not "AI-sounding" or verbose, but plainly utilitarian —
e.g. *"Payment Application #123 has been submitted for project: X."* No
family contains internal terminology, stack traces, or debug output in
its customer-visible text (verified — see §9's security check, which
looked specifically for this).

---

## 8. Raw URL review

Every family except Consultancy exposes at least one raw URL as visible
text in its HTML body (§2.2, §2.4, §2.5). Support tickets and the
internal-only marketing notices have no customer action link to expose.
Variations/EOTs/etc. have no URL of any kind, raw or otherwise (§2.3).

---

## 9. ICS review

One implementation, `App\Services\AppointmentIcsService` — confirmed via
`grep` for `BEGIN:VCALENDAR` across the whole codebase, zero duplicates.
Already reviewed in depth across Batches 1–3: stable UID (from
`Appointment::reference`, not the rotating `public_token`), `SEQUENCE`
from `schedule_version`, CRLF line endings, RFC5545 TEXT escaping,
`METHOD:PUBLISH`/`METHOD:CANCEL`, `STATUS`, `DESCRIPTION`, `LOCATION`, and
the trusted Meet URL when available. **Nothing further to find here** —
this checklist item is already fully satisfied.

---

## 10. Security review

**Confirmed leaks/gaps, in order of severity:**

1. **Unescaped subject → HTML injection, `DemoRequestController`
   (§2.9).** Live, public, unauthenticated input reaches `<h1>`/`<title>`
   unescaped. This is the one finding in this whole audit that reads as a
   genuine defect rather than a style/consistency gap, and it exists
   independent of whichever Batch 4 scope is chosen.
2. `buildHtml()` itself has no subject-escaping — every other caller
   either passes a static string (auth, most Consultancy subjects) or
   already escapes at the call site (`SupportTicketController`, and
   Consultancy's own subjects are built from system-generated strings, not
   raw user input). Only `DemoRequestController` is confirmed exploitable
   today.
3. No family leaks internal IDs, database IDs, provider IDs, Google event
   IDs, Stripe IDs, queue identifiers, exception messages, file paths,
   stack traces, or raw SQL in a customer-facing body — verified across
   every file read this pass (the org-wide notification family, §2.3,
   only ever references human-readable reference numbers like
   `variation_number`/`application_number`, never a numeric primary key).
4. Every signed link (Consultancy) is Laravel's own signed-URL mechanism,
   scoped to `public_token`, per Batches 1–3's own review — unchanged,
   re-confirmed present.

---

## 11. Queue / dispatch review

| Family | Queued? | `afterCommit()`? |
|---|---|---|
| Consultancy | Yes, `SendConsultationCommunicationJob`, `default` | Yes, every site |
| Appointments (generic) | Yes, `SendAppointmentEmailJob`, `default` | Yes, every site |
| Commercial workflow notices (§2.3) | **No** — synchronous in-request | N/A |
| Password reset (§2.4) | **No** — synchronous in-request | N/A |
| Email verification (§2.5) | **No** — synchronous in-request | N/A |
| Support tickets (§2.6) | **No** — synchronous in-request | N/A |
| Demo requests / marketing (§2.9) | **No** — synchronous in-request | N/A |
| AI analysis notices (§2.10) | Indirectly — inside an already-queued Job | N/A (already async) |
| Deadline reminders (§2.11) | Indirectly — inside a scheduled command | N/A |

No family sends an email before a committed database write reaches a
terminal state that matters (the synchronous families run their email
call after the relevant `->update()`/`->create()` in every file read this
pass) — but the *synchronous, in-request* families add a live Brevo HTTP
round-trip to the user's request latency, and have no retry if that call
fails (its own internal `try/catch` just logs and swallows). This is a
genuine dispatch-discipline inconsistency, most visible for password
reset/email verification, where the user is actively waiting on the
result.

---

## 12. Brevo review

One integration point, `EmailNotificationService`. `messageId` is
captured only by `sendDirectWithMessageId()` (Consultancy's own path);
every other caller uses the plain `sendDirect()`/`send()` and discards
whatever Brevo returns beyond success/failure. Failure handling is
uniform: a non-2xx response or thrown exception is caught internally,
logged via `Log::warning()`, and returns `false`/void — no family's
Brevo failure can raise an exception into its caller. No retry exists at
the `EmailNotificationService` level itself; retry, where it exists at
all, comes from the surrounding Job's own `$tries`/`backoff` (Consultancy/
Appointments only).

---

## 13. Activity Log review

There is no separate "email sent" Activity Log category anywhere in the
codebase. Delivery status is tracked in exactly two places:
`consultation_communication_deliveries` (Consultancy) and
`AppointmentReminderSend` (generic Appointment reminders only). Every
other family has zero delivery/idempotency record — its only audit trail
is the underlying business action's own `ActivityLog` entry (e.g.
`variation.approved`), which was never meant to represent "an email was
sent," and doesn't.

---

## 14. Documentation state

`consultancy.md` and `google-integration.md` are current and accurate as
of the Batch 3 report (spot-checked this pass, no drift found).
`project-context.md`'s Batch 1–3 entries are consistent with what this
audit re-verified. No dedicated "email architecture" or "queue
architecture" document exists platform-wide — communications
architecture today lives entirely inside `consultancy.md`'s own
Communications Upgrade sections, which is accurate for Consultancy but
was never meant to (and doesn't) describe the other ten families audited
here.

---

## 15. Production readiness, by family

| Family | Readiness |
|---|---|
| Consultancy | Production-grade: components, idempotency, plaintext part, buttons |
| Appointments (generic) | Functionally correct, visually behind Consultancy |
| Commercial workflow notices | Functionally correct, no CTA, no idempotency, synchronous |
| Password reset / email verification | Functionally correct, synchronous, raw URL, latency-sensitive |
| Support tickets | Functionally correct; one already-mitigated injection path noted in its own code |
| Demo requests / marketing | Functionally correct **except** the confirmed injection gap (§2.9/§16) |
| AI analysis / deadline reminders | Functionally correct, internal-facing, lower stakes |
| Billing | N/A — doesn't exist |
| Invitations | N/A — doesn't exist |

---

## 16. Findings that exist independent of the Batch 4 scope decision

Two things surfaced this pass that are worth acting on regardless of
whether Batch 4 ends up scoped as (A) Consultancy + Appointments or (B)
platform-wide, because they're defects, not inconsistencies:

1. **`DemoRequestController`'s unescaped subject (§2.9, §10.1)** — a live,
   public, unauthenticated HTML-injection path into an admin-viewed
   email. Fixing this doesn't require touching `EmailComponents` or any
   visual system at all; it's either a one-line `e()` at the call site
   (mirroring what `SupportTicketController` already does for the same
   reason) or, more robustly, escaping `$subject` once inside
   `buildHtml()` itself for every caller. Recommend treating this as a
   security fix independent of the Batch 4 design conversation, not
   bundled into whichever visual scope gets chosen.
2. **Password reset / email verification run synchronously** (§2.4, §2.5,
   §11) — the one place in the whole platform where a customer is
   actively waiting on an email-triggering action inside a live request.
   Not a visual problem; a dispatch-discipline one.

Neither requires any decision about Consultancy/Appointments/platform-wide
scope to act on, and neither was touched in this read-only pass.

---

## 17. What this audit does *not* find

Being explicit about this, per the brief's own instruction not to
manufacture work:

- No second Brevo integration, no second HTML wrapper, no second ICS
  generator anywhere in the codebase.
- No family leaks internal identifiers, stack traces, or raw SQL.
- No dark-mode-breaking colour usage (nothing to break — it's uniformly
  light-themed).
- No billing or invitation communications exist to standardise — asking
  "is billing's email consistent with Consultancy's" has no answer, because
  billing sends none.
- Consultancy itself needs nothing further from this audit — Batches 1–3
  already brought it to the standard the rest of the platform would be
  measured against.

---

## 18. Honest answer to the brief's own question

*"Would a paying enterprise customer believe every communication came
from the same mature SaaS platform?"*

**Not yet, but not because the platform is fragmented at the architecture
level — because roughly two-thirds of customer-facing email content (by
family count) still reads as a plain-text system notice with a bare URL,
next to Consultancy's polished equivalent.** The foundation (one wrapper,
one provider integration, one ICS generator) is already exactly what a
mature platform should have. What's missing is applying Consultancy's own
already-built component system to the handful of families a customer
actually receives outside Consultancy: generic Appointments, password
reset, email verification, and (to a lesser extent, since they carry no
link today) the commercial workflow notices. Billing and invitations are
moot — they don't send email to be inconsistent in the first place.

---

## 19. Scope options for Phase 2 (not decided here)

**Option A — Consultancy + Appointment communications only** (the
originally-scoped Batch 4): migrate `AppointmentEmailService`'s five
email types onto `EmailComponents`, add a plaintext alternative, add
per-send idempotency matching Consultancy's pattern. Smallest, lowest-risk,
highest-similarity-to-existing-work option. Does not touch auth, billing,
support, or commercial-notification code at all.

**Option B — platform-wide standardisation**: everything in Option A,
plus password reset, email verification, support tickets, and the six
commercial-workflow-notification controllers migrated onto
`EmailComponents`; the commercial notifications would additionally need a
real CTA added (a small content change, not present today at all); the
synchronous dispatch pattern for auth/support/demo/commercial-notice
emails would need a decision on whether to queue them (a dispatch-behaviour
change, arguably outside "visual standardisation" and worth its own
sub-decision). Billing/invitations remain out of scope regardless, since
they have no email to touch.

Recommend deciding this with the two independent findings in §16 already
actioned or explicitly deferred, since they're real defects regardless of
which option is chosen.
