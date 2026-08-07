# Error Messaging & Recovery UX — Phase A Audit

**Status:** Audit complete (read-only). **Batches 1–6** are now all
implemented as full semantic audit + fix passes: Shared Foundation +
Authentication + P0 safety fixes; Projects + Trade Packages; Commercial +
Documents; Consultancy + Appointments; Programme + Delay & EOT +
operational modules; Billing + AI + Google + Email/Communications. A
platform-wide mechanical consolidation sweep (covering the remaining
ground of Batches 5–7's *mechanical* debt only) was completed between
Batches 4 and 5. This is the last batch the original Phase A plan named —
see Batch 6's own completion report for the initiative-closure assessment.

**Scope:** Read-only, repository-wide audit of how SureSign currently
surfaces failures to customers (frontend) and how the backend currently
represents them (API contract). Produced sequentially, in-session, per the
repo's standing no-subagent/no-workflow-fan-out rule — see
[`feedback_no_subagents_for_implementation`] in project memory and the
"Agent / Subagent Usage" section of `CLAUDE.md`.

---

## 1. Executive Summary

SureSign has **no centralized error-handling architecture on either side of
the stack**, but it does have real, reusable pieces already in place that a
foundation should build on rather than replace:

- One shared Axios instance (`frontend/src/lib/api.ts`) already handles
  session-expiry (401) and account-deactivation (403 + `account_unavailable`
  code) globally. This is the right place to extend, not a new client.
- A `code` field on JSON error responses is a **real, working, existing
  convention** — just confined to the Subscription & Billing module
  (`checkout_unavailable`, `plan_change_conflict`, `subscription_conflict`,
  `account_unavailable`, etc.). Every other module has no structured code at
  all; the frontend has nothing to key off of except free-text `message`.
- A single-purpose helper, `getErrorMessage(error, fallback)`, has been
  **independently reinvented at least 8 times** across the frontend (one
  shared copy in `lib/getErrorMessage.ts`, plus near-identical inline copies
  in `commercial/page.tsx` (×2, different route groups), `contracts/page.tsx`,
  `delay-eot/page.tsx`, `deliveryDocumentShared.tsx`, `riskShared.tsx`, and
  `SubcontractAiOnboardingModal.tsx`). All of them do the same thing — pull
  `response.data.message` out of an Axios error — with small, uncoordinated
  variations. This is the single clearest piece of evidence that a shared
  utility is needed, and that it should **replace duplicates**, not add a
  ninth implementation.
- Laravel's **default exception rendering is unmodified** except for one
  rate-limit override. Validation, authorization, and not-found responses
  all use Laravel's stock JSON shape and stock wording.
- **Zero of the 54 `FormRequest` classes define `messages()` or
  `attributes()`.** Every validation failure returns Laravel's raw
  auto-generated text against the raw snake_case field name (e.g. "The
  application_date field is required.") — this is the single highest-value,
  lowest-risk fix available: it touches no business logic, only copy.
- **Inline, field-anchored validation display does not exist anywhere in the
  frontend.** Of the ~13 files that even read the Laravel `errors` object at
  all, all of them only pull a single field's first message out as a toast
  fallback (e.g. `errors?.email?.[0]`) — none render it next to the actual
  form field. Every validation failure today is a toast, never an inline
  error, regardless of whether the failing field is known.
- Domain services already throw **typed, hand-authored exceptions**
  (`\RuntimeException`, `\InvalidArgumentException`, and purpose-built
  classes like `SubscriptionLifecycleConflictException`) with messages
  written for the exception itself, and controllers already catch by type
  and return `$e->getMessage()`. This is a coherent, real pattern — the gap
  is consistency (409 vs. 422 for the same kind of conflict varies by
  controller) and that a few of these `catch` blocks are typed against
  PHP's *generic* built-in exceptions (`\RuntimeException`), which could in
  principle also catch a genuinely unexpected internal error and forward its
  raw message — this needs verification per call site before any change,
  not a blanket assumption either way.
- One real, currently-dormant P0-shaped risk exists:
  `FeatureNotEntitledException`'s own message string embeds the raw
  organisation ID and the raw internal `Feature` key
  (`"Organisation 52 is not entitled to feature \"custom_branded_subdomain\"."`).
  Its own docblock says nothing catches it yet; the one real caller
  (`OrganizationBrandingUrlController`) already deliberately avoids surfacing
  it. This is a landmine for the *next* caller, not a live leak — flagged so
  it's designed around, not fixed reactively later.
- A concrete, already-shipped example of an unreported partial success:
  `PaymentApplicationController::certify()` saves certification unconditionally
  and swallows a payment-certificate PDF generation failure into a `Log::warning`
  only — the API response (`$paymentApplication->fresh()`) gives the frontend
  no way to know the PDF didn't generate. The certification itself is
  correctly non-blocking; the customer is just never told.
- Route-level Next.js error boundaries (`error.tsx`) **do not exist anywhere
  in the app** — only a single root `not-found.tsx`. An uncaught render-time
  exception on any page currently falls through to Next.js's own default
  error UI, not a branded SureSign page.

None of this requires a large or risky foundation. Section 8 proposes the
smallest one that fits what's actually here.

---

## 2. Current Frontend Error Architecture

**One shared HTTP client.** `frontend/src/lib/api.ts` is the only Axios
instance found; it is imported everywhere (no second client, no raw `fetch`
pattern for authenticated calls). It already:

- attaches the bearer token from `localStorage` on every request;
- globally handles `401` and the specific `403` + `code: 'account_unavailable'`
  combination by clearing session storage and redirecting to `/login`;
- deliberately exempts `/auth/login` and `/auth/register` from that redirect
  (a documented, deliberate fix for a real prior bug — an incorrect
  login-attempt 401 must not be treated as session expiry);
- deliberately does **not** intercept the `password_change_required` 403,
  because `ForcePasswordChangeGate` already handles that case from the
  known `user.must_change_password` flag before such a request would fire.

This is a well-reasoned, working interceptor — it should be **extended**,
not replaced, for any new normalization work (e.g. a generic-`message`
extraction step for all other status codes could live in the same response
interceptor, or in a small utility called at each catch site — see §8).

**No shared error-normalization utility exists at the intended granularity.**
`lib/getErrorMessage.ts` exists and is correctly named for the job, but it is
not consistently imported — 7 other files independently redefine an
almost-identical function instead of importing it:

| File | Diverges how |
|---|---|
| `lib/getErrorMessage.ts` (canonical) | `response.data.message` only |
| `app/app/projects/[id]/delay-eot/page.tsx` | also checks `error instanceof Error` |
| `app/app/projects/[id]/commercial/page.tsx` | identical logic, re-declared |
| `app/(dashboard)/commercial/page.tsx` | different signature — no `fallback` param, hardcoded message |
| `components/deliveryDocuments/deliveryDocumentShared.tsx` | identical logic, re-declared |
| `components/risks/riskShared.tsx` | identical logic, re-declared |
| `components/subcontracts/SubcontractAiOnboardingModal.tsx` | uses `as any`, no type narrowing |
| `app/app/projects/[id]/contracts/page.tsx` | identical logic, more verbose type guards |

None of these 8 variants read the `errors` field, classify by status code,
or say anything about retryability or data state — they only ever answer
"what string do I show," and only from the raw backend `message`.

**Toasts are the default and near-universal surface.** `react-hot-toast` is
used directly (`import toast from 'react-hot-toast'`) at ~54 call sites, each
with its own literal fallback string (`'Failed to save.'`, `'Failed to save
risk'`, `'Failed to update the Consultancy consultant.'`, …). There is no
shared toast wrapper, no shared duration policy, and no category-based
styling — every module independently decided its own wording and, in most
cases, whether to prefer the raw backend message or its own fallback.

**React Query is the dominant mutation pattern** (`useMutation`/`useQuery`
appear in 76+ page/component files) but there is no global `QueryCache`
`onError` handler and no shared `onError` convention — `app/providers.tsx`
constructs a bare `QueryClient` with only retry/stale-time defaults, nothing
error-related. Every `useMutation` call defines its own inline `onError`,
almost always `toast.error(<one of the fallback patterns above>)`.

**Field-level validation display does not exist.** Grep for
`response?.data?.errors` (Laravel's field-keyed validation payload) finds it
consumed in only 13 files, and in every one of those, only as
`errors?.<field>?.[0]` folded into a single toast string — never rendered
next to the actual input. No form in the app currently keeps a modal open
with per-field inline errors driven by the server response; whatever inline
validation exists today is client-side-only (pre-submit) rather than
server-driven.

**No route-level Next.js `error.tsx` exists anywhere** (`find app -iname
"error.tsx"` returns nothing). Only `app/not-found.tsx` exists (a well-built,
branded 404 page) and one `loading.tsx`
(`app/app/projects/[id]/loading.tsx`). A render-time exception on any route
today shows Next.js's own default error overlay/page, not a SureSign-branded
recovery page — this is a real gap for section 6/19 of the target design
("page-blocking failures" surface).

**Login/auth errors are already reasonably good practice**, if
inconsistent: `app/login/page.tsx` reads `err.response?.data?.message ||
'Login failed. Please check your credentials.'` and renders it inline near
the form (not a toast) — this is actually the right pattern (page-level
inline error, not a toast, for a page-blocking auth failure) and is a good
existing precedent to generalize rather than a gap to fix.

---

## 3. Current Backend Error Architecture

**Exception rendering is Laravel's default**, with exactly one override.
`backend/bootstrap/app.php`'s `withExceptions()` only customizes
`TooManyRequestsHttpException` (429), deliberately generic ("Too many
attempts. Please try again later.") to avoid leaking throttle-key or
account-existence information. Every other exception type — `ValidationException`
(422), `AuthenticationException` (401), an `abort(403, …)` call,
`ModelNotFoundException` (404), and any uncaught `\Throwable` (500) — falls
through to Laravel's stock JSON renderer and stock message text.

**Authorization matches CLAUDE.md's documented convention exactly**: no
Policy classes exist; 65 call sites across controllers use a private
`authorize()`/`authorizeProject()`-style method with `abort(403, 'Access
denied.')` or a more specific message
(`'Only Super Admin can manage Appointment Types.'`,
`'You can only manage your own availability.'`). Laravel passes an
`abort()` message straight through as the JSON `message` field, so these are
already customer-safe by construction — this is a working pattern, not a
gap, though the wording is inconsistent (`'Access denied.'` vs. no message
at all — several `abort(403)` calls with no message argument, which falls
back to Laravel's generic `"Forbidden"`).

**No `FormRequest` defines `messages()` or `attributes()`.** All 54
`app/Http/Requests/*.php` classes rely entirely on Laravel's default
validation message templates and raw attribute names. A required-field
failure on, say, `application_date` returns exactly `"The application date
field is required."` (Laravel does convert snake_case to spaced text, so
this is more readable than raw `application_date`, but it is still
generic, non-labelled, and not guaranteed to match the actual UI field
label). This is a clean, low-risk, high-value fix candidate: adding
`attributes()` overrides changes only copy, never validation logic.

**A real structured-`code` convention already exists — confined to
Billing.** 29 occurrences of `'code' => '...'` were found, effectively all
in `CheckoutController`, `PlanChangeController`,
`SubscriptionCancellationController`,
`OrganizationSubscriptionAssignmentController`, `BillingPortalController`,
and the AI Credits operating-mode endpoints, using values like
`subscription_conflict`, `checkout_unavailable`, `plan_change_conflict`,
`no_longer_cancellable`, `provider_error`, `plan_unavailable`,
`no_change`. `EnsureAccountIsActive` also uses `account_unavailable` (already
consumed by the frontend interceptor — see §2). **No other module has any
structured code at all.** Any Phase B+ work introducing codes for
non-Billing business conflicts (e.g. `payment_application_already_certified`)
should follow this exact existing shape — do not invent a second `code`
convention or field name.

**Domain services throw typed, hand-authored exceptions with customer-safe
messages, and controllers catch by type.** Confirmed across Appointments,
Consultancy, and Billing controllers: `\RuntimeException`,
`\InvalidArgumentException`, and purpose-built classes
(`SubscriptionLifecycleConflictException`, `CheckoutValidationException`,
`ConsultancyConversionRetryableException`,
`ConsultancyManualReviewRequiredException`) are caught individually and
returned as `['message' => $e->getMessage()]` at the appropriate status
(422/409, chosen per call site). No controller was found catching a bare
`\Exception`/`\Throwable` and forwarding `getMessage()` directly — this is
good; it means the messages currently reaching the frontend from these paths
were, at each catch site, deliberately written for a human to read.

Caveat worth carrying into Phase B: catching by `\RuntimeException` or
`\InvalidArgumentException` (PHP's own generic base classes, not
SureSign-specific ones) is safe only as long as **nothing else in that
try-block can throw the same generic type for an unrelated, less-controlled
reason**. This needs a per-call-site check before being relied upon as a
guarantee, not assumed platform-wide from this audit alone.

**One dormant landmine:** `App\Services\Entitlements\FeatureNotEntitledException`
constructs its own message as `"Organisation {$organization->id} is not
entitled to feature \"{$featureKey}\"."` — a raw org ID and raw internal
Feature key, by design, because (per its own docblock) "nothing in the
codebase catches or triggers this yet" except `FeatureGate::requireFeature()`.
The one real caller today, `OrganizationBrandingUrlController`, is
documented in CLAUDE.md as deliberately never surfacing this exception's own
message to the customer. **Any future `requireFeature()` caller must follow
that same discipline** — this exception's `getMessage()` must never reach
`response()->json()` directly.

**AI failure classification is mature but internal-only.**
`App\Support\AI\AiFailureCategory` (validation_failure, provider_rejection,
output_truncated, timeout, transport_error, internal_exception,
insufficient_credits, unknown) is written to `failure_category` on
`ContractAiAnalysis`/`TradePackageAiAnalysis`. Per CLAUDE.md's own
documented discipline (`AiAnalysisPresenter::customerFacing*()` categorically
excludes execution telemetry), this category is not itself exposed to
customers today — the frontend AI failure UX (§ below) needs to be checked
against whatever flat message the customer-facing analysis endpoints
currently return, since the classification exists but this audit did not
find a frontend consumer translating each category into distinct copy.

**A concrete, already-shipped partial-success gap:**
`PaymentApplicationController::certify()` (lines ~590–644) persists the
certification unconditionally, then generates the certificate PDF inside a
`try { … } catch (\Throwable $e) { Log::warning(...); }` block — a
deliberate, correct "don't fail the business record over a PDF" design. But
the method's response is simply `response()->json($paymentApplication->fresh())`
— there is no field indicating whether the PDF generation succeeded. The
customer is told nothing; they'd only discover a missing PDF by trying to
open it separately. This is exactly the "record saved but secondary step
failed silently" pattern flagged as high-priority in the task brief (§12,
§20) — and it is very likely not unique to this one method; other
`DocumentGenerationService::generatePdf()` call sites should be checked the
same way in Phase B/C, not assumed identical without reading each one.

---

## 4. Existing Shared Helpers (do not duplicate)

| Helper | Location | Covers | Gap |
|---|---|---|---|
| Axios instance + response interceptor | `frontend/src/lib/api.ts` | 401 session expiry, `account_unavailable` 403 | No generic message/type extraction for other statuses |
| `getErrorMessage(error, fallback)` | `frontend/src/lib/getErrorMessage.ts` | `response.data.message` extraction | Duplicated 7×; no field errors, no classification, no retryability |
| `code` JSON field | Billing controllers only | Deterministic conflict identification | Not used anywhere outside Billing |
| Typed service exceptions + per-controller `catch` | Appointments/Consultancy/Billing controllers | Hand-authored customer-safe messages | Inconsistent status codes; not extended to Commercial/Programme/Documents modules |
| `AiFailureCategory` | `App\Support\AI\AiFailureCategory` | Backend-only AI failure taxonomy | Not translated into customer-facing copy anywhere found |
| `not-found.tsx` (root) | `frontend/src/app/not-found.tsx` | Branded 404 | No equivalent `error.tsx` for render exceptions; no page-specific 404s for e.g. a deleted project |
| Inline auth error (login page) | `app/login/page.tsx` | Page-level inline error display | Good pattern, not reused/generalized elsewhere |

**Conclusion for §4 of the task brief:** SureSign has **several partially-shared
paths**, not one unified path and not purely page-specific handling. The
foundation work is consolidation of what already exists (one Axios
interceptor, one canonical `getErrorMessage`, one `code` convention) rather
than invention from nothing.

---

## 5. P0 Findings (security / privacy / data-state risk)

| # | Finding | Evidence | Risk |
|---|---|---|---|
| P0-1 | `FeatureNotEntitledException` message embeds raw organisation ID + internal `Feature` key | `app/Services/Entitlements/FeatureNotEntitledException.php:22-24` | Currently unreached by any response that forwards `getMessage()` to JSON — but the class has no built-in safeguard, so the *next* `requireFeature()` caller could leak it by simply doing what several other controllers already do (`catch` + `$e->getMessage()`). Needs a structural fix (e.g. a fixed customer-safe message on the exception itself, or a documented, enforced rule) before any new caller is added, not a per-caller reminder. |
| P0-2 | `APP_DEBUG=true` in `.env.example` | `backend/.env.example:4` | Not itself a leak (example file, and `config/app.php` defaults to `false`), but worth an explicit one-line confirmation that production's real `.env` has `APP_DEBUG=false` before this work ships, since a `true` value would make Laravel's default handler return full stack traces in JSON error bodies for every unhandled exception. Verification, not a code change. |

No other raw stack traces, SQL error text, filesystem paths, or provider
payloads were found reaching a customer-facing response in the sampling
performed. This does not certify every one of the ~64 `getMessage()` call
sites individually — see §3's caveat on generic-exception catches — a
targeted per-call-site pass is recommended for Phase B/C of whichever module
each sits in, not a blanket re-audit here.

---

## 6. P1 Findings (customer cannot understand or recover)

| # | Finding | Impact |
|---|---|---|
| P1-1 | Zero `FormRequest` classes have `messages()`/`attributes()` | Every validation failure across the entire platform shows Laravel's generic auto-text against the raw field name, with no field-specific UI cue |
| P1-2 | No inline, field-anchored validation UI anywhere | Every validation error is a toast; a user with 3 invalid fields sees one flat sentence, not 3 field-level cues, and per the task brief's own "best" example this is exactly what should change first |
| P1-3 | `PaymentApplicationController::certify()` gives no signal when certificate PDF generation fails | Certification (the business-critical part) is correctly saved, but the customer has no way to learn the PDF didn't generate except manually checking |
| P1-4 | 8 duplicated `getErrorMessage()` implementations, all shallow | A shared foundation is needed and is a mechanical consolidation, not new invention — but until consolidated, any fix to one copy (e.g. adding field-error support) will not propagate to the other 7 |
| P1-5 | No route-level `error.tsx` boundary anywhere | A render-time JS exception on any page shows an unbranded Next.js error screen instead of a recoverable SureSign page |
| P1-6 | AI failure category (`AiFailureCategory`) exists on the backend but was not found translated into distinct customer copy | Customers likely see one flat "Failed to analyse" regardless of whether the cause was a timeout, a truncated response, or insufficient credits — needs a Phase D (AI) investigation, not fixed here |

---

## 7. P2 / P3 Findings (inconsistent / cosmetic)

| # | Finding | Severity |
|---|---|---|
| P2-1 | Fallback toast wording is independently authored per call site (`'Failed to save.'`, `'Failed to save risk'`, `'Failed to save availability.'`, …) — same failure class worded differently by module | P2 |
| P2-2 | Status code choice for "current-state conflict" varies (409 in some Appointments/Consultancy paths, 422 in others) for what reads as the same semantic case | P2 |
| P2-3 | Some `abort(403)` calls pass no message at all (falls back to Laravel's generic `"Forbidden"`), while sibling call sites in the same controller pass `'Access denied.'` | P2/P3 |
| P2-4 | Toast duration/style is whatever `react-hot-toast` defaults to per call — no shared category-based styling (e.g. permission vs. validation vs. network) | P3 |

---

## 8. Validation Findings

- Backend: validated via `FormRequest::rules()` only; no `messages()`/`attributes()`
  anywhere (§3, §6 P1-1).
- Frontend: no consistent mapping of `error.response.data.errors` (the
  Laravel field-keyed payload) to inline UI; the 13 files that touch it at
  all only extract a single field's first message into a toast fallback.
- No evidence found of modals closing on a failed submit (a good sign — this
  audit did not find a case where entered values were lost), but this was
  sampled, not exhaustively verified across every modal; Phase B/C should
  spot-check the highest-traffic forms (Payment Application, Variation,
  Contract creation) specifically for this.
- No shared "first invalid field gets focus" behavior exists — this is a
  reasonable, additive nice-to-have for the shared foundation, not required
  by the current API shape.

**Recommended rule** (matches the task brief's own §6/§9 guidance, and fits
what's already there): field-specific errors render inline once a form
knows which field failed (requires §3's `attributes()` fix so the message
text is usable); a toast is reserved for "Check the highlighted fields"
summary wording, never duplicating the full per-field message.

---

## 9. Permission / Tenant-Safety Findings

- The `authorize()`/`abort(403, …)` convention (CLAUDE.md, confirmed at 65
  call sites) is already tenant-safe by construction — none of the sampled
  messages name another organisation, another tenant's record, or expose an
  ID belonging to a different org.
- `FeatureNotEntitledException` (P0-1) is the one exception-shaped exception
  to this — flagged above, not yet live.
- No case was found where a 404 was used to mask a genuine cross-tenant 403
  inconsistently with a sibling endpoint doing the opposite — but this audit
  did not exhaustively diff every pair of similar endpoints; recommend this
  specific check (403-vs-404 consistency for the same resource type) as a
  standing item for whichever module's Phase B/C batch touches it, not a
  separate pass now.
- No authorization behavior is recommended to change — per the task brief's
  explicit instruction, and consistent with `[[project_admin_org_scoping_gap]]`
  (Admin is platform-wide, not org-scoped — any future tenant-safety wording
  work must preserve that distinction, not flatten Admin into "another
  tenant" language).

---

## 10. Partial-Success Findings

Confirmed, concrete example: `PaymentApplicationController::certify()` (§3,
P1-3) — record save is transactional and correct; PDF generation is
deliberately non-blocking and correctly isolated in its own `try/catch`; but
the outcome of that second step is never returned to the frontend.

Given CLAUDE.md's own documentation of the Consultancy Communications
architecture (`ConsultationCommunicationService`, dispatched
`SendConsultationCommunicationJob`s, "non-fatal by design" email/Google-sync
jobs), the *design intent* platform-wide already matches the task brief's
philosophy — record-save is never rolled back over a secondary step. The gap
found in this audit is narrower than "the architecture is wrong": it's that
**the immediate synchronous response frequently doesn't say whether a
same-request secondary step (like PDF generation) succeeded**, whereas
async, job-queued secondary steps (email, Google Calendar sync) are
correctly decoupled and were not found to make any false claim in their own
immediate response.

**Recommendation for Phase B/C:** audit each `DocumentGenerationService::generatePdf()`
call site individually (5 controllers found: `DocumentTemplateController`,
`TradePackagePackageGenerationController`, `ReportController`,
`PaymentApplicationController`, `DocumentController`) for the same
save-succeeds/generation-fails gap before deciding on a single fix shape —
some may already return the generation outcome and this audit's one
confirmed example may not generalize to all five without checking.

---

## 11. Provider-Error Findings

Stripe (`ApiErrorException` caught explicitly in `CheckoutController`),
Consultancy's own retry/manual-review exceptions
(`ConsultancyConversionRetryableException`,
`ConsultancyManualReviewRequiredException`), and the
`SubscriptionLifecycleConflictException` family are all caught by type with
hand-authored messages — this is the right shape already. Google Calendar/Meet
and Brevo/email failure surfacing was not independently traced end-to-end in
this pass (both are documented in CLAUDE.md as async, job-based, non-fatal —
consistent with the task brief's own recommended treatment) — a dedicated,
narrower check of what (if anything) a customer sees when a Google sync
permanently fails after retries is recommended as a Phase D item, not
concluded here either way.

---

## 12. Authentication Findings

Already a comparatively strong area: `app/login/page.tsx` uses an inline,
page-level error (not a toast) — the correct surface per the task brief's
own §19 rule — falling back to a plain, non-technical message. Session
expiry and account-deactivation are already handled centrally in the Axios
interceptor (§2). Not audited in this pass: exact wording shown for a
disabled/banned account, email verification, and password reset — these
should be a quick, low-risk confirmation pass in Batch 1 rather than a fresh
investigation, since the plumbing (`account_unavailable` code,
`getErrorMessage`) already exists.

---

## 13. Commercial Findings

Not deeply inventoried string-by-string in this pass (reserved for Batch 3
per §11's recommended batching) beyond the two concrete items already
surfaced: `PaymentApplicationController::certify()`'s silent PDF gap (§3,
§10) and the general absence of a `code` field outside Billing, which is the
main blocker to giving Commercial conflicts (e.g. "already certified,"
"final account locked") the same deterministic frontend handling Billing
conflicts already get.

---

## 14. Document-Generation Findings

Covered in §3/§10. Five call sites identified for a per-site check in
Phase B/C: `DocumentTemplateController`, `TradePackagePackageGenerationController`,
`ReportController`, `PaymentApplicationController`, `DocumentController`.

---

## 15. Consultancy / Appointments Findings

This module has the **most mature backend error-handling of any module
audited** — typed exceptions per failure mode
(`ConsultancyConversionRetryableException`, `ConsultancyManualReviewRequiredException`,
booking-conflict `RuntimeException`s with hand-written messages), consistent
use of 409 for state conflicts, and (per CLAUDE.md) explicitly non-fatal,
job-queued communications so a booking's own success is never held hostage
by a Google/email failure. The main gap here is the same as everywhere else:
no field-level inline validation and no shared frontend classification —
each of Appointments' ~15 `catch` blocks already has a good message; the
frontend has no shared way to know it's a "conflict" versus a "validation"
versus a "provider" failure beyond reading the status code ad hoc.

---

## 16. Billing Findings

Billing is the one module that already has almost everything the task brief
asks for: structured `code` values, typed exceptions, `ApiErrorException`
handling for Stripe specifically, and read-only presentation boundaries
(`BillingPresenter`) that already exclude raw provider internals from
customer-facing responses. Recommend Billing be treated as the **reference
implementation** for the rest of the platform to converge toward, rather
than a target of significant rework itself.

---

## 17. AI Findings

Backend classification (`AiFailureCategory`) is mature; this audit did not
find where — or whether — that classification reaches customer-facing copy
today (CLAUDE.md's own confirmed discipline is that
`AiAnalysisPresenter::customerFacing*()` excludes execution telemetry
categorically, which is correct for cost/tokens/duration, but a failure
*category* like `output_truncated` is a different kind of information than
telemetry — whether it's currently exposed at all needs a direct check of
`AiController`'s customer-facing analysis-status endpoint, not assumed from
this pass). Flagged as a Phase D investigation item, not a confirmed gap.

---

## 18. Google / Email Findings

Not independently traced end-to-end (see §11). Existing architecture
(job-queued, non-fatal, documented in CLAUDE.md) already matches the target
philosophy in principle; whether the customer is ever told a permanent
failure occurred (vs. only ever silent retries) is the open question for
Phase D.

---

## 19. Admin / Super Admin Findings

Not inventoried in this pass — correctly last in the priority order (§11 of
the task brief, Batch 7). Spot checks during other searches (e.g.
`app/admin/notifications/page.tsx`'s five `onError: () => toast.error('Failed
to ...')` mutations) suggest the same toast-only, per-call-site-fallback
pattern as the rest of the platform — no evidence of anything worse (no raw
stack traces surfaced), consistent with the rest of the audit.

---

## 20. Existing Structured Error-Code Findings

Confirmed and detailed in §3: `code` exists, is real, and is confined to
Billing + `account_unavailable`. This is the vocabulary any Phase B+ work
must extend, not replace or duplicate.

---

## 21. Recommended Error Taxonomy

Matches the task brief's proposed categories almost exactly, because the
codebase's real failure shapes already sort cleanly into them — no category
needed to be forced or dropped:

1. **Validation** — `FormRequest` 422s (needs `attributes()`/`messages()` —
   §3, §6)
2. **Permission / Access** — `abort(403, …)` convention (already tenant-safe
   — §9)
3. **Not found** — `ModelNotFoundException`/`abort(404)` (Laravel default,
   unaudited for consistency)
4. **Conflict / current state** — the typed-exception + `code` pattern
   already used in Billing/Consultancy/Appointments (§3, §15, §16) — extend
   its `code` vocabulary module-by-module, never invent a second mechanism
5. **Network** — not yet handled anywhere (no evidence of an `ERR_NETWORK`/
   no-response case being distinguished from a real 4xx/5xx in any file
   sampled) — a genuine gap for the shared utility to close
6. **Temporary service failure** — no distinct handling from generic 500 found
7. **Provider failure** — Stripe/`ApiErrorException` handled; Google/Brevo
   unaudited (§11, §18)
8. **Document generation** — the one concrete gap in §10/§14
9. **AI failure** — category exists backend-only (§17)
10. **Unexpected server error** — Laravel default (no leak found in sampled
    call sites, but see §5's caveat on generic-exception catches)

---

## 22. Recommended Shared Normalization Architecture

Given §2–§4's findings, the smallest correct move is **B + C combined**
from the task brief's own menu, deliberately *not* A alone (extending
`lib/getErrorMessage.ts` in place would still leave the other 7 duplicates
un-migrated) and *not* a new framework:

1. **Consolidate the 8 `getErrorMessage()` implementations into the one
   canonical `lib/getErrorMessage.ts`**, delete the other 7, and extend its
   signature (not its name/location — this keeps the diff small and the
   import churn mechanical) to return a small structured shape instead of a
   bare string:
   ```ts
   type NormalizedApiError = {
     type: 'validation' | 'permission' | 'not_found' | 'conflict' | 'network'
         | 'provider' | 'server' | 'unknown';
     title?: string;
     message: string;
     fieldErrors?: Record<string, string[]>;
     code?: string;        // maps directly to the backend's existing `code` field
     retryable: boolean;
   };
   ```
   `type` is derived from the HTTP status (`422`→validation, `403`→permission,
   `404`→not_found, `409`→conflict, no response→network, `5xx`→server), with
   `code` (when present — today, Billing only) taking priority over the
   generic status-based guess for `conflict`/`permission` distinctions.
2. **Extend `lib/api.ts`'s existing response interceptor** only for what is
   genuinely universal (network-error detection, generic 500 wording) —
   leave per-module message text where it already lives, per the task
   brief's own §25 instruction not to over-centralize.
3. **Do not touch Billing's existing `code` handling** — it already works;
   the new utility should simply read the same field other modules don't
   populate yet.
4. **Add `attributes()` to `FormRequest` classes incrementally**, module by
   module in the batch order below — copy-only change, no rule changes, and
   the highest safety-to-value ratio in this entire audit.

This keeps the abstraction small (one type, one function, one interceptor
extension) and reuses 100% of what already works.

---

## 23. Recommended Backend Contract Changes

- **No change to the JSON response shape is required** — `message` +
  `errors` (Laravel's existing 422 shape) + the existing optional `code`
  field cover every category in §21 once `code` is populated for
  non-Billing conflicts too.
- **Add `code` values for the highest-value Commercial state conflicts
  first** (Batch 3), following the exact naming style already established
  by Billing (`snake_case`, verb-free, e.g. `payment_application_already_certified`)
  — do not invent a differently-shaped identifier.
- **Add `attributes()`/`messages()` to `FormRequest` classes** module by
  module, starting with the highest-traffic forms (Payment Application,
  Variation, Contract).
- **Do not change any status code** platform-wide as part of this — §7's
  P2-2 (409 vs 422 inconsistency) is real but re-choosing status codes is a
  breaking-enough change to defer to whichever module's batch actually
  touches that endpoint, with its own explicit review, not a blanket pass.

---

## 24. Recommended Copy Standard

Adopt the task brief's own style guide as written (§10, §24 of the original
prompt) — it already matches the one clearly-good existing example found in
this audit (the login page's inline, plain-English fallback). No changes
recommended to that guide; it should be transcribed into
`CLAUDE.md`'s Frontend Guidelines once Batch 1 ships, per
`[[feedback_update_context_and_docs_per_change]]`.

---

## 25. Proposed Implementation Batches

Matches the task brief's own suggested shape, adjusted only where this
audit found a reason to reorder within a batch:

- **Batch 1 — Shared foundation + Auth.** Consolidate `getErrorMessage()`
  (§22), extend the Axios interceptor for network/5xx, add `attributes()` to
  the Auth-related `FormRequest`s, confirm disabled-account/verification/reset
  wording (§12).
- **Batch 2 — Projects + Trade Packages.** Add inline field-error rendering
  to the highest-traffic forms first (this is where the new utility earns
  its keep).
- **Batch 3 — Commercial + Documents.** Extend `code` to Commercial state
  conflicts (Payment Application, Variation, Final Account); fix the
  confirmed PDF-generation partial-success gap (§3, §10) across all five
  identified call sites, checked individually.
- **Batch 4 — Consultancy/Appointments.** Lowest-risk batch — mostly
  translating already-good backend messages through the new shared type
  rather than rewriting them.
- **Batch 5 — Programme/Delay-EOT/operational modules (RFIs, Meetings, QA,
  Snagging, Site Reports, Closeout).** Not separately audited yet — first
  real investigation happens at the start of this batch.
- **Batch 6 — Billing + AI + Google + Email.** Billing needs the least work
  (§16); AI needs the failure-category-to-copy investigation first (§17);
  Google/Email need the end-to-end trace deferred from §11/§18.
- **Batch 7 — Admin/Super Admin cleanup.** Lowest customer impact by
  definition (internal users only) — last, as the task brief specifies.

---

## 26. Estimated Blast Radius

- **Batch 1 foundation work:** ~10 file changes (delete 7 duplicate
  functions, extend 1 canonical one, extend 1 interceptor, add `attributes()`
  to a handful of Auth `FormRequest`s). Low risk — additive/consolidating,
  no behavior change to what's already correct.
- **Full platform migration (all batches):** touches the majority of the
  ~54 `FormRequest` classes and a large fraction of the 76+ files using
  `useMutation`/`useQuery` — this is genuinely platform-wide, matching the
  task brief's own framing, and is why it must stay batched rather than
  attempted in one pass.

---

## 27. Risks

- **Per-call-site exception-type verification is not exhaustive.** §3's and
  §5's caveats about generic `\RuntimeException`/`\InvalidArgumentException`
  catches mean each Phase B/C batch must re-check its own module's catch
  blocks before assuming every message reaching the frontend today is
  already safe.
- **Status-code changes are riskier than copy changes** and must not be
  bundled into wording fixes without their own explicit review (§23).
- **The `FeatureNotEntitledException` landmine (P0-1)** should be closed
  structurally before the entitlement/billing work already flagged as
  upcoming in CLAUDE.md (e.g. any future `requireFeature()` caller) rather
  than left as a per-caller discipline item.
- **No frontend automated test framework exists** (confirmed, matches the
  task brief's own §27 assumption) — Phase B+ verification will rely on
  TypeScript/ESLint/production Docker build plus manual browser validation,
  per `[[feedback_nextjs_build_validation_method]]`.

---

## 28–35. Files Added / Modified / Tests / Build Results

No files were added or modified in Phase A other than this audit document.
No tests were added (none applicable — read-only audit). No TypeScript,
ESLint, or Docker build was run, as no code changed.

---

## 36. Browser Validation Performed

None — Phase A is read-only per the task brief's own instruction (§28 of the
original prompt describes Phase A/B+ validation; this phase performed static
code audit only).

---

## 37–39. Validation Boundaries / Documentation Updated / Remaining Legacy Messages

- This document is the only documentation change in Phase A.
- `CLAUDE.md`'s Frontend Guidelines should gain a short "Error Messaging &
  Recovery UX" standard once Batch 1 ships (§24) — not yet done, correctly
  deferred per the task brief's own §12 instruction for this phase.
- Every duplicated/inconsistent message catalogued in §2, §6, and §7 remains
  unchanged in the codebase as of this report.

---

## 40. Recommended Next Batch

**Batch 1 — Shared foundation + Auth**, exactly as scoped in §25, is ready
to start on explicit approval. It is additive/consolidating, touches no
business logic, and is the dependency every later batch needs (the new
`getErrorMessage` return shape and the extended interceptor).

---

## 41. Final Production-Readiness Assessment

**Not blocking for the platform's approach to monitored customer
onboarding**, but a real, worthwhile investment: today's failures are mostly
*honest* (no confirmed raw stack traces, no confirmed cross-tenant leaks
beyond the one dormant P0) but frequently *unclear* (generic validation
text, silent PDF-generation gaps, inconsistent toast wording) rather than
actively broken or unsafe. Recommend proceeding to Batch 1 on explicit
approval, per the task brief's own instruction not to auto-start
implementation after this audit.

---

## Batch 1 Implementation Status (Shared Foundation + Authentication + P0)

**Shipped:**

- `frontend/src/lib/normalizeApiError.ts` — the canonical structured
  normalizer (`normalizeApiError()`), with `getErrorMessage()` kept as a
  backward-compatible string accessor on top and re-exported unchanged from
  `frontend/src/lib/getErrorMessage.ts` (the ~30 existing importers of that
  path needed no changes). `getFieldError()` also added for future
  per-field lookups.
- 6 of the 8 duplicate `getErrorMessage()` implementations consolidated
  onto the canonical one (2 via direct import, 2 via re-export from files
  that other modules import the helper from,1 replaced a divergent
  no-fallback signature with a thin wrapper). 1 (`delay-eot/page.tsx`) was
  deliberately **not** consolidated — it has a genuine behavioural
  difference (falls back to a bare `Error`'s own `.message`, needed for
  that file's `assertDeleteSucceeded`) that the canonical helper
  intentionally does not replicate platform-wide, to avoid ever surfacing
  an arbitrary JS runtime error message elsewhere. Documented in-file and
  in `normalizeApiError.ts`'s own docblock.
- `frontend/src/lib/api.ts`'s existing interceptor extended (not replaced)
  to carry a two-value, non-sensitive `?authNotice=` reason code
  (`session_expired` | `account_unavailable`) on its existing redirect —
  no new global toast behaviour was introduced, per the task's explicit
  instruction.
- `frontend/src/app/login/page.tsx` — the failure catch path now goes
  through `normalizeApiError()`; a login-specific message only overrides
  the normalizer's generic one for a genuine server (5xx) failure. Added
  `aria-invalid`/`aria-describedby` + inline per-field error text driven by
  a real 422 `fieldErrors` map (safety net beyond native `required`). Added
  the `authNotice` banner (session expiry / account unavailable), read and
  stripped from the URL on mount, matching the existing `brandHost`
  stripping convention in the same file.
- `frontend/src/components/auth/ForcePasswordChangeGate.tsx` consolidated
  onto the shared helper.
- `frontend/src/app/error.tsx` — the platform's first route-level error
  boundary (confirmed absent in Phase A). Deliberately a plain `error.tsx`,
  not `global-error.tsx` — the latter replaces `<html>/<body>` entirely and
  would drop `DemoBanner`/theme bootstrap/`QueryClientProvider`/`Toaster`;
  a plain `error.tsx` renders inside the existing root layout and covers
  the confirmed gap (any page/nested-layout render exception) without that
  risk.
- **Backend P0 fix**: `App\Services\Entitlements\FeatureNotEntitledException`
  no longer embeds the raw organisation ID or raw internal `Feature` key in
  `getMessage()` — now a fixed, generic, customer-safe sentence. The
  organisation/feature key remain fully available via existing typed
  properties plus a new `logContext(): array` helper, for server-side
  logging only. Added `errorCode` (named to avoid colliding with the base
  `Exception::$code` property — attempting to redeclare it as `readonly`
  is a PHP fatal error, caught and fixed during this batch's own
  verification) = `'feature_not_entitled'`, matching the existing Billing
  `code` convention. No entitlement/`FeatureGate`/snapshot/pricing/AI
  Credits semantics changed — the exception has no real caller yet (see
  its own docblock).
- **AuthController** (`backend/app/Http/Controllers/Api/AuthController.php`):
  login's invalid-credentials message changed from `'Invalid credentials.'`
  to `'The email or password is incorrect.'` (still identical
  wording/status for an existing vs. non-existent email — enumeration
  safety unchanged, test updated to match). Login's deactivated/banned
  checks merged into one branch returning the **same** message and
  `code: 'account_unavailable'` as the pre-existing mid-session
  `EnsureAccountIsActive` middleware check — previously these were two
  differently-worded, uncoded 403s for the same underlying account state
  (Phase A's P2-1 finding, now closed for this specific case).
  `EnsureAccountIsActive`'s own wording/code was left untouched (it's
  covered by existing, passing tests asserting its exact string) — the
  login-time checks were changed to match it, not the other way round.
- **Tests added**: `AccountStatusTest` gained 3 new tests covering
  login-time `account_unavailable` (deactivated, banned, and "no token
  issued"); `FeatureGateTest` gained 1 new test asserting the exception no
  longer leaks organisation ID/feature key while confirming the internal
  properties/`logContext()` still do; `AuthRateLimitingTest`'s 2 existing
  assertions updated to the new invalid-credentials wording.
- **APP_DEBUG verification**: `docker-compose.prod.yml` sets
  `APP_DEBUG=${APP_DEBUG:-false}` — safe by default in the checked-in
  production deployment config; a deploy-time environment override would
  be required to change this. The actual live Dokploy deployment's
  environment variable value was **not independently verifiable** from
  this environment (no production access) — recorded here as an
  unverified-but-safe-by-default deployment check, per the task's own
  fallback instruction.

**Deliberately not done in Batch 1** (in scope for later batches, not
regressions):

- No `FormRequest` classes exist for Authentication (`AuthController` uses
  inline `$request->validate()`) — there was nothing to add
  `attributes()`/`messages()` to. The only fields involved (`email`,
  `password`, `token`, `current_password`) are already single, readable
  words; Laravel's `current_password`/`confirmed` rule messages are already
  customer-safe ("The password is incorrect." / "The password field
  confirmation does not match.") and were left unchanged.
- Password reset / email verification pages
  (`forgot-password`/`reset-password`/`verify-email`) were already using
  the canonical `getErrorMessage()` before this batch and needed no
  changes — confirmed during pre-implementation verification, not assumed.
- Live browser validation was **not performed** — no browser automation
  tool was available in this session. Verification relied on TypeScript
  (`tsc --noEmit`, clean), ESLint (compared against each touched file's
  pre-existing baseline — zero new errors introduced; one new
  `react-hooks/set-state-in-effect` instance in `login/page.tsx` matches an
  already-unsuppressed pattern used twice elsewhere in the same file), the
  full backend test suite (2404 tests; the same 4 failures exist with this
  batch's changes fully reverted — confirmed via `git stash` — all four are
  pre-existing sandbox/environment issues: a storage-directory permission
  error and two environment-dependent Consultancy URL/HTTP-mock tests,
  none related to Authentication or error handling), and a real
  multi-stage production Docker build (`docker build -f frontend/Dockerfile
  frontend`), which completed successfully including the new `/error`
  route.
- No structured `code` was added to password-reset/email-verification 422s
  — their messages are already specific and safe; no deterministic
  frontend behaviour would benefit from a code yet (per the task's
  explicit "don't add codes to every 422" instruction).

---

## Batch 2 Implementation Status (Projects + Trade Packages)

Frontend-only — no backend files touched (Projects/Trade Packages use
inline `$request->validate()` in their controllers, same as
Authentication; no `FormRequest` classes exist to add `attributes()` to,
and none were introduced this batch).

**Shipped:**

- `frontend/src/app/app/projects/page.tsx` (`CreateProjectModal`) — the
  platform's first real per-field inline validation example outside
  Authentication. `onError` now goes through `normalizeApiError()`; a
  validation-type failure shows a short "Check the highlighted
  information." summary in the existing banner (never the same text
  twice) while `name`, `end_date`, and `contract_value` each get their own
  `aria-invalid`/`aria-describedby` + inline message from the real 422
  `fieldErrors` map. `end_date`'s message is a hand-written, customer-safe
  sentence ("The completion date cannot be earlier than the start date.")
  rather than the backend's raw `after_or_equal` validation text — the
  same choice the original task brief's own worked example makes for an
  analogous date-ordering rule; this is the field's only realistic
  validation rule today, so hardcoding the friendly equivalent was judged
  lower-risk than surfacing Laravel's default message. Non-validation
  failures (network/server/permission) still show their own specific
  message in the banner, since there's no field to attach them to. Entered
  values, modal-open state, and success/loading behaviour are all
  unchanged.
- `frontend/src/app/app/projects/[id]/subcontracts/[packageId]/page.tsx`
  — 4 mutations (add milestone, remove milestone, re-parse analysis, update
  trade package) previously called `toast.error()` with a **hardcoded
  string only**, silently discarding whatever the backend actually
  returned. All 4 now go through the existing `getErrorMessage()` helper —
  the same fixed fallback text still shows when the backend has nothing
  more specific, but a real, specific backend message (e.g. a state
  conflict) now reaches the user instead of being thrown away. Zero UI
  change when the backend returns no message, by design.
- Reviewed (not changed): `RisksTab.tsx`/`DeliveryDocumentsTab.tsx`
  (Trade Package tabs) and the trade-package edit modal
  (`page.tsx`'s own settings form) — all three already use the shared
  helper (from Batch 1's consolidation) and are toast-only by an existing,
  reasonable design (no inline-error UI architecture exists in those forms
  to retrofit safely within this batch's scope). Left untouched rather
  than force-fitting new UI onto them.

**Verification:** TypeScript clean. ESLint: `projects/page.tsx` has zero
issues (before and after); the trade-package detail page's pre-existing 29
problems (all `@typescript-eslint/no-explicit-any`/one unused-var, none
touched by this batch) are byte-identical before and after, confirmed via
direct baseline comparison. Backend fully unchanged — no backend test run
needed for this batch. Production Docker build succeeded. `git diff
--check` clean. Live browser validation again not performed (no browser
automation tool available in this session).

---

## Batch 3 Implementation Status (Commercial + Documents)

Scoped narrower than the original Batch 3 recommendation on inspection:
the structured-`code` extension to Commercial state conflicts was
**deferred**, not shipped — every state-conflict message found in this
pass (`'Only submitted applications can be certified.'`,
`'Application must be certified to generate a certificate.'`, etc.) is
already a specific, customer-safe sentence; adding a `code` to each would
help a future deterministic frontend action that doesn't exist yet, so
per this initiative's own "don't add codes without a real consumer" rule
(§20 of the original task brief), none were added this batch. The
confirmed silent-PDF-generation gap **was** fixed — that was the
concrete, real bug this batch existed to close.

**Shipped:**

- **`PaymentApplicationController::certify()`** (the audit's original P1-3
  finding) — now returns `certificate_generated: bool` merged onto the
  existing flat response shape (never nested, so any other consumer of
  this endpoint's response is unaffected). Certification itself remains
  unconditionally saved regardless of PDF outcome — no change to that
  transaction boundary, only to what the response reveals about the
  secondary step. Frontend (`commercial/page.tsx`'s certify modal) now
  shows "Application certified, but we couldn't generate the certificate
  PDF. Try generating it again from the application." when generation
  failed — a real recovery action (`POST
  .../generate-certificate` already exists), not an invented one.
- **Two further, more severe instances of the same bug class**, found
  during this batch's own audit (not in the original Phase A sample):
  `createPaymentNotice`/`createPayLessNotice` in the same controller
  already returned a `document: null` signal on PDF failure, but the
  frontend (`commercial/page.tsx`) **unconditionally showed "…PDF saved to
  documents" regardless of whether `document` was null** — actively
  telling the customer something succeeded that hadn't. Fixed to check
  the real signal and show an honest message ("...issued, but we couldn't
  generate the PDF. Contact SureSign support if you need the document.")
  when generation failed — no dedicated regenerate endpoint exists for
  either notice type, so no recovery action is invented for these two
  specifically (unlike the certificate case above, which has one).
- **`components/documents/ProjectDocumentsExplorer.tsx`** — 4 bare
  `catch`/`onError` blocks (file upload, delete, 2× download) previously
  discarded the backend's actual message in favour of a fixed string,
  same pattern fixed for Trade Packages in Batch 2. All 4 now use the
  shared `getErrorMessage()` helper.

**Investigated, not fixed — flagged for a future Documents batch:**

- `ProjectDocumentsExplorer.tsx`'s three list queries (folders/files/
  documents) each do `.catch(() => ({ data: [] }))` — any failure,
  including a genuine 500 or network outage, is silently rendered as "no
  documents" rather than a load failure. Not fixed this batch: doing so
  safely requires checking how each of the three consumers currently
  renders an empty result versus what an explicit `isError` state would
  need to look like, which is a larger investigation than this batch's
  remaining scope — recommended as a named follow-up, not silently
  dropped.
- The other 4 `DocumentGenerationService::generatePdf()` call sites in
  `PaymentApplicationController` (`generatePdf()`/`generateCertificate()`,
  the two dedicated regenerate endpoints) do not catch a generation
  failure at all — a genuine failure 500s the whole request, which is
  arguably correct for those two specifically (the document IS the
  business record being requested there, so there's no separate
  "record saved" to protect) — reviewed and left as-is, not a gap.
- `FinalAccountController`'s 2 and `VariationController`'s 1
  `generatePdf()` call sites were not audited this batch — flagged for
  whichever future batch covers Final Account/Variations specifically.

**Tests added:** `PaymentApplicationCertificationPartialSuccessTest` — 2
new tests, both forcing a **real** generation failure/success via the
`feature_document_generation` kill switch `DocumentGenerationService`
itself checks first (no mocking, following the same real-failure
convention as the pre-existing `PaymentApplicationExcelDisclosureTest`).
Confirms certification always succeeds regardless of PDF outcome, and
that `certificate_generated` accurately reflects each case.

**Verification:** Targeted backend tests 10/10 passed
(`PaymentApplicationCertificationPartialSuccessTest` +
`Batch4PaymentApplicationsTest`); the wider
`PaymentApplicationExcelDisclosureTest` re-run showed the same single
pre-existing, environment-specific failure already confirmed unrelated in
Batch 1. TypeScript clean. ESLint: `commercial/page.tsx` and
`ProjectDocumentsExplorer.tsx` both show byte-identical problem counts
before/after (3 and 3 respectively — all pre-existing). Production Docker
build succeeded (built twice, once per file group). `git diff --check`
clean. Live browser validation again not performed (no browser automation
tool available in this session).

**Recommended Batch 4 scope**: Consultancy + Appointments, per the
original plan — plus the two flagged-not-fixed Documents findings above
as a named follow-up whenever the Documents module gets its own full
pass.

---

## Batch 4 Implementation Status (Consultancy + Appointments)

Confirmed on inspection: this module already has (per Phase A §15) the
most mature **backend** error handling of any module audited — typed
exceptions, consistent 409 usage, correctly non-fatal async
communications. No backend files were touched this batch; every fix was
frontend-only, either consolidating onto the shared foundation or
correcting a customer-facing honesty gap the backend's own already-good
data made possible to fix.

**Shipped:**

- **`app/app/consultations/new/page.tsx`** (the customer booking form) —
  already had the platform's best existing inline-field-validation
  precedent (a real `error` prop on every `Input`, driven by the 422
  `errors` object) before this batch touched it. Only the top-level
  `onError` was changed, to route through `normalizeApiError()` instead of
  a raw `data.message` read — preserves the existing field-error behaviour
  exactly, closes the same "blindly trusts a 5xx message" gap fixed
  elsewhere this initiative.
- **`app/app/consultations/[id]/page.tsx`** — found and fixed a real,
  previously-unnoticed inaccuracy: the "Meeting Status" card showed
  identical copy ("Preparing Meeting Link…") for both the `pending` and
  `temporarily_unavailable` states, even though
  `ConsultationMeetingPresenter`'s own docblock defines them as distinct
  ("Meet is being prepared" vs. "the Calendar event itself doesn't exist
  yet — queued/retrying/disconnected, a longer and less certain wait").
  Now shown separately, with `temporarily_unavailable` explicitly
  reassuring the customer the consultation itself is still confirmed —
  the closest real-world match in this codebase to the original task
  brief's own "we couldn't create the Google Meet link yet, your
  consultation is still booked" worked example. The `unavailable` state's
  existing behaviour (the whole card hidden entirely) was left
  untouched — flagged below as a judgment call, not fixed blindly.
  Cancel mutation also consolidated onto the shared helper.
- **8 admin-side Consultancy/Appointments operator screens** — 20
  mechanical occurrences of the same
  `err?.response?.data?.message ?? 'fallback'` pattern (identical shape to
  every other instance fixed across this initiative) converted to
  `getErrorMessage()`, applied via a scripted, verified substitution
  (each occurrence individually confirmed correct, not a blind find/replace
  left unchecked): `admin/consultancy/{services,settings,availability,queue/[id]}/page.tsx`
  and `admin/appointments/{page,types,availability,[id]}/page.tsx`. Same
  fallback text preserved in every case — this is infrastructure
  consolidation, not a copy rewrite.

**Reviewed, deliberately not changed:**

- The `unavailable` meeting status still hides the "Meeting Status" card
  entirely rather than showing an explicit failure message. Per
  `ConsultationMeetingPresenter`'s own docblock this status "never
  distinguishes which [failure], to the customer" — plausibly a
  deliberate choice (nothing actionable to tell the customer beyond
  "something's wrong"), but this batch did not have enough context to
  confirm that was an intentional product decision rather than an
  oversight. Flagged for a product decision, not silently
  reinterpreted either way.
- Every backend typed-exception message and `code` value in this module
  (already extensive — `ConsultancyConversionRetryableException`,
  `ConsultancyManualReviewRequiredException`, the booking-conflict
  `RuntimeException`s, etc.) was left untouched — all were already
  customer-safe and specific per Phase A's own finding.

**Verification:** TypeScript clean across all 10 touched files. ESLint:
each file's problem count individually confirmed identical before/after
(23 total pre-existing issues across all 10 files, zero new) via a
scripted per-file baseline comparison — not just an aggregate count.
Production Docker build succeeded. `git diff --check` clean. No backend
changes, so no backend test run was needed this batch. Live browser
validation again not performed (no browser automation tool available in
this session).

**Recommended Batch 5 scope**: Programme + Delay & EOT + the remaining
operational modules (RFIs, Meetings, QA, Snagging, Site Reports,
Closeout), per the original plan.

---

## Mechanical Consolidation Sweep (Batches 5–7)

**Scope and honesty note**: on explicit instruction to "fix all," this
sweep found and fixed every remaining instance of the two specific,
well-understood, low-risk patterns this initiative had already validated
dozens of times across Batches 1–4 — it did **not** perform a fresh
per-module partial-success/state-conflict/structured-code audit of the
kind Batches 1–4 each received. Billing, AI, Google, and Email
specifically were checked for these two patterns only (none were found —
either already clean or already consolidated by an earlier batch) and
were not otherwise investigated. Treat this section as closing the
"duplicate/discarded message" class of finding platform-wide, not as a
completed Batch 5/6/7.

**Patterns fixed, platform-wide:**

1. `IDENT?.response?.data?.message ?? 'literal'` /
   `IDENT?.response?.data?.message || 'literal'` → `getErrorMessage(IDENT,
   'literal')` — including ternary and template-literal fallback
   expressions (e.g. `` `Failed to ${x} variation` ``), which a first
   mechanical pass's regex correctly didn't touch and were fixed
   individually afterward.
2. `onError: () => toast.error('literal')` (no `err` parameter at all,
   meaning any real backend message was structurally unreachable) →
   `onError: (e: unknown) => toast.error(getErrorMessage(e, 'literal'))`.
3. Bare `catch { toast.error('literal'); }` (same issue, in try/catch
   form) → `catch (err) { toast.error(getErrorMessage(err, 'literal')); }`.

**21 files fixed**, ~38 individual occurrences, spanning:
`(dashboard)/{contracts,settings}`, `admin/{companies/[id],consultancy/
reservations,documents,notifications,prompts,templates,users}`,
`app/{onboarding,projects/[id]/{adjudication/[caseId],contracts,notices,
programme,rfis,site-reports,variations}}`,
`components/{documents/{GeneratePackageModal,
GenerateTradePackageFolderModal},subcontracts/SubcontractAiOnboardingModal,
notifications/NotificationBell}`. Every fix preserves its existing
fallback string exactly — this is infrastructure consolidation, not a
copy rewrite, consistent with every prior batch's own rule.

**Deliberately NOT fixed — two files, by design:**

- `app/admin/users/page.tsx` (2 occurrences) — existing code reads
  `data.message ?? data.errors.email[0] ?? 'fallback'` /
  `data.message ?? data.errors.password[0] ?? 'fallback'`. The shared
  `getErrorMessage()` only reads `.message`; swapping blindly would have
  **removed** the existing field-error fallback, a real regression, not a
  consolidation. Left untouched.
- `app/admin/suresign/page.tsx` (1 occurrence) — reads `data.message ??
  e?.message ?? 'Failed to send.'`, i.e. it also falls back to the raw JS
  `Error.message` when no backend response exists at all — different,
  deliberately broader behaviour than the shared helper's network-failure
  handling. Left untouched for the same reason.

**A real script bug was caught during this sweep's own verification, not
shipped**: an early mechanical pass inserted the new import line inside an
already-multi-line `import { ... } from 'lucide-react'` statement in one
file (`admin/companies/[id]/page.tsx`), breaking its syntax — caught
immediately by the mandatory `tsc --noEmit` pass this initiative treats as
non-optional, and fixed before any other verification step ran. Every
other file was independently checked afterward for the same insertion
pattern and none were found.

**Verification:** TypeScript clean across the full project (the one
script-introduced syntax break above was caught and fixed by this same
check, not by luck). ESLint: all 21 touched files checked via a scripted
per-file baseline comparison (current vs. `git show HEAD:<path>`) — 175
current vs. 178 baseline total across the 21 files; the only 3 differences
all reduced the error count (converting `err: any` to `err: unknown`
removed a pre-existing `@typescript-eslint/no-explicit-any` violation each
time — verified individually, not assumed). `git diff --check` clean.
Production Docker build succeeded (re-verified synchronously after a
background build run was lost to a session interruption with no
completion record — re-run rather than assumed passing). Full backend
regression re-run: 2406 tests (2 more than Batch 3's own count, matching
its 2 added tests), same 4 pre-existing, already-confirmed-unrelated
failures, zero regressions. Live browser validation again not performed —
no automation tool available in this session.

**Recommended next step**: a genuine Batch 5 (Programme + Delay & EOT +
RFIs/Meetings/QA/Snagging/Site Reports/Closeout) and Batch 6 (Billing +
AI + Google + Email) pass, each with the same depth of partial-success/
state-conflict investigation Batches 1–4 received — this sweep closed the
mechanical debt but did not perform that investigation for those modules.

---

## Batch 5 — Semantic Audit & Fixes (Programme + Delay & EOT + Operational Modules)

Full semantic re-audit of Programme, Delay & EOT, RFIs, Meetings, QA
Reports, Snagging, Site Reports/Site Diaries, and Closeout — explicitly
not relying on the earlier mechanical sweep having already covered these
modules. Found and fixed real logic bugs, not just wording.

### Inventory (by finding, most severe first)

| # | Workflow | Actual backend/frontend behaviour found | Severity | Outcome |
|---|---|---|---|---|
| 1 | Any module calling `NotificationService::sendToOrganization()` unguarded (Programme, Delay Event, EOT, L&E Claim, Meetings, QA Report, RFI, Site Diary — 8 call sites) | A secondary in-app-notification failure would propagate all the way up and turn an already-committed primary save into an apparent total failure (500) | **P1** — user cannot tell the action actually succeeded | **Fixed** at the shared-service level (one change protects all 8 call sites) |
| 2 | `LossAndExpenseClaimController::createFinalAccountItemIfPossible()` | Same class of bug as #1, but for a different secondary operation (auto-seeding a Final Account item) — found independently while reading this controller in full | **P1** | **Fixed** |
| 3 | `CloseoutController` GET `/projects/{id}/closeout` (frontend) | `.catch(() => null)` swallowed every failure; the page then rendered a fully-populated-looking checklist UI at "0% - 0/0 complete" with zero indication anything had failed — worse than a normal empty state, since this endpoint always auto-seeds 15 real items on success | **P1** — actively misleading, not just uninformative | **Fixed** |
| 4 | `CloseoutController`'s `updateItemMutation`/`markCompleteMutation` (frontend) | Zero `onError` handling anywhere in the file — a failed checkbox toggle or "mark complete" action looked like it simply did nothing, with no explanation | **P1** — user cannot tell whether the action succeeded | **Fixed** |
| 5 | QA Reports / Snagging delete mutations (frontend) | Same "zero onError" gap — a failed delete left the confirm dialog open with no explanation | **P1** | **Fixed** |
| 6 | Programme milestone delete mutation (frontend) | Same gap — a failed delete after the user already confirmed via `window.confirm()` gave no feedback at all | **P1** | **Fixed** |
| 7 | RFIs / Meetings / QA / Snagging / Site Reports / Programme / Delay Event / EOT / L&E Claim list queries (frontend, 10 files) | `isError` never destructured (or, for QA/Snagging/Closeout, actively discarded via `.catch()`) — a genuine load failure rendered identically to "no records exist yet" | **P2 → P1** for the `.catch()` cases specifically (actively wrong, not just silent) | **Fixed** — all 10 |
| 8 | QA Reports / Snagging create/edit form (frontend) | `mutation.isError && <p>Failed to save. Please try again.</p>` — hardcoded, discarded the real backend message (e.g. which field was invalid) | P2 | **Fixed** |
| 9 | Programme's `seedFromAnalysis` mutation error handling (frontend) | Manually re-implemented the same `err.response?.data?.message` extraction the shared helper already does — missed by the earlier mechanical sweep because of its two-line shape (`const msg = ...; toast.error(msg ?? ...)`, not the single-line `?? '...'` pattern the sweep's regex matched) | P2 | **Fixed** — found incidentally while reading this file for the real audit, not a fresh sweep |
| 10 | EOT `decide()` re-decision (no guard against amending an already-granted/refused EOT) | Investigated — **confirmed intentional** by the code's own existing comment ("decide() has no guard against re-deciding an EOT... e.g. amending days_granted later") | N/A | **Not changed** — this is documented product policy, not a bug |
| 11 | RFI editing a `closed` RFI (no guard) | Investigated — no blocking rule exists, and no comment either way | N/A | **Not changed** — no evidence this is a bug vs. a deliberate simplicity choice; flagged as a product-decision candidate, not assumed either way |
| 12 | Delay Event / EOT "generate Notice/Decision Notice" dedicated PDF endpoints | Confirmed NOT wrapped in try/catch — correct, since the document IS the requested record for these two dedicated endpoints (same reasoning as Batch 3's Payment Application `generatePdf()`/`generateCertificate()`) | N/A | **Not changed** — correct as-is |
| 13 | Delay Event / EOT state-conflict messages (422s: "must be marked as notified before generating a Notice", "must be decided before generating a Decision Notice") | Already specific, customer-safe, and already flow through the shared `getErrorMessage()` helper (fixed in earlier batches) with informative fallback text | N/A | **No change needed** — already correct |

### Programme findings

Confirmed clean overall — the one real issue was #1 (notification resilience) and #6/#9 (frontend). Milestone date-relationship validation ("End time must be after start time.", "A meeting cannot be longer than 24 hours." — actually Meetings, see below) and status-change logic were not altered; no calculation changed.

### Delay & EOT findings

Backend messaging was already the most mature of any module reviewed in Batch 5 (matches Consultancy/Appointments' own standing from Batch 4) — typed, specific 422s already in place for both dedicated document-generation endpoints, already flowing through the shared frontend helper. `EotRequestController::decide()`'s re-decision behaviour investigated and confirmed intentional (finding #10). No backend changes needed beyond the shared notification fix (#1).

### RFI findings

Fixed the fake-empty-state list query (#7). Investigated closed-RFI editing (#11) — left unchanged, flagged as a product-decision candidate rather than assumed to be a bug. `GenerateProjectNotificationsJob::dispatch()` calls confirmed already-safe (`ShouldQueue`, fully decoupled from the request).

### Meetings findings

No email is sent for meetings at all currently (in-app notification only) — the task brief's "meeting saved vs. email failed" distinction doesn't apply to this controller's actual code path. Date/time validation ("End time must be after start time.", "A meeting cannot be longer than 24 hours.") already specific and correct. Fixed the fake-empty-state list query (#7).

### QA findings

Fixed the fake-empty-state list query — this one was the actively-misleading `.catch()` variant (#7), plus the hardcoded form error (#8) and the silent delete mutation (#5).

### Snagging findings

Identical shape to QA — same three fixes (#5, #7 `.catch()` variant, #8).

### Site Reports findings

Fixed the fake-empty-state list query (#7, the "isError never destructured" variant — no `.catch()` swallow here, so a smaller gap than QA/Snagging/Closeout).

### Closeout findings

The most severe module in this batch — three real, independent bugs (#3, #4, plus #7 for the pattern). `recalculateCloseoutStatus()`'s own logic (derived from item completion) was read and left untouched — no business-rule change.

### Batch 5 partial-success findings

The two real "primary saved, secondary silently would-have-failed-the-whole-request" bugs are #1 (notifications, 8 call sites via one shared fix) and #2 (Final Account auto-seed). Both fixed the same way: wrap in try/catch, log, never block the already-successful primary save — matching the exact pattern Batch 3 established for `PaymentApplicationController::certify()`. Neither needed a new response field, since nothing in either case currently promises the secondary step's outcome to the customer.

### Batch 5 fake-empty-state findings

10 confirmed cases (finding #7), 2 of which (`CloseoutController`'s frontend query, QA's, Snagging's) used the actively-misleading `.catch(() => ...)` swallow; the other 7 simply never destructured `isError` at all. All 10 fixed with the same pattern: destructure `isError`/`error`/`refetch`, add a dedicated error-state render branch (message via `getErrorMessage()`, a "Try again" action via `refetch()`) before the empty-state branch. The `ProjectDocumentsExplorer` case flagged in the mechanical sweep (Documents module) was deliberately **not** touched — stays a Documents-batch follow-up, per this batch's explicit scope boundary.

### Batch 5 changes implemented

**Backend:**
- `App\Services\NotificationService::sendToOrganization()` — wrapped in try/catch, logs and never rethrows. Protects 8 existing call sites without touching any of them individually.
- `App\Http\Controllers\Api\LossAndExpenseClaimController::createFinalAccountItemIfPossible()` — wrapped in try/catch, logs and never rethrows.

**Frontend** (10 files): `rfis/page.tsx`, `meetings/page.tsx`, `qa/page.tsx`, `snagging/page.tsx`, `site-reports/page.tsx`, `closeout/page.tsx`, `programme/page.tsx`, `delay-eot/DelayEventsTab.tsx`, `delay-eot/EotRequestsTab.tsx`, `delay-eot/LossAndExpenseTab.tsx` — fake-empty-state fixes, missing-`onError` fixes, hardcoded-message fixes, as detailed in the inventory above.

### Batch 5 items deliberately unchanged

- EOT re-decision (#10) — confirmed intentional via existing code comment.
- RFI closed-state editing (#11) — no evidence either way; flagged as a product-decision candidate for whoever owns RFI workflow policy, not assumed to be a bug.
- Delay Event/EOT dedicated PDF endpoints (#12) — confirmed correct as-is.
- `CloseoutController::update()`'s manual-status-override-vs-auto-calculated-status ambiguity (a project's closeout header status can be manually set to `completed` regardless of item completion) — investigated, judged to be architecture/product policy rather than a truthfulness bug (no message anywhere claims otherwise), explicitly not touched.

### Verification

- **Targeted backend tests**: `NotificationServiceResilienceTest` (2 new — one forces a **real** thrown exception via the documented `$recipientFilter` callable parameter, not a mock, proving the try/catch catches a genuine `\Throwable`); `Batch3LossAndExpenseClaimsTest` (1 new — happy-path regression guard proving the try/catch refactor didn't also swallow the successful auto-seed case). All pass.
- **Full backend regression**: 2413 tests (3 more than the pre-Batch-5 baseline of 2410, matching the 3 new tests exactly), same 4 pre-existing, already-confirmed-unrelated failures (a sandbox storage-permission issue + 2 environment-dependent Consultancy tests + 1 unrelated Excel test) — zero regressions.
- **TypeScript**: clean across the full project.
- **ESLint**: all 10 touched frontend files individually baseline-compared (`git show HEAD:<path>` vs. current) — 45 total pre-existing issues across all 10 files, byte-identical before and after, zero new issues.
- **Production Docker build**: succeeded — confirmed **synchronously** after an earlier background-dispatched build run was lost to a session interruption with no completion record (the task notification explicitly said so). That lost run was never treated as a pass; it was re-run for real before being reported here.
- **`git diff --check`**: clean.
- **Live browser validation**: not performed — no browser automation tool available in this session, stated plainly rather than assumed or fabricated.

### Stop-condition assessment (why Batch 6 was not started in this session)

None of Batch 5's findings required a migration, a legal/commercial calculation change, or hit unclear provider semantics — all fixes stayed within the "make the frontend truthful" boundary. Batch 6 (Billing + AI + Google + Email) was **deliberately not started** in this same session, per the task's own explicit instruction: "complete Batch 5, return its report, and leave Batch 6 untouched for explicit approval" — Batch 6's four areas each carry materially different risk profiles (Stripe webhook authority, Google OAuth token lifecycle, AI credit accounting, email queue/provider semantics) that deserve their own dedicated audit pass rather than being folded into an already-substantial Batch 5 session.

---

## Batch 6 — Semantic Audit & Fixes (Billing + AI + Google + Email/Communications)

Full semantic audit of the four provider-heavy areas, applying the same
methodology as Batch 5 (trace primary vs. secondary operations, verify
provider-state accuracy, check for fake-empty/false-success states) —
explicitly not a mechanical string sweep. Confirmed real test
infrastructure exists for all four providers before writing any test:
`FakeBillingProvider` (dependency-injected), `Http::fake('api.anthropic.com/*')`
(the existing `AiTelemetryTest` convention), `FakeGoogleApiClient`
(dependency-injected), `Http::fake('api.brevo.com/*')` (the existing
`ConsultancyCommunicationsBatch1Test` convention). No real Stripe/AI/
Google/email calls were made.

### Billing findings

**Zero fixes needed — confirmed, not assumed.** Traced Checkout success
polling, upgrade/downgrade/cancel-pending plan changes, Billing Portal
creation, subscription cancel/resume, and pending-checkout cancellation
end-to-end. Every one of these already matches this batch's own worked
examples almost verbatim:
- `checkout/success/page.tsx` never claims "active" until `GET
  /billing/overview`'s authoritative, webhook-confirmed state says so —
  has polling, a give-up threshold, and manual refresh.
- `PlanChangeController`/`BillingPortalController` already have mature,
  specific, provider-safe structured `code` values for every state
  conflict (`plan_change_conflict`, `subscription_not_eligible`,
  `provider_error`, `portal_unavailable`, etc.), never echo a raw
  `ApiErrorException`/internal-exception message, and every frontend call
  site already surfaces the real backend message via `getErrorMessage()`.
- Success wording is already accurate about what's actually known
  ("Upgrade requested — confirming with Stripe now." vs. "Downgrade
  scheduled for your next renewal date." — correctly distinct, matching
  the backend's own immediate-vs-scheduled-send behaviour).
- The main Billing overview/subscription pages already have real,
  distinct error states (not fake-empty) for a failed `GET
  /billing/overview` — confirmed, not a gap.

No structured codes were added — none were needed. This confirms
CLAUDE.md's own documented confidence in this module.

### AI findings

- **Fixed**: `AiAnalysisModal` (main Contract Analysis) had no recovery
  action at all for a completed-but-failed analysis — the user saw the
  reason and nothing else, with no visible way to retry (the actual
  recovery path — reopening the modal — worked, but nothing said so). Now
  has a "Try Again" button reusing the exact same `forceNew` action
  already used elsewhere on the same page. Brought this flow to parity
  with the Trade Package onboarding modal, which already had its own
  working "Retry analysis" action.
- **Fixed (real bug, not just wording)**: `SubcontractAiOnboardingModal`'s
  upload flow chains two API calls (upload the file, then start analysis)
  in one mutation. If the second call failed, the toast said "Failed to
  start subcontract analysis" with no indication the upload itself had
  already succeeded and committed — the user could reasonably think
  nothing happened at all, or re-upload unnecessarily. Now distinguishes
  which half failed and says so explicitly, matching this batch's own
  worked example almost verbatim ("We uploaded the document, but couldn't
  start the analysis. The document is still available...").
- **Fixed (found while re-checking the "upload vs analysis" question,
  more severe than initially framed)**: `AiAnalysisModal`'s mount effect
  fetched prior completed analyses before deciding whether to
  auto-start a new one. On a **fetch failure** (unrelated to whether a
  prior analysis exists), it silently called `startMutation.mutate(undefined)`
  — starting a brand-new AI analysis as a side effect of a failed read,
  with no user intent behind it. In the worst case (a real prior analysis
  existed but just couldn't be retrieved) this could trigger an
  unnecessary re-run instead of showing the "pick which one" chooser —
  contained in practice by the existing `document_hash` dedup cache
  (confirmed via `ContractAnalysisDedupTest`), but still a real,
  uncosted-for automatic action taken without the user asking for it.
  Now shows an explicit "we couldn't check for previous analyses" state
  with an honest choice: retry the check, or start new anyway
  (user-decided, not silently assumed).
- **Confirmed already correct**: `AnalyseContractWithAiJob`'s failure
  handling already distinguishes curated-safe messages (`RuntimeException`
  — missing file, unsupported type, AI usage allowance reached) from
  unexpected failures (generic "The AI analysis could not be completed.")
  — zero raw provider/exception detail ever reaches `error_message`,
  which is the one field the customer-facing presenter exposes.
  `failure_category` is confirmed deliberately never exposed to
  customers. AI Credit enforcement's own customer-safe wording
  ("This organisation's monthly AI usage allowance has been used...")
  was already exactly right.
- **Confirmed not applicable**: the "failed vs. incomplete" distinction
  (§10 of the task brief) has no `confidence`/`missing_information`
  fields anywhere in this codebase — SureSign's actual architecture
  handles "ran, but needs human judgement" via the existing
  completed→confirmed two-step status, not a machine-computed confidence
  score. Correctly did not invent one.

### Google findings

- **Fixed**: `admin/google-integration/page.tsx`'s connection-status query
  never checked `isError` — a genuine failure to load diagnostics (network
  blip, 500) fell through to the identical render path as "no Google
  account connected," telling an admin their integration was disconnected
  when the real status was simply unknown. This is the exact example
  named in this batch's own brief. Now shows a distinct "we couldn't
  check the status — this doesn't necessarily mean it's disconnected"
  state with a retry action.
- **Fixed (lower severity, preview-only)**: the admin Appointment
  reschedule modal's live availability-preview check defaulted to
  `available: true` on a failed check — indistinguishable from a genuine
  "yes, available" result. Confirmed **not** a data-integrity risk (the
  actual reschedule submission is still authoritatively re-validated
  server-side regardless of what the preview showed), but still a
  needlessly false-positive preview. Now shows a small, non-blocking "we
  couldn't verify availability" note instead of silently claiming a
  result it never obtained.
- **Confirmed already correct, not re-touched**: Consultancy's own
  `ConsultationMeetingPresenter` `pending`/`temporarily_unavailable`/
  `unavailable` distinction was already fixed in Batch 4 — re-confirmed
  still correct, not re-litigated. Generic (non-Consultancy) Appointments
  have no customer-facing UI surface at all (booking is admin/operator-
  only for that path) — confirmed there is no equivalent gap to find
  there; the customer-facing Meet/Calendar experience is exclusively
  through Consultancy, already covered.

### Email/Communications findings

- **Confirmed already correct**: `EmailNotificationService::send()`/
  `sendDirect()`/`sendDirectWithMessageId()` are all already fully
  resilient (try/catch, never throw, return failure state rather than
  propagating) — matches the exact discipline this batch was checking
  for. Every caller of the org-wide `send()` receives `void`, meaning no
  controller could even be lying about email delivery status if it tried
  — the architecture already structurally prevents that class of false
  claim everywhere.
- **Deferred product decision, not silently changed**: `AuthController`'s
  "a password reset link has been sent" / "Verification email sent."
  wording is technically imprecise — both dispatch a queued job
  (`SendPasswordResetEmailJob`/`SendEmailVerificationJob`, confirmed
  `->afterCommit()`), meaning the email is queued, not yet actually sent,
  at the moment this response returns. This is **explicitly named as a
  stop-condition in this batch's own brief** ("email 'sent' vs 'queued'
  product terminology is intentionally abstracted") — not changed, per
  that instruction, and noted here rather than silently left unexamined.
- No other "record saved, email failed" conflation was found beyond what
  Batches 1/3/4 already fixed (Payment Notices/Pay Less Notices'
  document-generation honesty, Consultancy's meeting-link states).

### Batch 6 partial-success findings

Two real ones, both AI: the mount-effect auto-start-on-fetch-failure (a
provider action taken with no user intent — the most serious finding
this batch) and the combined upload+analysis-start mutation not
distinguishing which half failed. Both fixed. No backend response-shape
changes were needed for either — both were purely frontend
state-handling bugs; the backend already returns everything needed to
tell the truth.

### Batch 6 fake-empty/false-success findings

Two confirmed: Google integration's connection status (`isError` never
checked) and the reschedule availability preview (`available: true`
defaulted on failure). Both fixed with this initiative's now-standard
pattern (distinguish the failure explicitly, never render it identically
to a real result).

### Structured error codes

**None added.** Billing's existing codes were reused conceptually (as a
confirmation of the pattern, not by adding new ones) — no AI/Google/Email
finding this batch needed new deterministic frontend branching that
`code` values would serve; every fix was about state-representation
honesty, not routing.

### Backend response changes

**None.** Every fix this batch was achievable entirely in frontend state
handling — a first for this initiative (every prior batch touched at
least one backend file). Confirmed by re-checking: no new field, no
structural change to any API response was required.

### Security/privacy review

No provider secret, customer ID, price ID, OAuth token, or webhook
internal was exposed anywhere audited this batch — all already correctly
kept server-side per the existing conventions confirmed in Billing/AI/
Google controllers.

### Tenant-isolation review

No authorization logic touched.

### Retry/idempotency review

The one new "Try Again"/"Start new anyway" action added (AI Contract
Analysis modal) reuses the exact same `forceNew` mutation path already
used elsewhere on the same page for user-initiated re-analysis — not a
new retry mechanism. No automatic retries were added anywhere; the one
confirmed *removal* of an automatic action (the fetch-failure auto-start)
was the single biggest retry-safety fix this batch made.

### Files modified

`frontend/src/app/app/projects/[id]/contracts/page.tsx`,
`frontend/src/components/subcontracts/SubcontractAiOnboardingModal.tsx`,
`frontend/src/app/admin/google-integration/page.tsx`,
`frontend/src/app/admin/appointments/[id]/page.tsx`. No backend files, no
new files.

### Tests

None added — no backend behaviour changed this batch (a first for this
initiative). All four fixes are frontend state-handling corrections;
TypeScript's own type-checking plus the manual trace-through documented
above were the applicable verification for changes of this shape.

### Verification

TypeScript clean. ESLint: all 4 touched files individually
baseline-compared — 61 current vs. 62 baseline (one file improved by 1,
zero regressions). Full backend regression: 2413 tests, identical to the
pre-Batch-6 baseline (no backend changes were made, so no count change
expected), same 4 pre-existing already-confirmed-unrelated failures.
Production Docker build: succeeded, run synchronously and completed
within the foreground window (no backgrounding, no lost result this
time). `git diff --check` clean. Live browser validation not performed —
no automation tool available, stated plainly.

### Deferred product decisions

Email "sent" vs. "queued" wording (`AuthController`) — flagged per this
batch's own explicit stop-condition, not decided unilaterally.

### Final assessment

**This was the last batch the original Phase A audit plan named.** Every
module list from that plan (Authentication; Projects/Trade Packages;
Commercial/Documents; Consultancy/Appointments; Programme/Delay & EOT/
operational modules; Billing/AI/Google/Email) has now received a full
semantic audit and, where real findings existed, a targeted fix — plus
the platform-wide mechanical sweep that closed the remaining
duplicate-message-discarding debt. Whether to formally close the
initiative is a judgement call for whoever owns it, not something to
declare unilaterally here — see Batch 6's own completion report for the
explicit recommendation.
