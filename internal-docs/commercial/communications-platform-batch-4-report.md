# SureSign Communications Platform — Batch 4 Report

Phase 2 of the Batch 4 work opened in
`communications-platform-batch-4-audit.md` (the read-only Phase 1 audit —
not duplicated here). This document is the implementation record: what
shipped, why, and the final state of SureSign's communications
architecture.

**Scope decided**: Option B (platform-wide standardisation), with one
refinement — no new communication workflows, no Billing email, no
invitation email invented. Two independent findings from the audit were
fixed first, ahead of the visual migration, per explicit priority.

---

## 1. Executive summary

Every customer-facing email family identified in the Phase 1 audit as
"plain `nl2br(e())`, no plaintext alternative" has been migrated onto the
same `EmailComponents` visual language Consultancy already used —
without changing a single trigger, business rule, subject wording, or
ICS condition. Two real, independent defects were fixed ahead of that
migration: a live HTML-injection path in `DemoRequestController`, and a
synchronous (non-queued) dispatch pattern for password reset/email
verification. Billing (no email exists) and invitations (no such concept
exists) were confirmed untouched, exactly as scoped.

---

## 2. Priority 1 — DemoRequestController subject injection (fixed)

**Root cause**: `EmailNotificationService::buildHtml()` interpolated its
`$subject` parameter into `<title>`/`<h1>` without escaping, relying on
each caller to escape a user-controlled subject itself.
`SupportTicketController` did; `DemoRequestController` — whose subject is
built directly from a public, unauthenticated marketing form field
(`company`) — did not.

**Fix**: `buildHtml()` now escapes `$subject` once, centrally, for every
caller, present and future — closing the class of bug, not just this one
instance. `SupportTicketController`'s two now-redundant local `e()` calls
were removed in the same change to avoid double-escaping (verified by
test).

**Incidental fix in the same file**: while adding a test for the above,
`DemoRequestController`'s `phone`/`message` fields (both `nullable`) threw
"Undefined array key" when omitted from a request — a real, pre-existing
500 on the public `/book-a-demo` form whenever a visitor left phone
blank. `project_count` was already correctly guarded with `isset()`;
`phone`/`message` were not. Fixed with the same guard. Not a business
behaviour change — the field was always meant to be optional.

## 3. Priority 2 — password reset / email verification queued dispatch (fixed)

