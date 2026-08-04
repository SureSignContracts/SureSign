# Consultancy Communications & Global Email Experience Upgrade — Batch 3 Architecture Report

Status: Batch 1 (shared foundation, `booking_confirmed`/`meeting_link_ready`)
and Batch 2 (`booking_rescheduled`/`booking_cancelled`/`meeting_reminder_{offset}`)
are complete — see `internal-docs/super-admin/consultancy.md`'s own
Batch 1/Batch 2 sections for the full implementation writeup. This document
opens the Batch 3 record (follow-up/published-summary communications, the
dedicated public no-account view page) with a pre-flight audit requested
before Batch 3 design begins: confirming every communication type shipped so
far is dispatch-safe with respect to the enclosing database transaction.

## Transactional-safety audit (dispatch-after-commit)

**Every dispatch site for a Consultancy or generic Appointment communication
uses `->afterCommit()`.** Confirmed by grepping every call to
`SendConsultationCommunicationJob::dispatch()` and
`SendAppointmentEmailJob::dispatch()` across `app/` — all 16 call sites
across `AppointmentController` (`created`/`reschedule`/`transition`
`confirm`|`decline`|`cancelled`), `PublicAppointmentController` (`created`),
`PublicConsultationController` (`booking_confirmed`),
`ConsultationController` (`booking_confirmed`/`booking_cancelled`),
`PublicAppointmentActionController` (`reschedule`/`cancel`, both branching
Consultancy vs. generic), `AppointmentCalendarSyncService::applyConferenceResult()`
(`meeting_link_ready`), and `SendAppointmentReminders::handle()`
(`meeting_reminder`/`reminder`) chain `->afterCommit()` with no exception.
Zero dispatch calls were found without it.

**None of these dispatch calls sit inside a still-open transaction at the
line that calls `->afterCommit()`**, which matters because Laravel's own
contract for `afterCommit()` is: if a transaction is currently open when the
job is dispatched, the job is queued to fire only once that transaction
commits (and is silently discarded if it rolls back instead); if no
transaction is open at dispatch time, the job fires immediately — the write
it's reporting on is already durably committed by ordinary auto-commit, so
there is nothing left to wait for. Traced per family:

- **Booking confirmation** (`booking_confirmed`, `created`) — dispatched
  from `PublicConsultationController::store()`/`ConsultationController::store()`/
  `PublicAppointmentController::store()`/`AppointmentController::store()`
  after the appointment row (and, for Consultancy, the linked
  `ConsultationEnquiry` row) has already been created via
  `ConsultationEnquiryService::book()`/`AppointmentSchedulingService`, whose
  own conflict-checking writes are the ones wrapped in a transaction — the
  dispatch line itself runs after that call returns, once any such
  transaction has already committed.
- **Reschedule** (`booking_rescheduled`, `reschedule`) — dispatched from
  `AppointmentController::reschedule()`/`PublicAppointmentActionController::reschedule()`
  after `AppointmentSchedulingService::withConflictCheck()` returns.
  `withConflictCheck()` wraps its staff-row lock, conflict check, and the
  actual `AppointmentWorkflowService::reschedule()` write in one
  `DB::transaction()` (`AppointmentSchedulingService.php:178`) — by the time
  control returns to the controller and the dispatch line runs, that
  transaction has already committed or thrown (in which case the dispatch
  line is never reached at all).
- **Cancellation** (`booking_cancelled`, `transition`) — dispatched from
  `AppointmentController::applyTransition()`/`PublicAppointmentActionController::cancel()`/
  `ConsultationController::cancel()` after
  `AppointmentWorkflowService::transition()` returns. `transition()` has no
  explicit `DB::transaction()` wrapper — its `$appointment->update()` call
  auto-commits as a single statement — so at the point of dispatch there is
  no open transaction for `afterCommit()` to defer to; the job fires
  immediately, which is correct since the status change is already durable.
- **Meeting-link-ready** (`meeting_link_ready`) — dispatched from
  `AppointmentCalendarSyncService::applyConferenceResult()`, which the method's
  own docblock already documents: dispatched immediately after
  `$sync->update()` (auto-committed, no explicit transaction), and confirmed
  that none of this method's four call sites (`process()` ×3,
  `refreshPendingMeet()`) sit inside `claim()`'s own `DB::transaction()`
  block (`AppointmentCalendarSyncService.php:654`) — that transaction is a
  separate, earlier method call, already committed before `process()`/
  `refreshPendingMeet()` (and therefore `applyConferenceResult()`) ever run.
- **Reminders** (`meeting_reminder`, `reminder`) — dispatched from
  `SendAppointmentReminders::handle()` immediately after
  `claimReminderSend()` inserts the `AppointmentReminderSend` row (a single
  auto-committed `INSERT`, no explicit transaction) — same "no open
  transaction, fires immediately" case as cancellation above.

**No Observer dispatches any communication job.** `app/Observers/` contains
exactly one observer (`ConsultancyAppointmentObserver`); grepping it for
`SendConsultationCommunicationJob`/`SendAppointmentEmailJob` returns nothing
— it plays no role in customer communication dispatch, so there is no
model-event path that could fire a job outside the request/command flows
audited above.

**Conclusion**: every communication type shipped in Batch 1/Batch 2
(`booking_confirmed`, `meeting_link_ready`, `booking_rescheduled`,
`booking_cancelled`, `meeting_reminder_{offset}`) is guaranteed to dispatch
only once its triggering write has actually committed, and a rolled-back
transaction can never result in a sent email — either because
`afterCommit()` defers the job until commit and drops it entirely on
rollback, or because the write it reports on was never wrapped in a
transaction that could roll back in the first place. No code change was
required by this audit; it is a confirmation of the existing, already-shipped
Batch 1/Batch 2 wiring, kept here as the opening record for Batch 3.