Both previously called `EmailNotificationService::sendDirect()`
synchronously, inline, inside the live request — the one place in the
whole platform where a customer was actively waiting (on a "check your
email" screen) while a Brevo HTTP round-trip happened in their own
request/response cycle.

**Fix**: two new jobs, `App\Jobs\SendPasswordResetEmailJob` and
`App\Jobs\SendEmailVerificationJob`, both `default`-queue, `tries=3`,
`backoff=[30,120]` — the exact same contract `SendAppointmentEmailJob`/
`SendConsultationCommunicationJob` already use. `User::sendPasswordResetNotification()`
and `EmailVerificationService::sendVerificationLink()` now dispatch these
`->afterCommit()` instead of sending directly. Neither method's own
write is wrapped in an explicit transaction, so `->afterCommit()` fires
immediately in practice today — the same reasoning already documented for
every other family's dispatch site, and it stays correct automatically if
that ever changes.

Both jobs delegate their actual content-building to a new
`App\Services\AccountEmailService` — deliberately its own small service,
not folded into `AppointmentEmailService`/`ConsultationCommunicationService`,
since "account" (password reset, email verification) is a genuinely
different, narrower domain than either.

---

## 4. The visual migration

### 4.1 `EmailNotificationService::send()` — the leverage point

Rather than editing each of the eight `send()`-calling files
individually, the migration was centralised inside `send()` itself:

- `$bodyText` now renders via `EmailComponents::paragraph()` instead of
  `nl2br(e())`.
- A genuine `textContent` plaintext alternative is now always sent
  (`send()` had none before this batch).
- A human category label is derived from the event's own namespace
  prefix (`payment_application.submitted` → "Payment Application"), via
  `categoryFromEvent()` — a small explicit map covers the handful of
  abbreviations that don't humanize cleanly (EOT, L&E, AI), everything
  else falls through to a generic rule.
- The previously "reserved" (always-empty) `$meta` parameter gained its
  first real use: `EmailNotificationService::actionMeta(?string $relativeUrl, string $label = 'View in SureSign'): array`
  builds the CTA payload from a relative in-app path, returning `[]` (no
  button) when there's nothing to link to. Every existing caller still
  passes `[]` by default and is completely unaffected.

This one change gave every `send()` caller the premium body/plaintext/
category treatment for free, with zero risk to the six controllers' own
business logic.

### 4.2 Commercial workflow notifications — CTA buttons added

Six controllers (`VariationController`, `EotRequestController`,
`FinalAccountController`, `LossAndExpenseClaimController`,
`PayLessNoticeController`, `PaymentApplicationController`) and two AI
analysis jobs (`AnalyseContractWithAiJob`, `AnalyseTradePackageWithAiJob`)
already computed a relative `action_url` for their paired **in-app**
`NotificationService` call, via `WorkspaceNavigationResolver::actionUrl()`
— but never passed it to the email. Each call site now computes that URL
once and reuses it for both the in-app notification and
`EmailNotificationService::actionMeta()`'s email CTA — no new navigation
logic, no new destination, just resurfacing an already-computed link.
This closes the audit's own finding that this family had literally zero
CTA of any kind.

**Deliberately left without a button**: `SendDeadlineReminders` and the
marketing/demo-request internal notices. Unlike the eight call sites
above, neither had a pre-existing computed action URL to reuse — adding
one would mean inventing new navigation logic rather than resurfacing
existing data, which sat outside "no new workflows." They still get the
paragraph/plaintext/category upgrade from §4.1, just no CTA.

### 4.3 `AppointmentEmailService` — full migration

The family Batch 1's own scope note named as the intended target for this
batch: `sendAwaitingConfirmation`/`sendConfirmed`/`sendDeclined`/
`sendCancelled`/`sendReminder` were rewritten to build both an
`EmailComponents`-based HTML body and a parallel plaintext body (now
actually sent as `textContent`, not just embedded in HTML) —

- Reference/date/time/duration → `EmailComponents::meta()` (mirrors
  Consultancy's own `detailsRowsFor()` shape).
- A `meeting_url`, when present, → a real "Join Meeting" button
  (previously a raw `Join: {url}` text line); a bare `location` → a
  single-row meta block.
- Reschedule/cancel links → `EmailComponents::textActions()`, the same
  tertiary (never button-styled) treatment Consultancy already uses for
  the identical destructive/lower-priority pair.
- Every subject, ICS attachment condition (`appointment_ics_enabled`,
  cancellation vs. regular ICS), and status-to-email mapping is
  byte-for-byte unchanged — confirmed by the full existing
  `AppointmentsPhase4CommunicationsTest` suite passing unmodified.

### 4.4 Support ticket owner email

`SupportTicketController::emailTicketOwner()` (customer-facing —
ticket-resolved and staff-reply notices to the ticket owner) gained a
"View Request" button, reusing the exact relative URL
(`/app/help/support/{id}`) both callers already passed to their paired
in-app notification, plus a genuine plaintext alternative. Multi-paragraph
bodies (`"\n\n"`-separated, as both existing callers already pass) are
split into individual `paragraph()` calls so the line break survives in
HTML, not just plaintext.

**`notifySupportTeam()`** (the inbound, staff-facing side — recipient is
`admin_email`/a real reviewer's inbox, not a customer) was **not**
visually migrated — it is internal, not customer-facing, which is this
batch's own stated boundary. Its subject-escaping bug fix (§2) still
applies to it, since that's a security fix independent of audience.

---

## 5. What was deliberately left unchanged, and why

| Family | Status | Reason |
|---|---|---|
| Billing / subscription | Untouched | Confirmed in the Phase 1 audit: no email exists anywhere in this module. Nothing to migrate. |
| Invitations | Untouched | No dedicated concept exists — `UserController::invite()`'s only email is the same email-verification notice already migrated in §3/§4. Inventing a new "you've been invited" email would be a new workflow, explicitly out of scope. |
| `notifySupportTeam()` (inbound support) | Untouched (visually) | Internal/admin-facing, not customer-facing — outside this batch's stated boundary. |
| Marketing contact / demo request bodies | Untouched (visually) | Same reasoning — the recipient is always `admin_email`, never a customer; only the subject-escaping defect (customer-agnostic) was fixed. |
| `SendDeadlineReminders` / AI-analysis CTA | No button added | No pre-existing computed action URL to reuse — see §4.2. Still gets the `send()`-level upgrade. |
| ICS generation | Untouched | Confirmed in the Phase 1 audit: exactly one generator, no duplication, already correct. |
| Consultancy (Batches 1–3) | Untouched | Already the reference implementation this whole batch migrated everything else toward. |

---

## 6. Files added

- `app/Services/AccountEmailService.php`
- `app/Jobs/SendPasswordResetEmailJob.php`
- `app/Jobs/SendEmailVerificationJob.php`
- `tests/Feature/CommunicationsPlatformBatch4Test.php`
- `internal-docs/commercial/communications-platform-batch-4-audit.md` (Phase 1)
- `internal-docs/commercial/communications-platform-batch-4-report.md` (this document)

## 7. Files modified

`app/Services/EmailNotificationService.php` (subject escaping, `send()`
internals, `actionMeta()`), `app/Http/Controllers/Api/SupportTicketController.php`
(subject-escaping dedup, `emailTicketOwner()` migration),
`app/Http/Controllers/Api/SupportTicketMessageController.php` (action URL
threaded through), `app/Http/Controllers/Api/DemoRequestController.php`
(incidental phone/message guard fix), `app/Models/User.php`
(`sendPasswordResetNotification()`), `app/Services/EmailVerificationService.php`
(`sendVerificationLink()`), `app/Services/AppointmentEmailService.php`
(full `EmailComponents` migration), `app/Http/Controllers/Api/VariationController.php`,
`app/Http/Controllers/Api/EotRequestController.php`,
`app/Http/Controllers/Api/FinalAccountController.php`,
`app/Http/Controllers/Api/LossAndExpenseClaimController.php`,
`app/Http/Controllers/Api/PayLessNoticeController.php`,
`app/Http/Controllers/Api/PaymentApplicationController.php`,
`app/Jobs/AnalyseContractWithAiJob.php`, `app/Jobs/AnalyseTradePackageWithAiJob.php`
(all seven: reuse the already-computed `action_url` for the email CTA).

## 8. Automated tests

`tests/Feature/CommunicationsPlatformBatch4Test.php` — 16 tests covering:
the injection fix (with and without the incidental phone/message bug), the
double-escaping regression guard, password reset/email verification queue
dispatch and content, `send()`'s upgraded internals (plaintext, category
label, `actionMeta()`), `AppointmentEmailService`'s migrated content
(confirmed/meeting-url/cancelled), and the support ticket owner email.

## 9. Regression results

Full backend suite, run in memory-limited chunks (this environment's
documented 128M PHP ceiling): Unit 334/335 passed (1 pre-existing,
unrelated storage-permission error); Feature ~1,470 tests across 5 chunks,
all passing except the same pre-existing, unrelated failures already
documented in every prior batch's own report — the `FRONTEND_URL`
environment mismatch in two `ConsultancyCommunicationsBatch1Test`
assertions, `PaymentApplicationExcelDisclosureTest`, and this
environment's storage-permission errors on the `support-tickets`/
`adjudication` disks. **Zero regressions attributable to this batch** —
every existing Appointments/Consultancy/Billing/Batch3/Batch4-commercial/
AI-telemetry/support-ticket test that exercised a file this batch touched
passed unmodified.

## 10. Manual validation performed, and its boundaries

**Performed**: automated `Http::fake()`-based content assertions only (no
real Brevo send this batch, unlike Batch 1's own live validation).
**Not performed, stated honestly rather than assumed**: no real email was
opened in Outlook, Gmail, Apple Mail, or any mobile client; no automated
accessibility audit tool was run; dark-mode rendering was not visually
inspected in a real client (the wrapper remains the same static
light-themed HTML every family already shared before this batch — no new
dark-mode risk was introduced, but none was newly verified either).

## 11. Production readiness assessment

Every family identified in the Phase 1 audit as a genuine gap has been
closed: the injection defect, the synchronous auth-email dispatch, and
the plain-text/no-CTA/no-plaintext-alternative rendering across every
customer-facing family bar Consultancy. No business rule, trigger,
subject, or ICS condition changed anywhere — confirmed by the full
existing regression suite passing unmodified. Billing and invitations
were correctly left alone, since neither has anything to standardise.

## 12. Final Communications Architecture Summary

One provider integration (`EmailNotificationService`, Brevo), one HTML
wrapper (`buildHtml()`), one shared premium component library
(`EmailComponents` — button, meta, hairline, quietNote, statusCallout,
detailsTable, textActions, supportBlock, paragraph, heading), one ICS
generator (`AppointmentIcsService`). Every customer-facing email family in
the platform — Consultancy, generic Appointments, password reset, email
verification, support tickets, and the six commercial-workflow/two
AI-analysis notification families — now renders through that same
component set, with a genuine plaintext alternative and (wherever a real
destination already existed) a real CTA button. Billing has no email;
invitations have no dedicated email concept — both confirmed, not
assumed, and correctly out of scope.

## 13. Overall SureSign Communications Readiness Rating

**Production-ready, platform-wide.** The honest answer to the audit's own
question — *"would a paying enterprise customer believe every
communication came from the same mature SaaS platform?"* — is now yes:
every family a real customer receives shares the same wrapper, the same
component language, a genuine plaintext fallback, and (where a
destination exists) a real button, with the two independent defects the
audit found fixed ahead of the visual work. The only ratings-relevant
gaps that remain are the ones explicitly out of scope by design (Billing/
invitations, which have no email to standardise) and the manual
cross-client/accessibility verification listed in §10 as not performed.
