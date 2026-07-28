# SureSign AI Credit Policy & Consumption Model v1

**REVISION NOTICE (G4C.2A–B, superseding Sections 5–13/18–20 below): after
two further rounds of architectural and commercial review, the original
recommendation in Section 6 ("1 completed analysis = 1 AI Credit") is no
longer the direction. The final, frozen-for-implementation policy is in
Part Two, starting at Section 31. Sections 1–30 are retained unmodified as
the historical reasoning trail that led there — the workflow inventory,
architecture review, and accounting-invariant work in those sections
remains valid and is relied upon by Part Two; only the credit-model
recommendation itself changed. Do not implement against Sections 5–13 —
implement against Part Two.**

**Status: specification only. No code, migration, schema, Stripe object, FeatureGate
change, or UI was implemented as part of this document. Every numeric value in
this document is either (a) a real, currently-configured value already in the
codebase (marked Confirmed, with its own separate "not yet founder-approved"
caveat carried over from the Commercial Strategy), or (b) a clearly labelled
Provisional placeholder requiring business approval and/or additional
telemetry before use. Nothing here should be read as an approved commercial
decision. See Section 27 for the consolidated list of what actually needs
Graham's sign-off.**

This document is Phase G4C.2. It follows, and must be read alongside:
[SureSign Commercial Strategy v1](suresign-commercial-strategy-v1.md),
[SureSign Entitlement Specification v1](suresign-entitlement-specification-v1.md),
and `internal-docs/super-admin/ai-credits-architecture.md` (G4C.0 through
G4C.1A.1 — the telemetry, cache, failure-classification, and effective-dated
pricing foundation this policy is designed to sit on top of).

---

## 1. Executive Summary

SureSign already has two real, provider-backed AI workflows (Contract
Analysis, Trade Package Analysis), a working telemetry foundation that
records real tokens/cost/cache-hit/failure-category per analysis, and
effective-dated provider pricing that keeps historical costs stable across
pricing changes. It also already has customer-facing "AI credits" language
in the product (cancel/reparse copy) and an existing, currently-enforced-in-name-only
usage entitlement, `ai_analyses_per_month`, that expresses AI usage as a flat
monthly analysis count.

This document specifies what an AI Credit should mean, how usage should
convert into credits, how plan allowances should work, how caching/retries/
failures should be treated, what should happen at exhaustion, how purchased
top-ups could work, and what a future ledger must guarantee — without
building any of it yet.

**The central finding this document surfaces** (Section 6): SureSign's own
approved Commercial Strategy already explicitly warns against "highly
granular consumption billing" and treats "metered AI billing" as
demand-driven, not a current direction. The recommended model therefore
keeps the customer-facing unit as close as possible to the *already-approved*
flat-count philosophy — **1 AI Credit = 1 analysis, for both real workflows,
regardless of document size** — rather than introducing token-based or
size-banded pricing now. Internal provider-cost telemetry (tokens, cost,
duration) remains a separate, invisible-to-the-customer calibration signal,
not the deduction mechanism. Size-banding, purchased packs, and any overage
model are documented as real options with full trade-off analysis, but are
explicitly flagged as **beyond** what's currently approved, not just pending
a number — introducing any of them requires the founder revisiting, not
merely rubber-stamping, that part of the Commercial Strategy.

Current telemetry (five local rows, one real large-contract benchmark, zero
Trade Package samples) is explicitly **not** treated as representative
anywhere in this document. Telemetry collection continues in parallel,
per Section 21, and is not on the critical path for this specification.

---

## 2. Existing Architecture — Confirmed Constraints

*(Full detail already delivered in the factual checkpoint; summarised here as
the standing constraints every recommendation below must respect.)*

- **Organisation is the billable entity** (Commercial Strategy §4/§7;
  structurally confirmed via `SubscriptionLifecycleService::
  hasConflictingSubscription(Organization)`). Any AI Credit balance belongs
  to an Organisation, never a User.
- **SureSign is the entitlement source of truth; Stripe is payment processor
  only** (Entitlement Spec §2 principles 1–2). A future credit balance must
  be resolved from SureSign's own stored state, never from a live Stripe
  call, and Stripe must never gate whether an AI action is allowed.
- **No seat/user model** — `max_users` is dormant, unenforced, unsold,
  invisible (Feature registry). AI Credits must not become a de facto seat
  mechanism (e.g. "each user gets their own credit pool") — the allowance is
  organisation-wide.
- **Grandfathering is real and load-bearing** — entitlements are snapshotted
  per subscription at activation/plan-change via `EntitlementSnapshotService`,
  never re-read live from a plan's current defaults. Any AI Credit allowance
  attached to a plan must follow the same snapshot discipline — a future
  plan-catalogue edit must never silently change what an existing
  subscription's AI Credit grant already was.
- **`Feature::AI_ANALYSES_PER_MONTH` already exists** — a real, sold,
  customer-visible, soft-limit usage entitlement (integer, unit `analyses`,
  reset by UTC calendar month per Entitlement Spec §12, decided 2026-07-23).
  Current configured defaults (code, not founder-approved pricing): Essential
  10, Professional 50, Enterprise 200 (placeholder), trial 3. **Any AI Credit
  design must explicitly state its relationship to this existing key** —
  see Section 6.2.
- **`FeatureGate` exists but is called by no module yet** (confirmed via its
  own docblock and `UsageMetricsService`'s docblock, which identifies itself
  as the first real caller). Nothing in this codebase enforces
  `ai_analyses_per_month` today — it is measured and displayed
  (`UsageMetricsService`), never blocked. A future AI Credit *availability*
  check sits logically downstream of a `FeatureGate` enforcement path that
  does not exist yet either — this affects sequencing (Section 30), not the
  policy itself.
- **G4C.1/G4C.1A telemetry is real and verified**: `workflow`, `provider`,
  `model`, `document_char_count`/`document_file_type`, `provider_called`,
  `tokens_input`/`tokens_output`, `estimated_cost`, `duration_ms`,
  `queue_attempt`/`is_final_attempt`, `failure_category` (including the new
  `output_truncated`), `stop_reason`. Verified live: a genuine cache hit
  produces `provider_called=false`/zero tokens/zero cost; the real 110-page
  benchmark produced 58,897 input / 30,287 output tokens at $0.420664.
- **Effective-dated pricing is real** (`AiPricingSchedule`,
  `config/ai_pricing.php`) — cost is resolved against the provider-call
  timestamp, never the current date, so historical costs never silently
  drift when Anthropic's pricing changes.
- **Existing customer-facing AI Credit language is real precedent**, not
  proposed terminology: *"No AI credits were used,"* *"Running a new
  analysis may consume AI credits."* Customers are already primed for this
  mental model.
- **One confirmed pre-existing gap** (found during this review, not
  previously documented): `UsageMetricsService::aiAnalysesThisMonth()` only
  counts `ContractAiAnalysis` rows — `TradePackageAiAnalysis` usage is
  invisible to the existing entitlement display today. Any future AI Credit
  consumption counter must not repeat this gap — it must count both
  workflows from day one (Section 7).

---

## 3. Current Telemetry Limitations (Honest Baseline)

As of this document, the local environment has:

- **Five** `ContractAiAnalysis` rows total, all created by manual
  investigation of one real document during G4C.1B/G4C.1A, not organic
  customer usage.
- **One** real, successful, full benchmark analysis (the 110-page contract):
  58,897 input / 30,287 output tokens, $0.420664, ~4 minutes.
- **One** real, verified cache-hit reuse of that same benchmark: zero tokens,
  zero cost, ~1 second.
- **Zero** real Trade Package Analysis documents or analyses anywhere in this
  environment.
- **Zero** small or medium real contracts analysed.

**No average, frequency, or distribution in this document should be read as
representative of real customer usage.** Every numeric recommendation below
is either a currently-configured code value (labelled Confirmed, itself still
requiring founder pricing approval per the Commercial Strategy) or an
explicitly labelled Provisional placeholder pending real telemetry.

---

## 4. Provider-Backed AI Workflow Inventory

Only two workflows make real provider calls. No other candidate module
(document extraction outside these two flows, deterministic sync, narrative
generation, payment/commercial intelligence beyond what these two already
produce) does — confirmed by the same repo-wide provider-call search
G4C.0 already performed, re-verified during this review.

### 4.1 Contract Analysis

| Field | Value |
|---|---|
| Workflow identifier | `contract_analysis` (`App\Support\AI\AiWorkflow::CONTRACT_ANALYSIS`) |
| User-facing name | "AI Contract Review" / "Analyse Contract" |
| Provider interaction | `ContractAnalysisService::analyse()` → `ClaudeAiProvider::complete()` |
| Expected input | One uploaded PDF/DOCX/TXT contract document |
| Current telemetry source | `contract_ai_analyses` — full G4C.1/G4C.1A field set |
| Cache behaviour | Exact `document_hash`+`model` match on a prior `completed`/`confirmed` row → zero provider call, zero cost (verified live) |
| Retry behaviour | `$tries=1` + idempotency guard (no queue-level retry); user `force_new` creates a new row, never re-charges the old one |
| Durable business value | Yes — feeds `ContractIntelligenceSyncService` (payment dates, milestones, parties) into operational records after human confirmation |
| Should it consume credits? | Yes — the only real, costed, provider-backed action a customer directly triggers in this workflow |
| Proposed charging unit | See Section 6 — recommended: 1 credit per settled analysis |
| Unresolved questions | Whether a `reparse` (no provider call) should ever appear in customer-visible usage history as a zero-credit event for transparency (recommend yes — Section 9) |

### 4.2 Trade Package (Subcontract) Analysis

| Field | Value |
|---|---|
| Workflow identifier | `trade_package_analysis` |
| User-facing name | "Subcontract AI Analysis" |
| Provider interaction | `TradePackageAnalysisService::analyse()` — composes `ContractAnalysisService`'s shared provider/cost/telemetry logic, not a duplicate implementation |
| Expected input | One uploaded subcontract document |
| Current telemetry source | `trade_package_ai_analyses` — identical field set to Contract Analysis |
| Cache behaviour | Same mechanism, same verified guarantees |
| Retry behaviour | Same |
| Durable business value | Yes — feeds `TradePackageIntelligenceSyncService` |
| Should it consume credits? | Yes, same reasoning as 4.1 |
| Proposed charging unit | Same as Contract Analysis (Section 6) — **no evidence exists to justify a different rate for this workflow**; both use the same provider, same prompt-shape discipline, same cost formula |
| Unresolved questions | **No real document has ever been analysed by this workflow in this environment.** Any credit rate applied to it today is entirely unvalidated — flagged explicitly in Section 21's telemetry plan as the single highest-priority sample still needed |

### 4.3 Confirmed NOT separately chargeable

| Candidate | Why not |
|---|---|
| `ContractIntelligenceSyncService` / `TradePackageIntelligenceSyncService` | Deterministic — reads already-confirmed JSON, writes to programme/calendar/contract fields. Zero provider calls (re-confirmed this review). Charging this separately would double-charge for one already-costed analysis. |
| Prompt Library | Confirmed zero provider calls anywhere in its code path (manual copy/paste workflow). Zero-provider-call workflows must never accrue credit cost per the Commercial Strategy's own framing of AI Credits as tied to real, variable provider cost. |
| AI Chat (`/ai/conversations`, `/ai/summarize`, `/ai/draft-document`) | Non-functional — routes reference controller methods that don't exist. Cannot consume credits for an action that cannot run. |
| Reparse (`reparseAnalysis()`) | Makes **no** provider call (confirmed: neither `AiController::reparseAnalysis()` nor its Trade Package equivalent reference `makeProvider()`). Must remain zero-credit. |
| Cancel-before-processing | `status='pending'` cancellation happens before any provider call. Must remain zero-credit (existing copy already promises this: *"Analysis cancelled before it started. No AI credits were used."*). |

---

## 5. Options Considered — What an AI Credit Represents

| Option | Summary | Verdict |
|---|---|---|
| **A. Token-based credits** | Credits track provider tokens/cost directly | **Rejected as the customer-facing unit.** Exposes raw provider economics (tokens, effectively model choice) to a customer who has no intuition for what a token is; unpredictable before the user starts an action (a 5-page vs. 110-page contract could differ 5×+ in cost, discovered only after the fact); directly contradicts Commercial Strategy §7's "one predictable price... not a variable bill they have to audit." Retained as **internal telemetry only** (already built — G4C.1). |
| **B. Fixed credits per workflow** | Each workflow = a flat, predictable credit cost | **Recommended** (Section 6). Matches the already-approved bundled-pricing philosophy exactly; trivially reuses the existing `ai_analyses_per_month` count-based mechanism; zero new customer-facing complexity. Real risk: cost variance between a 5-page and a 300-page contract (see Section 5's margin discussion) — accepted as a deliberate trade-off, consistent with Commercial Strategy §7's explicit acceptance that bundled pricing means "some customers under-utilise, others approach limits — this is normal and acceptable for a B2B bundled model." |
| **C. Size-banded workflow credits** | Bands (Small/Standard/Large) determined by page count/char count/tokens | **Not recommended as the default; documented as a future option only.** More accurate to real cost, but: (a) genuinely requires representative telemetry to set band boundaries sensibly — G4C.0 already reached this exact conclusion with zero real data, and this review still only has one real large-document sample; (b) introduces a customer-facing complexity ("was my contract Small or Large?") the Commercial Strategy explicitly warns against ("do not design prematurely" — granular consumption billing); (c) creates a real boundary-dispute risk for scanned/low-text-density documents where char count doesn't reflect true complexity. |
| **D. Provider-cost-derived internal units, displayed as stable SureSign units** | Same shape as B but the fixed number is periodically *calibrated* from real cost telemetry rather than picked once | This is not a different customer-facing model from B — it's **the correct internal calibration method for B**, and is folded into the recommendation (Section 6.4) rather than treated as a separate option. |
| **E. Hybrid** | E.g. fixed base + size band, or fixed-user-facing/internal-cost-monitored | **Effectively what's recommended**: fixed, predictable, user-facing credit cost (Option B) with internal provider-cost telemetry (Option A, already built) used only for margin calibration — never exposed, never the deduction mechanism. This *is* the hybrid; it isn't named as a separate fourth option to avoid presenting the same recommendation twice under different labels. |

**Do not equate 1 AI Credit with 1 provider token or 1 cent.** No documented
reason exists strong enough to justify that — Anthropic's own token
definition can change (already confirmed: Claude 4.7+ models use a newer
tokenizer producing ~30% more tokens for the same text, per the pricing
docs fetched during G4C.1A), and a 1:1 mapping would silently move the
customer-facing unit every time a provider/model changes, which directly
contradicts the "stable when provider prices change" requirement.

---

## 6. Recommended Credit Model

**SUPERSEDED by Part Two, Section 32 (G4C.2A–B).** Retained below only as
the reasoning trail — the "1 credit = 1 analysis, no exceptions" model
was found, on further review, to leave real margin exposure uncriticised.
Do not implement against this section.

### 6.1 The recommendation (superseded)

**1 AI Credit = 1 settled analysis, for both Contract Analysis and Trade
Package Analysis, regardless of document size.** No size bands. No
per-token pricing. A customer sees "this will use 1 AI Credit" before
starting, every time, for every document, for both workflows.

- **Evidence it's based on**: Commercial Strategy §6 ("Why AI is a usage
  allowance, not simply enabled/disabled") and §7 (bundled pricing
  philosophy, explicit rejection of granular/metered billing); the existing,
  already-shipped `ai_analyses_per_month` entitlement, which is *already*
  a flat count; existing customer-facing "AI credits" copy already primes
  users for a simple credit-per-action mental model, not a token count.
- **Alternatives considered**: Options A/C/D/E above — rejected or folded in,
  per Section 5's table.
- **Why this fits SureSign specifically, not generic SaaS practice**:
  SureSign is sold as a compliance/contract-administration system, not an
  AI tool (Commercial Strategy §2 — "SureSign is deliberately not... an AI
  wrapper"). A customer's buying decision and day-to-day trust in the
  product has nothing to do with token economics; pricing AI as if it were
  metered would misrepresent what's actually being sold, exactly as §7
  warns.
- **Engineering or commercial decision?** The *shape* (fixed vs. banded) is
  a commercial decision, already effectively pre-answered by the approved
  Commercial Strategy's own philosophy — this document is applying, not
  overriding, that decision. The *specific number of credits per workflow*
  (Section 6.3) is provisional and requires founder approval.
- **What remains configurable**: the credit cost per workflow (a single
  config value per workflow identifier — see Section 19), independently of
  code, and independently of any ledger redesign.
- **What requires Graham's approval**: the actual number (1 credit is a
  starting recommendation, not a proven number — see 6.3), and whether to
  ever introduce size-banding later (a reopening of already-approved
  strategy, not a configuration tweak).
- **What telemetry is still missing**: real volume across many
  organisations and both workflows, specifically to check whether treating
  all Contract Analyses as equal-cost is actually sustainable at scale (a
  margin question, not a customer-experience question) — see Section 21.

### 6.2 Relationship to the existing `ai_analyses_per_month` entitlement

This is the most important reconciliation this document makes. Two
non-exclusive paths exist:

**Recommended: `ai_analyses_per_month`'s existing *shape* becomes the AI
Credit allowance, renamed in customer-facing language but not
re-architected.** Concretely: the entitlement key, snapshot mechanism,
UTC-calendar-month reset, and soft-limit enforcement philosophy all stay
exactly as already specified (Entitlement Spec §4/§12) and require zero
change. What changes is presentation only: "10 AI Analyses per month"
becomes "10 AI Credits per month," and — because 1 credit = 1 analysis under
Section 6.1 — the numbers are identical on day one. This is deliberately
the **smallest possible reconciliation**: it doesn't touch `FeatureGate`,
doesn't touch the entitlement snapshot schema, and doesn't require the
founder to re-approve a new commercial dimension, since `ai_analyses_per_month`
is already approved in principle (Commercial Strategy §6/§21).

**Why not build a second, parallel "AI Credits" entitlement key
alongside it?** That would create exactly the two-sources-of-truth problem
Entitlement Spec §2 principle 6 exists to prevent ("every entitlement check
flows through one service boundary... never duplicated ad hoc"). If credits
and analysis-count ever diverge (e.g. size-banding is approved later), that
is the correct future moment to introduce a genuinely separate numeric unit
— not now, speculatively.

**Confirmed vs. Provisional**: that `ai_analyses_per_month` exists and is
the right integration point is Confirmed (it already exists, sold,
customer-visible). That "1 credit = 1 analysis" should be the permanent
conversion, and that the entitlement should be *renamed* in UI language, are
both Provisional — Graham approval needed before any UI copy change.

### 6.3 Provisional workflow credit rate

| Workflow | Proposed rate | Basis | Confidence |
|---|---|---|---|
| Contract Analysis | 1 credit | Matches existing analysis-count entitlement 1:1 | **Provisional** — no margin analysis performed against real Anthropic cost at volume |
| Trade Package Analysis | 1 credit | Same provider, same cost shape as Contract Analysis, no evidence to justify divergence | **Provisional, weaker confidence** — zero real documents ever analysed by this workflow (Section 4.2) |

**TBD pending representative telemetry**: whether 1 credit remains
sustainable once real volume (not the single 110-page benchmark) is
observed across many organisations — see Section 21/26.

### 6.4 Internal calibration method (proposed, not run)

```
Observed provider cost (real, from G4C.1/G4C.1A telemetry, per workflow)
+ infrastructure overhead (queue/storage/compute — not currently measured)
+ failed-call allowance (a truncated/failed-but-provider-charged call still
  costs real money — see Section 8's failure policy)
+ support/risk margin
= target internal cost envelope per workflow

Target envelope ÷ approved customer-facing credit price (a founder pricing
decision, not proposed here)
= whether 1 credit/analysis is margin-safe, or whether the workflow needs
  its own distinct rate
```

This calibration should run periodically (e.g. quarterly, or triggered by
a material Anthropic pricing change) against real accumulated telemetry —
never once, never from the current n=5 sample. It never changes historical
settled credit amounts (Section 20's policy-versioning principle) — only
the *rate applied to future requests*.

---

## 7. Credit Consumption Catalogue

| Workflow | Credit Model | Proposed Unit | Cache Hit | User Retry | Provider Failure | Notes |
|---|---|---|---|---|---|---|
| Contract Analysis | Fixed | 1 credit / settled analysis | 0 credits | New analysis = new charge; not a re-charge of the failed one | 0 credits if no tokens were ever persisted; **TBD pending policy** for a post-call failure (Section 8) | Existing `document_hash` cache scope is not organisation-isolated — see Section 9's carried-forward finding |
| Trade Package Analysis | Fixed | 1 credit / settled analysis | 0 credits | Same | Same | Zero real samples exist — treat any number here as provisional until real usage exists |

For each workflow:

- **When consumption begins**: at settlement (Section 8), not at request
  submission — see the charging-lifecycle recommendation below.
- **Preflight estimate possible?** Yes, trivially — since the unit is fixed
  (1 credit), the customer can be told the exact cost before starting,
  something a token/size-banded model could not promise without first
  reading the document.
- **Must credits be available before starting?** Recommended yes (Section
  14) — a hard eligibility check before the provider call, to avoid ever
  reaching a state where a provider call happens but the organisation had
  no credits to pay for it.
- **Reservation required?** Recommended yes, conceptually (Section 9) —
  even though the amount is fixed and known upfront (unlike a token-based
  model, there's no "maximum possible" ambiguity to reserve against; the
  reservation is simply "the known 1-credit amount," making this workflow's
  reservation logic simpler than a banded model would need).
- **Does actual document size change the result?** No, by design (Section
  6.1) — this is the entire point of the fixed-unit recommendation.
- **Duplicate submissions**: already handled at the analysis layer
  (`$tries=1` + idempotency guard, one active analysis per contract/package
  at a time) — a future reservation should key off the same analysis id,
  never accept a second reservation for an already-`pending`/`processing`
  row.
- **Are regenerations separate actions?** Yes — `force_new` creates a new
  analysis row and should create a new reservation/settlement, never reuse
  the old one's accounting.
- **Does manual confirmation affect charging?** No — confirmation
  (`confirmAnalysis()`) is a downstream, deterministic, non-provider-calling
  action; it must never itself consume credits (see Section 4.3).
- **Does downstream sync consume additional credits?** No — confirmed
  deterministic, zero provider calls (Section 4.3).

---

## 8. Charging Event — Recommended Lifecycle

```
Request (user clicks "Analyse")
  → Eligibility check (does the organisation have ≥1 available credit?)
  → Credit reservation (1 credit, tied to this analysis's id)
  → Provider call (existing ContractAnalysisService/TradePackageAnalysisService::analyse())
  → Validation (existing JSON/schema validation, stop_reason check)
  → Settlement (valid, usable result) OR Release (failed before/without a real provider cost)
```

**Recommendation: consumption is based on a successfully completed,
schema-valid analysis reaching `status = 'completed'` (or `'confirmed'`)
— not merely "the provider was called."** This is deliberately *not* "any
provider call occurred" as the sole standard, because:

- A pre-provider-call failure (missing file, unsupported type — classified
  `validation_failure`) never reached the provider at all and must never
  be charged (Section 8's failure table).
- A genuine cache hit never calls the provider and must never be charged
  (Section 9).
- However, a post-provider-call failure (truncated output, unparseable
  response) **did** incur real Anthropic cost even though the customer got
  no usable result — Section 8 below addresses this as its own category,
  distinct from both "clean success" and "never happened."

This mirrors the existing telemetry distinction already built (`provider_called`
flag, `failure_category` including `output_truncated`) — the charging
lifecycle is designed to consume that signal directly, not invent a
parallel one.

**This is a future behavioural specification only — not implemented.**

---

## 9. Cache-Hit Policy

**A genuine cache hit consumes 0 AI Credits, always.** Already true in
spirit today (verified live, G4C.1A) and simply carried forward as
commercial policy, not a new mechanism.

- **Same organisation, same document**: 0 credits — the common, safe case.
- **Same project, repeated request**: 0 credits — no distinction from the
  organisation-level case; project doesn't factor into cache eligibility
  today.
- **Cross-project reuse within one organisation**: 0 credits — same
  reasoning; the cache key is `document_hash`+`model`, not project-scoped.
- **Cross-organisation cache safety — carried-forward finding, not
  resolved here**: the current cache lookup has **no organisation-scoping
  clause** (confirmed unchanged since G4C.0). Today this only means a
  hash match from a *different* organisation's identical document content
  would be reused too — correctness-neutral for the analysis *content*
  (identical input produces identical output regardless of who requested
  it, and the requesting org already possesses the identical document to
  have produced the same hash), but **it must never silently misattribute
  a future credit ledger's zero-charge event across an organisation
  boundary** — a future implementation must record the zero-charge event
  against the *requesting* organisation's own ledger, referencing (for
  audit only, never exposed to the customer) which analysis id it reused
  from. This document does **not** propose tightening the cache lookup
  itself in this phase (that's a separate, already-flagged engineering
  decision, not a credit-policy one) — it only specifies how the ledger
  must behave regardless of whether that tightening ever happens.
- **Expired or superseded analysis**: not currently a concept — no
  expiry/invalidation mechanism exists on cached analyses today (confirmed,
  G4C.0). A future credit ledger should not invent one either; this is out
  of scope for this document.
- **Schema/prompt-version changes**: **must invalidate cache eligibility**
  — reusing a cached result generated under an older schema version (e.g.
  the pre-"schema v2.0" flat `contract_summary` era, G4C.1A's own finding)
  as if it satisfied a request for the current schema would silently
  under-deliver. See Section 19 (cache versioning is a configuration
  concern, addressed there, not duplicated here).
- **Model-version changes**: same — a cache eligible under `claude-sonnet-5`
  must not satisfy a request now configured for a different model. Already
  true today (`model` is part of the existing cache key).
- **User-requested regeneration despite an available cached result**: this
  is exactly `force_new` — already a real, existing user action, and it
  must always create a fresh reservation/settlement, never silently reuse
  the cache's zero-charge status.

**When does a cached result become invalid for free reuse?** When the
document hash, model, or (once introduced — Section 19) prompt/schema
version no longer matches. Never based on time alone (no expiry exists).

---

## 10. Retry and Failure Policy

**Recommended principle** (as stated in the brief, adopted verbatim because
the repository evidence supports it directly): *customers should not lose
credits because SureSign or its provider failed to deliver a usable result,
but should not receive unlimited free reruns after a successful usable
analysis.*

| Failure category (existing `AiFailureCategory`) | Reserve | Settle | Release | Retry allowance | Audit |
|---|---|---|---|---|---|
| `validation_failure` (missing file, unsupported type — never reached the provider) | Yes (at request time) | No | Yes — full release | Unlimited (user simply fixes the input and retries; this is user-source-material correction, not a "retry" in the risk sense) | Log the release with reason |
| `output_truncated` (real provider call happened, tokens were spent, response was cut off) | Yes | **Yes, if the future policy chooses to settle failed-but-costed calls** — see below | N/A if settled | System-caused; a subsequent attempt (e.g. `force_new` after the max_tokens ceiling was raised, exactly what happened in the real G4C.1B investigation) is a new reservation, not a free retry of the same one | Must be visible in future reporting as a distinct, real-cost failure category |
| `provider_rejection` (real call happened, response unusable) | Yes | Same open question as above | N/A if settled | Same | Same |
| `timeout` / `transport_error` (connection-level, provider likely never processed the request) | Yes | No — no confirmed provider execution occurred | Yes — full release | System-caused; automatic or user-initiated retry, unlimited | Log |
| `internal_exception` (SureSign's own bug, e.g. a DB error) | Yes | No | Yes — full release | Unlimited; this is never the customer's fault | Log, and flag for engineering investigation — this indicates a real internal bug, not a normal operational path |
| `unknown` | Yes | No, fail-safe | Yes — full release | Unlimited | Must be investigated — an `unknown` classification indicates the classifier itself needs a new category, not a stable steady-state |
| Cancelled before provider call (`status='pending'` → `cancelled`) | Yes | No | Yes — full release | N/A — user chose to stop | Already true today (existing copy: *"No AI credits were used"*) |
| Cancelled during processing (`status='processing'` → `cancelled`) | Yes | **Open — existing copy already says "may still be charged"** | Partial/no release, per existing precedent | N/A | Existing customer copy already sets this expectation; a future ledger should settle consistent with what the provider actually did, not what the user wished had happened |

**The one genuinely open policy question this document surfaces rather than
resolves**: whether `output_truncated`/`provider_rejection` (a real,
costed provider call that produced no usable result) should **settle** (the
customer is charged, because SureSign incurred real cost) or **release**
(the customer is not charged, because they received nothing usable,
matching the "should not lose credits because SureSign failed" principle).
**Recommendation, requiring Graham's approval**: settle — the "should not
lose credits" principle in the brief is best read as protecting the
customer from losing credits for a failure of *SureSign's own reliability*
(a bug, a timeout, a validation gap), not from a case where the provider
was genuinely, correctly invoked and genuinely returned something SureSign's
own instrumentation just couldn't parse. Real money was spent either way;
absorbing that cost silently on every truncation is a margin decision, not
a reliability guarantee, and should be made deliberately, not by default.

**Distinguishing the four retry types**:
- **System-caused retry** (timeout, transport error, a bug): free, unlimited,
  no reservation consumed.
- **Provider-caused retry** (truncation, rejection): per the open question
  above — may settle.
- **User-requested regeneration** (`force_new` on an already-completed
  analysis, no failure occurred): always a full new charge — this is not a
  "retry" in the failure sense at all, it's a fresh, deliberate action.
- **Correction of user-provided source material** (re-upload after
  `validation_failure`): always free to attempt again, since nothing was
  ever charged for the rejected attempt.

---

## 11. Reservation, Settlement, and Accounting Invariants

### 11.1 Conceptual flow (not implemented)

- **Full reservation, not partial**: since the unit is fixed (1 credit —
  Section 6.1), there is no "maximum possible amount" ambiguity a
  size-banded model would need to resolve before knowing what to reserve.
  Reserve exactly 1 credit at request time, for both workflows.
- **Concurrent requests**: the existing "one active analysis per
  contract/trade-package at a time" guard (already enforced in
  `AiController`/`TradePackageAiController`) is the natural place a future
  reservation attaches — a second concurrent request for the same
  contract already fails today before it would ever reach a credit check.
  Cross-contract concurrency (many different contracts analysed
  simultaneously by one organisation) needs its own atomic
  check-and-decrement, following the same lock pattern already used
  elsewhere in this codebase (`DocumentNumberSequence`/
  `BillingReferenceSequence`).
- **Reservation expiry**: should mirror the existing job timeout
  architecture (`$timeout = 480s` on both AI jobs) — a reservation that
  never settles or releases within a bounded window (e.g. the job timeout
  plus a safety margin) must be treated as stale and released, never left
  to silently hold credits hostage forever.
- **Job crash**: if the job crashes after a successful provider response but
  before persistence (a real, already-anticipated failure mode per
  G4C.1's own idempotency-guard design), the future ledger's settlement
  must be idempotent per analysis id, so a retried job never double-settles.
- **Settlement vs. reservation**: settlement is the final, real charge —
  always 1 credit for a completed analysis under this model (never
  variable, unlike a token-based model where settlement would differ from
  the pre-call reservation estimate).
- **Unused reservation release**: on any terminal failure classified for
  release (Section 10's table), immediately — no batch/delayed release
  process needed given the low volume expected.
- **Idempotency**: keyed on the analysis id (already a stable, unique
  identifier every real workflow produces) — a reservation, settlement, or
  release for a given analysis id must be a no-op if repeated.
- **Retries link to the original reservation only when it's the *same*
  analysis attempt** (e.g. a job re-entry after a crash) — a `force_new`
  retry is always a **new** analysis id and therefore a **new**
  reservation, never a continuation of the old one's accounting (this
  mirrors the existing "retry after failure creates a new row, never
  reopens the old one" behaviour already built).
- **Manual settlement/reversal by an administrator**: yes, conceptually
  (Section 16) — always via a compensating entry, never an edit to an
  existing immutable row.

### 11.2 Required accounting invariants (non-negotiable for any future implementation)

1. No balance mutation without an immutable ledger entry.
2. Every settled consumption is idempotent (keyed on analysis id).
3. The same provider job/analysis can never be charged twice.
4. A reservation can never make available balance negative.
5. A released reservation is never counted as consumption.
6. A cache hit always produces zero settled consumption.
7. A failed provider operation settles a customer's credits **only** when
   the policy explicitly says so (Section 10) — never by default, never
   silently.
8. Historical ledger entries retain their original credit quantity forever
   — a later policy-rate change never rewrites a past entry (Section 20).
9. Administrative adjustments always require an actor, a reason, and a
   timestamp (mirroring the existing Enterprise-override discipline in
   Entitlement Spec §18).
10. Organisation boundaries are enforced on every ledger row.
11. Purchased and granted credits remain independently auditable by source
    (allowance vs. purchased vs. promotional vs. manual — never merged
    into one undifferentiated number).
12. Monthly allowance grants are idempotent — a scheduler re-running for
    the same organisation/period must never double-grant.
13. Expiry (if ever implemented) never silently deletes accounting
    history — an expired grant's history remains queryable.
14. Reversals always use compensating entries, never destructive edits —
    exactly the pattern this codebase already uses for
    `billing_entitlement_snapshots` (immutable, append-only) and
    `subscription_overrides` (append-only history).
15. A future ledger's "current balance" is always a **computed aggregate**
    of immutable entries, never a separately mutable stored number that
    could drift from what the entries actually say.

---

## 12. Plan Allowance Framework

Building directly on the existing, already-approved `ai_analyses_per_month`
mechanism (Section 6.2) — this section specifies how it should behave as
"AI Credits," not a new mechanism from scratch.

| Question | Recommendation | Status |
|---|---|---|
| Grant date | Same as the entitlement snapshot's own effective date — activation, trial start, or plan change (`EntitlementSnapshotService`'s existing transitions) | Confirmed mechanism exists; applying it to a credit-labelled allowance is Provisional |
| Billing-cycle alignment | **Deliberately not aligned to Stripe billing anniversary** — UTC calendar month, exactly as already decided for `ai_analyses_per_month` (Entitlement Spec §12, confirmed 2026-07-23) | Confirmed (already decided, not reopened here) |
| Trial organisations | Trial profile's existing `ai_analyses_per_month = 3` (`PlanEntitlements::trialProfile()`, a real currently-configured value) becomes "3 AI Credits" under the renamed presentation | Confirmed value exists; whether 3 remains the right trial number is Provisional |
| Proration | **No first-month proration**, exactly as already decided (Entitlement Spec §12) — a subscription activated mid-month gets the full monthly allowance | Confirmed (already decided) |
| Upgrade | Immediate, per Commercial Strategy §11 — new (higher) AI Credit allowance available as soon as the plan change is confirmed | Confirmed policy shape; exact new-plan numbers Provisional |
| Downgrade | Scheduled for next renewal, per Commercial Strategy §11 — must warn if current-period usage already exceeds the new plan's allowance | Confirmed policy shape |
| Cancellation | Existing-record access (viewing past analyses) must remain available regardless of subscription state, per Entitlement Spec §15's non-negotiable compliance-record guarantee — this applies to AI analysis *history*, though a cancelled subscription naturally can't start new analyses | Confirmed constraint |
| Grace period | `SubscriptionAccessPolicy::GRACE` mode already resolves full entitlements during `past_due` (unless the grace deadline has passed) — AI Credits inherit this automatically once wired through the same `FeatureGate` path, no separate grace logic needed | Confirmed mechanism; grace-period *duration* itself remains an open founder decision (Commercial Strategy §21) |
| Restricted/suspended/unpaid | `SubscriptionAccessMode::RESTRICTED` already resolves to "not entitled" — a restricted organisation cannot start new AI analyses; existing analysis history remains viewable per Section 15's guarantee | Confirmed mechanism |
| Paused | `SubscriptionAccessMode::NONE` (paused is a settled, deliberate policy decision per the codebase's own checkpoint 10 note — maps to manual review, never a live status in practice) | Confirmed, unrelated to this document |
| Expired | Same as cancellation — no new analyses, historical access preserved | Confirmed constraint |
| Failed payment | Falls under `past_due`/`unpaid` access modes above — no separate AI-specific failed-payment policy needed | Confirmed mechanism |
| Enterprise custom allowance | Via the existing negotiated-override mechanism (`subscription_overrides`, Entitlement Spec §9/§18) — an Enterprise AI Credit allowance is a negotiated `ai_analyses_per_month` override like any other entitlement, not a separate concept | Confirmed mechanism exists |

**No final allowance numbers are proposed or approved here** beyond the
already-existing code defaults cited above (10/50/200/3), which the
Commercial Strategy itself already marks as indicative, not final.

---

## 13. Allowance Expiry and Rollover

| Grant type | Recommendation | Confidence |
|---|---|---|
| Included monthly allowance | **No rollover** — unused credits expire at month end, exactly matching the existing `ai_analyses_per_month` UTC-calendar-month reset (nothing to change) | Confirmed mechanism; "no rollover" as explicit policy is Provisional but consistent with existing behaviour |
| Purchased top-up credits | Recommend a longer, defined validity period (e.g. 12 months) — **not proposed as a specific number**; this only matters if purchased packs are ever approved at all (Section 15 — currently beyond approved strategy) | Provisional, contingent on Section 15 |
| Promotional credits | Recommend explicit start/end dates per grant, mirroring the existing "temporary discounts require an explicit start and end date" policy (Commercial Strategy §9) | Confirmed policy pattern to reuse; specific grant terms Provisional |
| Manual administrative grants | No default expiry unless the granting administrator sets one explicitly | Provisional |
| Enterprise contractual credits | Governed entirely by the underlying negotiated agreement, via the existing override/expiry mechanism (Entitlement Spec §18) | Confirmed mechanism |

**Recommended consumption priority order** (only relevant once more than
one credit source can coexist — not urgent given Section 15's status):
expiring promotional credits → monthly included credits → purchased credits
→ non-expiring contractual credits. This ordering minimises credits lost to
expiry, consuming the most time-sensitive source first.

**No expiry logic is implemented by this document.**

---

## 14. Exhausted Credit Behaviour

| Plan | Recommendation |
|---|---|
| Essential / Professional | **Hard block before the provider call** — no AI action is submitted to the provider without an available credit; this directly prevents the "provider call happens, no credits existed" failure mode Section 11's invariants forbid. |
| Enterprise | Configurable/contractual — an Enterprise account's negotiated terms may include an agreed overage or grace behaviour, but this is never the standard-plan default. |

**No silent negative balances anywhere.** No provider call is ever made
when the required reservation cannot be created. This is a hard constraint,
not a UX preference — it's what makes invariant 4 (Section 11.2) actually
true rather than aspirational.

**Conceptual future error response** (not implemented): a 4xx-style
rejection at the same point `AiController::startAnalysis()` already checks
"is AI enabled"/"is an analysis already in progress" — a natural, existing
pre-flight check location, extended with one more condition. Customer-facing
message should read constructively, consistent with Entitlement Spec §21's
tone guidance ("You've used all your AI Credits for this month — contact us
to discuss a plan with a larger allowance" rather than an alarming or
technical error).

---

## 15. Trials, Sales-Assisted Onboarding, and Purchased Credit Packs

### 15.1 Trials

- Trial AI Credit allowance: the existing `PlanEntitlements::trialProfile()`
  value (currently 3) — a real, currently-configured, deliberately
  conservative number, consistent with Entitlement Spec §17's own reasoning
  ("capped conservatively on AI analyses specifically, since that's the one
  dimension with real, uncontrolled cost exposure").
- Expiry: trial credits do not carry into a paid subscription's own first
  allowance — a conversion grants the new plan's full allowance fresh,
  consistent with "no proration" (Section 12).
- Abuse prevention: no new mechanism needed beyond what already exists
  (sales-assisted-only trial creation, no public self-service signup, per
  Commercial Strategy §8).
- Super Admin/internal organisation exemptions: any internal demo/test
  organisation's AI usage should be excluded from commercial analytics
  (Section 24) — consistent with the existing "test organisation" concept
  this codebase already treats distinctly elsewhere.

### 15.2 Purchased credit packs — **beyond currently approved strategy**

The Commercial Strategy explicitly does not currently support a
purchasable top-up mechanism — this is genuinely new commercial ground, not
a pending-number decision. This document specifies the *shape* of a
possible future policy without proposing it as approved:

| Question | Possible answer | Status |
|---|---|---|
| Available to which plans | Professional/Enterprise only, or all plans | Requires Graham |
| Minimum pack size | N/A | Requires Graham |
| Expiry | Longer than monthly allowance (Section 13) | Requires Graham |
| Refund policy | Follows general refund/reversal invariants (Section 11.2) but the actual policy (refundable at all? under what conditions?) | Requires Graham |
| Transferability | Organisation-owned, non-transferable between organisations, consistent with "organisation is the billable entity" | Reasonably inferable, still Requires Graham for final sign-off |
| Tax/currency | Follows existing Stripe Tax / VAT open decisions already tracked in Commercial Strategy §12/§21 — not a new tax question, just a new product to apply the same unresolved decisions to | Requires the same accountant confirmation already pending for the rest of billing |
| Failed payment | Standard Stripe dunning, consistent with existing renewal/retention policy (Commercial Strategy §19) | Reasonably inferable |
| Chargeback | Existing reversal invariants apply (Section 11.2) | Reasonably inferable |
| Survives cancellation? | Recommend yes for purchased (customer paid for them specifically), no for unused monthly allowance | Requires Graham |
| Auto-purchase (automatic top-up on exhaustion) | Not recommended at all initially — directly contradicts "no silent negative balances"/hard-block philosophy (Section 14) and the sales-assisted, non-self-service commercial model (Commercial Strategy §4) | Recommend against, but Requires Graham to formally close |

**No Stripe product or price is proposed, named, or created by this
document.**

---

## 16. Administrative Controls (Specification Only)

Future Super Admin capabilities, modelled directly on the existing
Enterprise-override discipline (Entitlement Spec §18) — never a silent
balance edit:

- View organisation balance and full ledger (read-only).
- Grant credits (promotional/goodwill/correction) — always with actor,
  reason, timestamp (mirrors invariant 9).
- Reverse erroneous consumption — a compensating entry, never a destructive
  edit to the original settled row.
- Configure an organisation-level override (mirrors `subscription_overrides`
  exactly).
- View/release stale reservations (an operational safety-net tool, not a
  routine action).
- Export usage for support/reporting.
- Attach internal notes to any adjustment — treated as internal-only,
  never customer-visible, per Entitlement Spec §22's existing internal/
  customer-visible split.

**No interface is implemented.** Every change described above must produce
an auditable ledger entry — this document explicitly forbids any
"balance = X" style direct edit, even for administrators.

---

## 17. Customer Visibility and Notifications

### 17.1 What customers should eventually see

Available credits, included monthly allowance, purchased-credit balance (if
that ever exists), next reset date, recent AI usage (workflow name, credits
consumed, status, including zero-credit cache hits and zero-credit
failures shown honestly rather than hidden), a top-up action (if purchased
packs are ever approved), and warning thresholds.

### 17.2 What must never be exposed

Hidden reasoning/thinking-block content, raw prompts, provider credentials,
internal margin, provider request/response bodies, confidential system
diagnostics, or the underlying provider name/model (consistent with the
existing customer-facing convention already enforced in `ClaudeAiProvider`
— error messages never name "Claude" or "Anthropic" to the customer). This
directly extends Entitlement Spec §21/§22's existing customer-visible vs.
internal-only split — no new principle invented, same one applied to a new
domain.

### 17.3 Notification thresholds (future, not implemented)

75%/90%/100% consumed, failed reservation, purchased-credit expiry (if
applicable), monthly reset, Enterprise overage (if applicable) — via
in-app notification and/or email to organisation administrators, mirroring
the existing `NotificationEngineService`/`EmailNotificationService`
architecture already used for every other in-app notification in this
codebase. Tone must follow Entitlement Spec §21's explicit guidance:
constructive, never alarming, given SureSign's compliance-tool positioning.

---

## 18. Configuration Model and Policy Versioning

### 18.1 What must be configurable

Workflow credit rates (Section 6.3), plan allowances (already configurable
via `pricing_plan_entitlements`, Section 6.2), trial allowance, any future
expiry/rollover duration, any future top-up pack values, low-balance
notification thresholds, Enterprise overrides (already configurable via
`subscription_overrides`).

### 18.2 Where configuration belongs

- **Workflow credit rates**: a small config file, following exactly the
  pattern `config/ai_pricing.php` (G4C.1A.1) already established for
  provider pricing — effective-dated, versioned, never a bare hardcoded
  number. This is a **new**, small config surface this document proposes
  (not yet built): `config/ai_credit_rates.php` or equivalent, holding
  `{workflow => credit_rate}` with the same effective-dating discipline.
- **Plan allowances**: already database-managed
  (`pricing_plan_entitlements`) — no change needed.
- **Organisation overrides**: already database-managed
  (`subscription_overrides`) — no change needed.

**Avoid making historical accounting depend on mutable configuration.**
Every settled usage event must preserve, at settlement time, the exact
values used — workflow, the credit rate actually applied, the policy
version, and a reference to the configuration source — so a later change
to `config/ai_credit_rates.php` never silently reinterprets an already-settled
historical entry. This directly parallels how `AiPricingSchedule` already
solves the identical problem for provider cost (G4C.1A.1) — the credit-rate
schedule should be built the same way, not reinvented.

### 18.3 Policy versioning

**A policy change affects future eligible requests only. It must never
recalculate or rewrite historical ledger entries** — identical in spirit to
how `AiPricingSchedule` already guarantees historical cost stability, and
to Entitlement Spec §7's grandfathering guarantee for plan defaults. Every
settled event should record which policy version applied, the same way a
future entitlement snapshot records which plan defaults applied at that
moment. In-flight reservations at the moment of a policy change should
settle under the policy version active when they were *reserved*, not
when they happen to settle, for the same historical-stability reason.

---

## 19. Cache Versioning (Consolidated from Section 7's Deferred Item)

Future cache eligibility should incorporate, in addition to today's
`document_hash`+`model`: workflow (already implicit — separate tables),
prompt version, and schema version (not yet tracked anywhere — a real,
already-identified gap from G4C.1A's own audit, §24.1 of
`ai-credits-architecture.md`). **Conceptual cache identity**:
`document_hash + workflow + model + prompt_version + schema_version`.

**Recommendation**: do not reuse an old cached result once the extraction
schema has materially expanded (e.g. the v1→v2.0 schema evolution G4C.1A
already found) — an old cached v1-schema result silently satisfying a
request that expects v2.0's richer structure would under-deliver in a way
the customer can't detect. Existing legacy-schema cache rows may remain
usable **only** if their version is compatible with the currently requested
output — this document does not resolve what "compatible" means precisely
(that's an engineering decision for whichever phase actually adds
`prompt_version`/`schema_version` columns), only that version compatibility,
not mere hash equality, must govern reuse once those fields exist.

This is documented here as a **prerequisite finding for future engineering
work**, not something this specification phase implements.

---

## 20. Relationship to Entitlements (FeatureGate) — Confirmed Separation

```
Subscription access (SubscriptionAccessPolicy)
  → Feature entitlement (FeatureGate: may this org use AI analysis at all?)
  → Credit availability (future credit service: does this org have ≥1 credit right now?)
  → Reservation
  → AI execution (existing ContractAnalysisService/TradePackageAnalysisService)
  → Settlement
```

**Explicitly confirmed, all consistent with existing architecture, none
changed by this document:**

- Credits do not grant access to a feature the plan doesn't include — if
  AI analysis were ever gated as a feature flag (it currently isn't; it's
  a usage allowance, not an on/off flag), having credits would never
  bypass that.
- Feature access does not imply unlimited credits — the two questions are
  independent, mirroring exactly the existing Feature-vs-Usage-entitlement
  distinction Entitlement Spec §3/Principle 9 already establishes.
- `FeatureGate` remains the sole authority for feature entitlement — this
  document does not touch it.
- A future, separate credit-availability service is the authority for
  *usage* availability — following the same "one central calculation
  method"/"never duplicated ad hoc" principle FeatureGate itself already
  embodies (Entitlement Spec §2 principle 6), applied to a new, adjacent
  concern rather than folded into `FeatureGate` itself (keeping "can this
  org use the feature" and "does this org have enough credits right now"
  as two separately testable questions, exactly as Entitlement Spec §16
  already insists billing status and entitlements must not be collapsed).
- Stripe makes no runtime entitlement or credit-availability decision —
  unchanged, already guaranteed by the existing architecture.

**No FeatureGate change is made or proposed in this phase.**

---

## 21. Relationship to Provider Cost

- Provider cost (real Anthropic dollars, already tracked via
  `estimated_cost`) is **not** the customer's credit balance — they are
  related only through the periodic calibration process (Section 6.4),
  never a live 1:1 conversion.
- AI Credits are not necessarily equal to any currency amount — 1 credit
  is a SureSign-defined unit, not "$0.42."
- A provider price change (e.g. the confirmed 2026-09-01 Sonnet 5 rate
  reversion from $2/$10 to $3/$15, already handled correctly by
  `AiPricingSchedule`) must **not** automatically alter what a customer is
  charged in credits — the internal cost envelope shifts, but the
  customer-facing rate only changes via a deliberate, versioned policy
  decision (Section 18.3), never an automatic pass-through.
- Telemetry supports periodic calibration (Section 6.4); margin review
  remains a commercial process requiring Graham's involvement, not an
  automatic engineering trigger.
- Historical credit deductions remain unchanged regardless of later
  provider price changes — identical guarantee to what `AiPricingSchedule`
  already provides for cost telemetry, just extended to the customer-facing
  ledger once it exists.
- A model change (e.g. moving from Sonnet 5 to a future model) may require
  a new policy version if the calibration in Section 6.4 concludes the
  existing rate is no longer margin-safe — this is exactly the same
  "effective-dated, never retroactive" pattern already proven correct in
  `AiPricingSchedule`.

---

## 22. Telemetry Collection Plan (Parallel Workstream, Not Blocking)

Target future samples, to be collected as real usage occurs (this
specification does not depend on any of them existing before approval):

**Contract Analysis**: small real contracts, medium real contracts,
additional large contracts beyond the single 110-page benchmark, scanned
vs. text-native documents, different contract forms (JCT/NEC3/NEC4/FIDIC/
bespoke) where available.

**Trade Package Analysis**: **the first real document of any size** — the
single highest-priority gap identified in this entire document — then
small/medium/large samples, cache-hit repetitions, and at least one real
failed-and-retried analysis.

For every sample, capture (all fields already exist in telemetry —
nothing new needs building to start collecting): document category, page
count where reliable, `document_char_count`, `tokens_input`/
`tokens_output`, `duration_ms`, `estimated_cost`, `provider_called`/cache
state, retry/attempt count, final `status`/`failure_category`, `model`, and
— once this policy is approved — a simulated credit result under the
model in Section 6 (see Section 23).

**Do not delay this specification's approval until these samples exist.
Do not describe the current n=5 dataset as representative anywhere it is
used.**

---

## 23. Dry-Run Credit Simulation (Proposed, Not Implemented Here)

A future, **non-enforcing** simulation mode should answer, for each
completed analysis: *"how many credits would this have consumed under
policy version X?"* — without deducting any balance, creating any customer
liability, blocking any request, or changing any subscription.

**Recommendation: build this before the real ledger, as its own narrowly
scoped phase (see Section 30's roadmap, G4C.2B).** Rationale: given the
fixed-unit model recommended in Section 6, a simulation is nearly free to
build (every settled analysis already deterministically "would consume 1
credit" under the recommended policy) and lets real telemetry validate the
policy's sustainability (via the Section 6.4 calibration) *before* any
customer-facing balance exists to get wrong. This directly serves the goal
this document was asked to support: calibrating rates from real telemetry
without having built the ledger first.

**Not implemented as part of this document** — proposed as the
recommended next phase only.

---

## 24. Decisions Requiring Business Approval (Graham)

Separated explicitly from engineering decisions, per this document's own
scope constraint:

1. **The customer-facing credit model itself** — confirming fixed-per-
   workflow (Section 6.1) as the actual direction, versus ever revisiting
   size-banding, which the existing Commercial Strategy currently treats as
   out of scope.
2. **The specific credit rate per workflow** (Section 6.3 — currently 1/1,
   unvalidated by real volume).
3. **Whether `ai_analyses_per_month` should be renamed to "AI Credits" in
   customer-facing copy** (Section 6.2), and the exact wording.
4. **Plan allowance numbers** (Section 12 — 10/50/200/3 are current code
   defaults, not approved prices/limits, per the Commercial Strategy's own
   §21).
5. **Whether purchased credit packs should exist at all** (Section 15.2) —
   this is new commercial ground, not a pending number.
6. **Pack prices, minimum size, expiry, refund policy** — all of Section
   15.2's table.
7. **Rollover policy specifics** beyond "no rollover on included allowance"
   (Section 13).
8. **Overage/exhaustion behaviour for Enterprise** specifically (Section
   14) — the standard-plan hard-block default doesn't need approval (it's
   the safe, already-implied default), but any Enterprise exception does.
9. **Whether a failed-but-costed provider call (`output_truncated`/
   `provider_rejection`) settles or releases** (Section 10's open
   question) — a real margin-vs-goodwill trade-off, not an engineering
   call.
10. **Trial AI Credit allowance** — whether 3 remains right (Section 15.1).
11. **Refund/reversal treatment** generally (ties to the existing open VAT/
    credit-note questions already pending accountant confirmation,
    Commercial Strategy §12).
12. **Cancellation treatment of purchased (not included) credits** (Section
    15.2).
13. **Grandfathering of any future rate change** — confirming that a
    workflow credit-rate change (Section 18.3) follows the same
    "communicated, never silent" principle already approved for pricing
    generally (Commercial Strategy §10).
14. **Messaging and terminology** — "AI Credits" vs. any alternative
    branding, consistent tone across in-app copy, emails, and any future
    customer-facing usage page.

---

## 25. Future Ledger Concepts (Conceptual Only — No Schema Proposed)

| Concept | Purpose | Organisation ownership | Idempotency key | Source | Amount | Status | Audit actor |
|---|---|---|---|---|---|---|---|
| Credit account | The organisation's current computed balance context | Yes | N/A (aggregate view) | N/A | N/A | N/A | N/A |
| Allowance grant | Monthly included credits | Yes | (organisation, period) | Plan entitlement snapshot | Plan default (or override) | Active/expired | System (scheduled) or Super Admin (manual) |
| Purchased grant | Top-up pack (if approved) | Yes | Stripe payment/checkout id | Checkout | Pack size | Active/expired/refunded | System (webhook-driven) |
| Promotional grant | Marketing/goodwill credit | Yes | Grant id | Super Admin action | Explicit amount | Active/expired | Super Admin |
| Reservation | Provisional hold before provider execution | Yes | Analysis id | Analysis request | 1 (per Section 6.1) | Active/settled/released | System |
| Settlement | Final consumption | Yes | Analysis id (same as its reservation) | Completed analysis | 1, or 0 for cache hit | Final | System |
| Release | Reverses a reservation that never settled | Yes | Analysis id | Failed/cancelled analysis | Same as the reservation it reverses | Final | System |
| Reversal | Corrects an erroneous settlement | Yes | New id, references the original | Super Admin action | Compensating amount | Final | Super Admin (actor, reason, timestamp required) |
| Manual adjustment | Goodwill/correction unrelated to any analysis | Yes | Grant id | Super Admin action | Explicit amount | Final | Super Admin |
| Policy version reference | Which rate/rule applied at settlement time | N/A (attached to the settlement) | N/A | Config | N/A | N/A | N/A |

**No migration, table name, or schema is proposed here** — this table
exists to establish that the future implementation has a complete concept
inventory to design against, not to pre-decide its physical shape.

---

## 26. Security and Abuse Considerations

- **Credit amounts must be determined server-side, always.** No
  client-provided value (a size estimate, a claimed page count) may ever
  influence how many credits a request consumes — the fixed-unit model
  (Section 6.1) makes this trivially enforceable (the amount never varies
  by input at all), which is itself a meaningful security simplification
  versus a size-banded model that would need to defend a band boundary
  against a manipulated client-side estimate.
- **Concurrent submissions / duplicate queue jobs**: already substantially
  mitigated by existing architecture (`$tries=1`, idempotency guard, one
  active analysis per contract/package) — a future reservation must key
  off the same existing analysis id, adding no new race window.
- **Cross-organisation access**: every ledger row must be organisation-
  scoped and validated the same way every other tenant-isolated table in
  this codebase already is; no new pattern needed, just consistent
  application.
- **Manual adjustment misuse**: mitigated entirely by invariant 9 (actor +
  reason + timestamp, always) — an adjustment with no reason should be
  structurally impossible to create, not merely discouraged by convention.
- **Stale reservations**: bounded by the expiry policy in Section 11.1 —
  never left open indefinitely.
- **Webhook replay for purchased credits** (if ever built): must reuse the
  existing `billing_webhook_events` idempotency architecture
  (`WebhookIngestionService`/`WebhookEventProcessor`), never a new,
  separate webhook-handling path.
- **Refund/chargeback abuse**: handled via the same reversal/compensating-entry
  mechanism (Section 11.2, invariant 14) — never a direct balance edit.
- **Cache poisoning**: not a new risk this document introduces — the
  existing cache-hit mechanism already only ever reuses a result for an
  *identical* document hash; there's no injection point a malicious actor
  could use to get someone else's cached result attributed to their own
  zero-charge event, beyond the already-flagged (Section 9) missing
  organisation-scoping on the lookup itself.
- **Repeated regenerations**: each `force_new` is already a fresh charge
  under this model — there's no "free unlimited regeneration" loophole to
  close, since the fixed-unit charge applies identically every time.
- **Unsupported document flooding**: `validation_failure` never reaches
  the provider and is never charged (Section 10) — this is intentionally
  safe from a customer-experience standpoint, but means a malicious/careless
  user submitting many invalid files costs SureSign nothing in provider
  spend either, so no abuse vector exists there worth hardening further at
  this phase.
- **Provider cost incurred after account restriction**: the hard-block-
  before-provider-call policy (Section 14) already prevents this by
  construction — a restricted organisation never reaches the provider call
  at all, because the credit-availability (and, in practice, the feature
  entitlement) check happens first.

---

## 27. Reporting Requirements (Future, Not Implemented)

**Internal-only**: credits granted, consumed, expired, purchased, reversed;
provider cost; cost-to-credit ratio (the direct output of Section 6.4's
calibration); workflow distribution; organisation outliers; failed-call
cost (the real dollar cost of `output_truncated`/`provider_rejection`
failures, whether or not they're settled to the customer); cache savings
(cost avoided via zero-charge cache hits); policy-version comparison (how
outcomes differ across rate versions, for calibration).

**Customer-facing**: balance, allowance, usage history, next reset,
purchased-credit history (if applicable), expiry, refunds/reversals.

**Provider cost and internal margin remain internal-only, always** — never
exposed in any customer-facing report or UI, per Entitlement Spec §22's
existing internal/customer-visible boundary, applied here without
modification.

---

## 28. Recommended Implementation Sequence

Adjusted from the brief's suggested sequence to reflect what this review
actually found (particularly: `FeatureGate` isn't wired into any
enforcement yet, and a fixed-unit model makes simulation genuinely cheap
to build first):

1. **G4C.2A — Policy Approval and Configuration Contract**: get Section 24's
   decisions actually approved; build `config/ai_credit_rates.php` (Section
   18.2) following the `ai_pricing.php` pattern exactly. No ledger yet.
2. **G4C.2B — Non-Enforcing Credit Simulation** (Section 23): compute "would
   this have consumed N credits" for every real analysis going forward,
   logged/reported only, never enforced. Cheapest possible way to validate
   the fixed-1-credit model against real accumulating telemetry before
   committing to a ledger schema.
3. **G4C.3 — Immutable Ledger Foundation**: the concepts in Section 25,
   built as real tables — additive, immutable, organisation-scoped.
4. **G4C.3A — Reservation and Settlement**: wire the lifecycle in Section 8
   into the real `ContractAnalysisService`/`TradePackageAnalysisService`
   call sites.
5. **G4C.3B — Monthly Allowance Grants**: idempotent scheduled grants,
   reusing the existing entitlement-snapshot-adjacent timing.
6. **G4C.3C — Workflow Integration**: both real workflows fully wired to
   the ledger; simulation mode (G4C.2B) can be retired once this ships.
7. **G4C.4 — Subscription and Entitlement Integration**: the credit-
   availability check joins the existing `FeatureGate`-adjacent access
   flow (Section 20) — this phase likely also needs to address that
   `FeatureGate` itself still isn't called by any module yet, a
   pre-existing gap this document found but does not fix.
8. **G4C.4A — Purchased Credit Packs**: only if Section 15.2's business
   decisions are actually approved — otherwise this phase simply doesn't
   happen, and that's a legitimate outcome, not a stalled roadmap item.
9. **G4C.5 — Customer and Super Admin Usage Experience**: Sections 16/17
   built as real UI.
10. **G4C.6 — Production Calibration and Monitoring**: Section 6.4's
    calibration run for real, against real multi-organisation volume.

Each phase remains independently testable and narrowly scoped, matching
every prior phase in this codebase's own delivery pattern.

---

## 29. Open Questions

- Does `output_truncated`/`provider_rejection` settle or release? (Section
  10 — requires Graham.)
- Should Trade Package Analysis ever have a different credit rate than
  Contract Analysis, once real telemetry exists? (Section 6.3/21 — requires
  telemetry, then Graham.)
- Should the cache lookup ever become organisation-scoped? (Section 9 —
  an engineering decision, flagged but not resolved here, carried forward
  unchanged from G4C.0.)
- Is `ai_analyses_per_month` renamed in the database/API, or only in
  UI copy? (Section 6.2 — leans toward UI-only to avoid an unnecessary
  migration, but not decided here.)
- What exactly triggers a `prompt_version`/`schema_version` bump, and who
  owns updating it? (Section 19 — an engineering process question for
  whichever phase adds those columns.)
- Should purchased packs exist at all? (Section 15.2/24 — squarely
  Graham's decision, not resolved here.)

---

## 30. Explicit Non-Goals of This Document

This document does not, and none of the above should be read as having:

- Created any AI Credit database table, migration, or schema.
- Implemented any deduction, reservation, or settlement code.
- Implemented any refund or reversal engine.
- Implemented any subscription enforcement.
- Changed `FeatureGate` behaviour.
- Created any Stripe product, price, or checkout flow.
- Implemented any customer-facing usage dashboard.
- Implemented any Super Admin credit-management UI.
- Approved any final numeric allowance, rate, or price — every number
  above is either an existing code default (itself not founder-approved
  per the Commercial Strategy) or explicitly Provisional.
- Treated the current five-row local telemetry sample as representative
  of real customer usage anywhere in this document.
- Changed production AI behaviour, pricing, or any customer-visible
  wording.
- Designed chunked analysis (out of scope, already deferred per
  `ai-credits-architecture.md` §23.4).
- Speculated about any provider integration beyond the existing Anthropic/
  Claude Sonnet 5 integration.

---
---

# Part Two — G4C.2A–B: Final AI Credit Policy (Frozen for V1)

**This part supersedes Sections 5–13 and 18–20 above wherever they
conflict.** It does not repeat the architecture review, workflow
inventory, accounting invariants, security considerations, or reporting
requirements from Part One (Sections 1–4, 14–17, 21–30) — those remain
valid and are relied upon unchanged. This part re-specifies only the
credit model itself, arrived at across three rounds of review: the
original flat-1 recommendation (Section 6, now superseded), a rejected
token-derived-band counter-proposal, and this final, provider-independent,
per-workflow-configurable model.

## 31. Final Commercial Philosophy

**AI Credits are commercial units, not computational units.**

An AI Credit represents the commercial value SureSign delivers via a given
AI-assisted workflow — not a measurement of provider compute. A credit
must never directly expose or depend on: provider input/output tokens,
provider pricing, execution duration, tokenizer behaviour, model
selection, routing decisions, or infrastructure changes.

**Why this fits SureSign specifically**: SureSign is sold as a compliance/
contract-administration system, not an AI tool (Commercial Strategy §2).
Pricing AI around provider mechanics would misrepresent what's actually
being sold and would make the customer-facing unit hostage to a vendor
relationship the customer has no visibility into or interest in.

**Engineering implication**: provider tokens/cost/duration remain fully
recorded (G4C.1/G4C.1A telemetry, unchanged) but sit strictly on the
calibration/monitoring side of a hard boundary — no code path may compute
a customer-facing credit charge from them directly.

**Commercial implication**: a customer's bill (in credits) is stable
across provider switches, model upgrades, and tokenizer changes — this is
now a design guarantee, not an accident of the current provider.

**Alternatives considered**: token-based billing (Section 5, Option A —
rejected, unpredictable, exposes provider economics); provider-cost-derived
units with a live conversion (Section 5, Option D — rejected as a *direct*
customer mechanism, retained as the calibration input it always was).

**Telemetry required to validate this further**: none — this is a design
principle, not a number. It requires no data to adopt, only discipline to
enforce in every future workflow's implementation.

## 32. Universal Charging Principle

**Every provider-backed AI workflow must define its own configurable AI
Credit resolution policy, resolved from a provider-independent measurement
of its own normalized input, before execution.** This applies uniformly —
no workflow is special-cased, and no workflow is exempted from the
*mechanism*. What is **not** uniform, and must not be: the specific
*complexity* of each workflow's policy.

### 32.1 Flat and banded are both first-class strategies within one mechanism

The universal principle is about the mechanism (config-driven,
provider-independent, resolved pre-execution), not about mandating
banding everywhere. A workflow's resolution policy has a
`charging_strategy`, and exactly two values are defined for V1:

- `flat` — a single credit value, regardless of input size.
- `banded` — a small set of input-size bands, each with its own credit
  value.

Both are configured through the identical schema (Section 35). Neither is
a special case, an escape hatch, or a "shortcut" bolted onto the other —
they are two values of the same field.

### 32.2 `flat` requires justification, not mere convenience

Per your explicit instruction: **`flat` must never be selected simply
because it's easier to configure or because a workflow "seems small."**
It is the correct choice only when workflow-specific telemetry and
commercial review together show that input-size variation does not
materially affect the workflow's real cost or value — a finding, not a
default. Contract Analysis and Trade Package Analysis are **not** assumed
to qualify for `flat` merely because that was the prior recommendation;
Section 33's per-workflow discussion revisits both from scratch under this
new principle.

### 32.3 Future workflows: no invented bands, no invented flatness

For a workflow that does not exist yet (no real implementation, no real
telemetry), its charging policy is **`unresolved`** — a third, explicit
state, not defaulted to either `flat` or `banded`. An `unresolved` policy
must block that workflow from being offered to customers as a credit-consuming
action at all until a real policy is set from real data. This is the
direct application of the same discipline this document has held
throughout (G4C.0's "do not report percentiles from an empty dataset,"
G4C.1B's "do not build chunking for a document size that doesn't exist
yet") — applied here to policy *values*, not just statistics.

**Engineering implication**: the config schema (Section 35) must support
`unresolved` as a real, distinct value — not merely the *absence* of a
row, which could be silently misread as "not yet configured, so
presumably free" or "not yet configured, so presumably flat-1." An
explicit `unresolved` state fails safe and loud.

**Commercial implication**: no workflow should be shipped to real
customers with a guessed rate. This is a real constraint on the roadmap
(a new AI feature cannot commercially launch on day one with a made-up
credit cost) — deliberately, since guessing here is exactly the failure
mode this whole document exists to prevent.

## 33. Provider-Independent Input Philosophy

**Why normalized workflow input, not provider tokens:**

1. **Tokenizers are not stable, confirmed empirically, not hypothetically.**
   Anthropic's own pricing documentation (fetched live during G4C.1A.1)
   states Claude 4.7+ models use a newer tokenizer producing **~30% more
   tokens for the same text** than earlier models. A token-based credit
   band would silently reclassify identical customer documents purely
   because SureSign upgraded a model — with zero product change and zero
   customer visibility into why.
2. **Multi-provider/multi-model routing would create incoherent pricing.**
   Two customers with byte-identical input, routed to different
   providers/models (for cost, redundancy, or quality reasons), must see
   the same credit charge. Token-based bands cannot guarantee this;
   document-based bands do, by construction.
3. **This is not a new principle — it's an existing one, applied one layer
   higher.** `AiPricingSchedule` (G4C.1A.1) already guarantees a
   historical *cost* figure never drifts when provider pricing changes.
   Extending "stable regardless of provider" from the internal cost ledger
   to the customer-facing credit charge itself is the consistent next
   step, not a new architectural pattern.
4. **Output tokens specifically must never determine a charge** (carried
   forward from the prior review round) — they are only known *after* the
   provider call, and pricing off them reintroduces the "estimate now,
   actual later" unpredictability this document explicitly rejects
   (Section 34's reserve = settle requirement).

**Engineering implication**: every workflow needs its own definition of
"normalized input" appropriate to what it actually processes (Section 33's
worked examples) — there is no single generic formula, because a
Contract Analysis's input (one document) and a future Meeting Minutes
workflow's input (a transcript) are structurally different things to
normalize.

**Commercial implication**: this is what makes the "customer never pays
differently because SureSign changed providers" guarantee actually true,
not just claimed.

**Alternatives considered**: raw/normalized provider token count
(rejected, Section 33 points 1–2); "estimated tokens" via a chars÷4-style
heuristic (rejected — this is characters wearing a tokenizer costume,
not a genuinely different metric, and dishonestly implies provider
awareness it doesn't have); structural/semantic complexity scoring (e.g.
AI-assisted document structure mapping — rejected as premature, identical
reasoning to why chunked analysis was deferred: no representative
telemetry, real engineering cost, solving a problem not yet confirmed to
exist).

## 34. Charging Lifecycle

```
Normalize workflow input
  ↓
Resolve workflow policy (flat / banded / unresolved)
  ↓
Resolve exact credit charge (a single, known number — never a range)
  ↓
Reserve exact amount
  ↓
Execute AI (existing ContractAnalysisService/TradePackageAnalysisService, unchanged)
  ↓
Settle same amount
```

**Under successful execution: Reserved Credits = Settled Credits, always.**
No estimate-versus-actual step, no post-execution recalculation, no
customer surprise — for both `flat` and `banded` strategies, because both
resolve their exact charge from input that is fully known *before* the
provider is ever called. This is the direct payoff of Section 33's
provider-independence requirement: because nothing about the charge
depends on what the provider does or returns, there is never a reason to
defer the real number to "after."

Reservation and settlement remain conceptually separate accounting events
in a future ledger (per Section 11's invariants, unchanged) — separate for
audit/idempotency reasons, not because the *amount* could differ between
them.

**This remains a future behavioural specification only — not
implemented.**

## 35. Configuration Architecture (Conceptual Only)

No runtime code, migration, or schema is created by this document. The
following is the recommended **shape** a future configuration must have —
directly extending the pattern already proven correct for provider pricing
(`config/ai_pricing.php` / `AiPricingSchedule`, G4C.1A.1), applied to
credit policy instead of provider cost:

```
per workflow:
  workflow_identifier        (e.g. "contract_analysis" — reuses App\Support\AI\AiWorkflow)
  policy_version             (effective-dated, per Section 36)
  effective_from / effective_until
  charging_strategy          ("flat" | "banded" | "unresolved")
  input_measurement          (which normalized-input metric this workflow uses — Section 33's
                              worked examples; workflow-specific, not a shared platform default)
  normalization_strategy     (reference to Section 36's contract — which normalization rules apply)
  band_definitions           (only present when charging_strategy = "banded" — an ordered list
                              of {label, lower_bound, upper_bound, credit_value})
  flat_credit_value          (only present when charging_strategy = "flat")
  customer_visible_label     (e.g. "Contract Analysis" — never the internal workflow identifier)
  internal_notes             (engineering/commercial reasoning for this policy version — internal only,
                              never customer-facing, mirroring Entitlement Spec §22's existing
                              internal/customer-visible split)
  grandfathering_reference   (if this policy version changed from a prior one, a pointer to what
                              existing customers/analyses remain under, per Section 36)
```

**Why this shape, and not something more generic**: a single generic
"credit formula" field (e.g. a stored expression string) was considered
and rejected — it would reintroduce exactly the "arbitrary per-feature
micro-formula" complexity this document's own guardrails warn against,
and it would make `unresolved` awkward to represent cleanly (an empty
formula is ambiguous; an explicit `charging_strategy: unresolved` is not).
The three-value `charging_strategy` enum keeps the schema closed, honest,
and easy to validate.

**No band thresholds, flat values, or `unresolved`-to-real transitions are
proposed as numbers anywhere in this document** — this section specifies
structure only.

## 36. Workflow Examples (Illustrative Only — None Approved)

**Restating the constraint explicitly, since these are easy to
misread as decisions**: every example below is illustrative of *how a
workflow's normalized input might be defined*, not an approved policy, a
committed band threshold, or a confirmed `charging_strategy` value. Per
Section 32.3, any workflow without real telemetry is `unresolved`,
regardless of how plausible its illustrative example looks here.

| Workflow | Real today? | Illustrative normalized input | Illustrative `charging_strategy` |
|---|---|---|---|
| Contract Analysis | **Yes** (real, provider-backed) | Normalized document content (Section 37) | Revisit from scratch under Section 32.2 — not assumed `flat` just because that was the prior recommendation. Requires the same telemetry gap (small/medium samples) already identified in Part One before a real decision can be made either way. |
| Trade Package Analysis | **Yes** (real, provider-backed) | Normalized document content, identical basis to Contract Analysis | Same open status; weaker confidence still, since **zero real documents have ever been analysed by this workflow** (Part One, Section 4.2 — unchanged finding). |
| AI Summary | No — does not exist | Normalized source content being summarised | `unresolved` — no telemetry, no workflow, no decision possible |
| AI Clause Comparison | No — does not exist | Normalized combined content of the clauses/documents being compared | `unresolved` |
| AI Risk Extraction | No — does not exist | Normalized document content | `unresolved` |
| AI Meeting Minutes | No — does not exist | Normalized transcript content | `unresolved` |
| AI Programme Generation | No — does not exist | Normalized project/programme context | `unresolved` |
| **AI Chat** | **No — confirmed non-functional.** `AiConversation`/`AiMessage`/`AiOutput` models and their registered routes exist, but `AiController` has no corresponding methods (reconfirmed as recently as Phase G4C.0). | Normalized conversation context, if it were ever built | `unresolved` — and cannot become anything else until the feature is real, has a provider call, and has telemetry. Listed here only because the review brief named it as an example; it must not be read as a roadmap commitment. |

**Engineering implication**: the workflow inventory in this table is not
a build queue — most rows describe a hypothetical future feature, not
planned work.

**Commercial implication**: no pricing conversation about any
`unresolved` workflow should happen before that workflow is real and
measured — this table gives engineering and commercial stakeholders a
shared vocabulary for *when that day comes*, not a set of numbers to
negotiate now.

## 37. Input Normalisation Contract (Conceptual)

A single, shared normalization discipline every workflow's `input_measurement`
should follow, to keep "normalized document content" honestly comparable
across file types and avoid the extraction-artifact drift identified in
the prior review round:

- **Whitespace normalization**: collapse consecutive whitespace/blank
  lines to a single space/line — prevents a loosely-formatted document
  from measuring larger than an equivalent tightly-formatted one.
- **Line-ending normalization**: normalize `\r\n`/`\r`/`\n` consistently
  before measuring — avoids a platform/tool-of-origin artifact affecting
  size.
- **UTF-8 safety**: reuse the existing `mb_strlen()`/`mb_convert_encoding()`
  discipline already applied in `ContractAnalysisService::parseJsonResponse()`
  — no new encoding-handling approach needed.
- **Extraction-artefact stripping**: a concrete, already-identified case —
  `ContractAnalysisService::extractTextFromElement()` inserts a literal
  `" | "` between DOCX table cells. This kind of structural separator is a
  real extraction artefact, not real document content, and must not
  inflate the normalized measurement.
- **DOCX tables**: normalize cell-separator artefacts (above) without
  discarding genuine tabular content — the *presence* of a table's real
  text should still count; only the artificial join character should not
  be double-weighted.
- **PDF extraction**: text-layer extraction only (`pdftotext`, unchanged) —
  no OCR exists or is proposed. A scanned, text-layer-free PDF already
  fails validation before reaching any credit calculation (`ContractAnalysisService::analyse()`'s
  existing empty-text check) — this is a pre-existing constraint, not a
  new gap this document introduces.
- **TXT extraction**: already the simplest, most literal case — minimal
  normalization needed beyond whitespace/line-ending handling.
- **Empty extraction**: already handled — fails before reaching credit
  logic at all (existing `RuntimeException`).
- **OCR limitations**: explicitly out of scope, unchanged from Part One —
  no OCR capability exists or is proposed anywhere in this document.
- **Structural separators generally**: any workflow-specific extraction
  step that inserts non-content characters for structure (table
  separators, section markers) should be identified and excluded from
  that workflow's normalized measurement, following the DOCX example
  as the template case.
- **Meaningful formatting preservation**: normalization must not strip
  content that changes *meaning* (e.g. collapsing distinct paragraphs
  into one, losing clause boundaries) — only redundant/structural noise.

**Do not redefine existing telemetry.** `ContractAiAnalysis::document_char_count`
(G4C.1, raw `mb_strlen()` of extracted text) keeps its existing meaning
unchanged. If a normalized measurement is adopted, it is a **new,
additive concept** — e.g. a future `normalized_input_size` telemetry field
— computed alongside the existing raw one, never replacing it.

## 38. Failure and Retry Policy (Engineering Defaults vs. Commercial Decisions)

Because every charge is now resolved once, pre-execution, from
provider-independent input (Section 34), the failure/retry table from
Part One (Section 10) is **simplified**, not replaced — most of its
complexity existed to handle "was a real, costed provider call made"
questions that remain valid, but the settle/release decision no longer
needs to consider a variable, output-dependent amount:

| Category | Engineering default (no approval needed) | Requires Graham's approval |
|---|---|---|
| Cache hit | Zero credits, always — unchanged from Part One Section 9, re-confirmed live | — |
| Validation failure (never reached the provider) | Full release, always — the charge was never even reserved against a real attempt | — |
| Provider failure (timeout/transport/internal exception — provider likely never processed, or SureSign's own bug) | Full release, always — never the customer's fault | — |
| Provider failure where a real call demonstrably happened but produced no usable result (`output_truncated`/`provider_rejection`) | — | **Settle or release?** Same open question carried forward from Part One Section 10, unchanged by this revision — a real margin-vs-goodwill trade-off, not resolved here |
| Malformed response (schema-invalid but real call happened) | Same as above | Same as above |
| Duplicate request / idempotent processing | Never double-charged — already structurally guaranteed by the existing `$tries=1` + idempotency guard + analysis-id-keyed settlement (Part One Section 11.2, invariant 2–3) | — |
| User-requested regeneration (`force_new`) | Always a full new charge — a new analysis id, a new reservation, never a continuation of the old one's accounting | — |
| Cancellation before provider call | Full release, always — matches existing customer-facing copy ("No AI credits were used") | — |
| Cancellation during processing | Existing copy already sets the expectation ("may still be charged") | Whether this becomes a hard settle-or-release rule, or stays a case-by-case operator judgment |

**What's now cleanly an engineering default that wasn't before**: since
`Reserved = Settled` always (Section 34), there is no longer a "partial
settlement" or "settle-at-actual-lower-amount" case to design for at all
— every row above resolves to exactly one of {full charge, full release},
never a fraction. This is a direct, positive simplification the
provider-independent model buys, worth naming explicitly.

## 39. Entitlement Migration Recommendation — `ai_analyses_per_month` → `ai_credits_per_month`

**Recommendation: apply the existing, already-approved entitlement
deprecation policy (Entitlement Specification v1, §20) exactly as
written — do not invent a new migration philosophy.**

1. **Introduce `ai_credits_per_month` as a genuinely new entitlement key**,
   not a rename of `ai_analyses_per_month`. This is not cosmetic: once
   any workflow adopts `banded` charging, 1 analysis no longer reliably
   equals 1 credit, which is a real semantic change to what the number
   means — exactly the "a feature is repackaged... requires the
   deprecation policy, not an implicit reinterpretation" case Entitlement
   Spec §20 already anticipates.
2. **Mark `ai_analyses_per_month` deprecated**, not deleted — its original
   meaning (a flat count of analyses) remains valid for any subscription
   still snapshotted against it, per the same section's existing
   discipline (deprecated keys retain their historical rows and meaning
   for as long as any subscription references them).
3. **Do not silently repurpose or reinterpret the old key.** A snapshot
   already resolved against `ai_analyses_per_month` continues to mean
   exactly what it meant when captured — never retroactively read as
   "credits" after the fact.
4. **Explicit, deliberate migration path** for existing subscriptions —
   never automatic, mirroring Commercial Strategy §10's "customer terms
   must never change silently" and Entitlement Spec §20's own migration
   step. A future implementation phase (not this document) would define
   exactly when/how an existing subscription's entitlement snapshot is
   deliberately re-issued under the new key — this document only
   confirms *that* it must be deliberate, not *when* it happens.
5. **Grandfathering**: an existing subscription's `ai_analyses_per_month`
   allowance is not automatically reinterpreted as an equivalent number of
   `ai_credits_per_month` — that conversion (if it happens at all) is
   itself a commercial decision (how many credits does "10 analyses/month"
   become, once analyses can cost more than 1 credit each?), not an
   engineering formula.
6. **`FeatureGate`/reporting implication**: `Feature::ALL` gains an 11th
   key (`ai_credits_per_month`) — this itself needs the same "credible,
   named commercial requirement" justification Entitlement Spec §4
   already requires for adding any key, which this document's own
   findings (real margin variance the flat model couldn't address)
   satisfy. `UsageMetricsService`'s registry-driven usage-card generation
   (Part One Section 2, `Feature::ALL` iteration) already supports a new
   key with no structural change — it would simply need a new resolver
   case, the same shape as the three that already exist.

**Why this fits SureSign specifically**: this is the *lowest-risk* possible
migration path precisely because it's not a new pattern — it's the
pattern this codebase already committed to for exactly this situation.
**Engineering implication**: one new Feature key, one new resolver in
`UsageMetricsService`, no change to `FeatureGate`'s resolution logic
itself. **Commercial implication**: existing customers' current allowances
are untouched until a deliberate, communicated migration decision is
made — no silent renegotiation of what any customer is currently
entitled to. **Alternatives considered**: renaming the key in place
(rejected — violates Entitlement Spec §20 directly, and would retroactively
reinterpret every existing snapshot); maintaining two independently-tracked
allowances forever (rejected — unnecessary permanent complexity once a
deliberate migration is completed). **Telemetry required**: none to define
the migration *policy* (this section); real usage data is still required
before the *credit-per-workflow rates* that would make the old-to-new
conversion meaningful (Section 33/40) exist at all.

### 39.1 Existing `UsageMetricsService` Trade Package gap — timing recommendation

The previously identified defect (`UsageMetricsService::aiAnalysesThisMonth()`
only counts `ContractAiAnalysis`, never `TradePackageAiAnalysis`) remains
its own separate issue, per your instruction, and is **not** fixed by this
document. **Recommendation: fix it before, not during or after, the AI
Credit rollout** — specifically, before `ai_credits_per_month` is
introduced (Section 39), so the new entitlement's usage counting is
correct from its very first day rather than inheriting a known,
already-documented undercount. Fixing it independently first also keeps
the two changes auditable separately: a reviewer can confirm "Trade
Package usage counting was fixed" and "AI Credits were introduced" as two
distinct, individually-verifiable events, rather than one conflated
change where a usage-count anomaly post-launch would be ambiguous as to
which change caused it.

## 40. Audit Requirements — Minimum Facts Future Records Must Preserve

Every future settled credit event (once a ledger exists — not built by
this document) must preserve, at minimum:

- `workflow_identifier`
- `policy_version` (which configuration version — Section 35 — was in
  effect at settlement time)
- `charging_strategy` applied (`flat` or `banded`)
- The exact `normalized_input_size` measured (and which normalization
  strategy version produced it — Section 37)
- The resolved band label, if `banded`
- The exact credit amount reserved and settled (always equal, Section 34)
- Analysis id (the existing, already-stable idempotency key)
- Organisation id
- Timestamp
- Cache-hit status (`provider_called` — already recorded)
- Failure category, if applicable (already recorded, `AiFailureCategory`)

**Provider telemetry** (tokens, provider, model, `estimated_cost`,
duration) continues to be recorded on the same row/analysis **for
calibration and profitability analysis only** — never as an input to the
settled amount itself. This dual-recording (commercial facts + provider
facts, on the same underlying analysis, serving two genuinely separate
purposes) is the concrete expression of Section 31's "commercial units,
not computational units" principle — both live side by side, and the
audit trail must make clear which fields determined the customer's charge
(the commercial ones) and which exist purely for SureSign's own internal
review (the provider ones).

## 41. Graham Approval Checklist (Final, Consolidated)

Every item from Part One's Section 24, still open, plus what this
revision adds or changes:

1. Confirm the final philosophy itself (Section 31) — commercial units,
   not computational units.
2. Confirm the universal mechanism (Section 32) — every workflow
   configurable, `flat`/`banded`/`unresolved` as the only three states.
3. **Per-workflow `charging_strategy` for Contract Analysis and Trade
   Package Analysis specifically** — genuinely reopened by this revision,
   not assumed `flat` from the prior round.
4. Whether/how to convert an existing subscription's
   `ai_analyses_per_month` allowance into an equivalent
   `ai_credits_per_month` number at migration time (Section 39.5) — an
   unresolved commercial formula, not an engineering one.
5. Whether `output_truncated`/`provider_rejection` settles or releases
   (Section 38 — carried forward unresolved from Part One).
6. Whether/when to rename `ai_analyses_per_month` in customer-facing
   copy, separate from the entitlement-key question.
7. Plan allowance numbers generally (Part One Section 12 — still
   provisional code defaults).
8. Whether purchased credit packs should exist at all (Part One Section
   15.2 — unchanged, still beyond currently-approved strategy).
9. Rollover/expiry specifics beyond "no rollover on included allowance."
10. Enterprise overage treatment.
11. Trial AI Credit allowance number.
12. Refund/reversal treatment (ties to pending accountant/VAT questions
    already tracked in Commercial Strategy §12).
13. Terminology/messaging generally.
14. **New**: approval to introduce a genuinely new, 11th entitlement key
    (`ai_credits_per_month`) at all, per Entitlement Spec §4's "credible,
    named commercial requirement" bar — this document argues that bar is
    met (Section 39) but the actual key addition still needs sign-off,
    consistent with how every previous entitlement key addition in this
    codebase has been treated.

## 42. Readiness Assessment — V1 Freeze Decision

**This specification is ready to freeze as an architecture — it is not
ready to freeze with real numbers, and it should not be.**

What's genuinely settled and safe to build against once approved:
the commercial philosophy (Section 31), the universal
flat/banded/unresolved mechanism (Section 32), the provider-independence
requirement (Section 33), the reserve-equals-settle lifecycle (Section
34), the configuration shape (Section 35), the normalization contract
(Section 37), the entitlement migration pattern (Section 39), and the
audit-field minimum (Section 40). None of these require any further
telemetry to approve — they are architecture and policy-shape decisions,
not calibration numbers.

**What remains a genuine blocker, not a formality**:

- **Contract Analysis's and Trade Package Analysis's own `charging_strategy`
  is still genuinely undecided** — Section 32.2 explicitly forbids
  defaulting to `flat` out of convenience, and Part One's own telemetry
  section already establishes there is no small/medium Contract Analysis
  sample and zero Trade Package samples of any size. This is the single
  largest remaining blocker to a fully numeric V1: the two *real* workflows
  don't yet have a settled policy under the new, stricter standard.
- No representative telemetry exists to set any band threshold, for any
  workflow, if banding is where either real workflow ends up.
- The `ai_analyses_per_month` → `ai_credits_per_month` conversion formula
  for existing subscriptions is unresolved (a commercial decision, not
  yet asked of Graham in a form he can answer).

**Recommended smallest, safest next phase: G4C.2B — Non-Enforcing Credit
Simulation, scoped narrowly to *resolving the two real workflows'
`charging_strategy`*, not to building the ledger.**

Concretely, the smallest safe next phase should:

1. Add the two new, additive telemetry fields Section 37/40 imply
   (`normalized_input_size`, `normalization_strategy_version`) to both
   real workflows' existing analysis tables — mirroring exactly how
   `document_char_count` was added in G4C.1, i.e. nullable, additive,
   no backfill of historical rows beyond what's safely knowable.
2. For every real analysis going forward, compute and log (never enforce,
   never bill) a **hypothetical** credit result under both a
   `flat` and an illustrative `banded` policy — purely for comparison,
   exactly matching Section 22's existing telemetry-collection-plan intent
   from Part One, now made concrete.
3. **Do not pick `flat` or `banded` for either real workflow yet** — let
   this simulation run until Part One Section 3's telemetry gap
   (small/medium Contract Analysis samples, any real Trade Package
   sample) is actually closed by real usage, then bring the comparison
   back to Graham as a data-backed recommendation, not a guess dressed up
   as one.
4. Fix the `UsageMetricsService` Trade Package undercount (Section 39.1)
   in this same phase, before it, or as an explicitly separate PR within
   it — but treat it as its own reviewable change, not folded silently
   into the credit-simulation work.

This is smaller than the roadmap originally proposed in Part One (Section
28) precisely because Part One's roadmap assumed the credit model itself
was settled; it isn't yet, for the two workflows that actually matter
today. Building a full ledger foundation (G4C.3 onward) before this
narrower simulation closes the telemetry gap would repeat the exact
mistake this whole document exists to avoid — designing real
architecture against a number nobody has actually measured yet.

---

## 43. Phase G4C.2C-2 — Implementation of the Non-Enforcing Simulation (Built)

Section 42's recommended next phase (there called G4C.2B) was built as
**G4C.2C-2**, alongside the separate, urgent G4C.2C-1 customer-facing
telemetry-leak fix (see `project-context.md`/`CLAUDE.md`'s G4C.2C-1
entries — a different, independently-shipped unit of work). This section
records what was actually built, not a re-statement of the plan above.

### 43.1 What was built

1. **`UsageMetricsService` Trade Package fix** (Section 39.1) — landed
   first, as its own reviewable change, with 6 dedicated tests
   (`tests/Unit/UsageMetricsAiCountingTest.php`).
2. **Telemetry immutability** — `App\Support\AI\AiTelemetryIntegrityGuard`
   makes execution-telemetry fields immutable once a `ContractAiAnalysis`/
   `TradePackageAiAnalysis` row reaches a terminal status
   (completed/confirmed/failed/cancelled), via each model's `updating`
   event. This was not explicitly asked for in Section 42's plan but
   follows directly from Section 40's audit-immutability principle: a
   simulation's own inputs (tokens, cost, document metrics) must not be
   able to drift after the fact.
3. **Provider-independent normalized input** —
   `App\Services\AI\AiInputNormalizer::normalizedCharCount()` implements
   Section 37's contract exactly (UTF-8 safety, line-ending
   normalization, DOCX `" | "` artefact stripping, whitespace collapsing).
   `VERSION` ('v1') is stamped onto every simulation result.
4. **Candidate policy configuration** —
   `config/ai_credit_simulation_policies.php`, mirroring
   `config/ai_pricing.php`'s effective-dated-period shape exactly. Two
   named candidates per real workflow: `candidate_a` (flat, 1 credit —
   kept as a live comparison baseline, explicitly labelled as the
   superseded Section 6 recommendation, not a re-endorsement of it) and
   `candidate_b` (banded, illustrative example thresholds only — NOT
   calibrated from real telemetry; this environment still has no
   representative small/medium Contract Analysis sample and no real
   Trade Package sample, exactly as Part One's telemetry-limitations
   section already established). **Neither candidate is an approved
   commercial rate.**
5. **Simulation engine and storage** —
   `App\Services\AI\AiCreditSimulator` + `App\Models\AiCreditSimulationResult`
   (table `ai_credit_simulation_results`). Implements Section 34's
   reserve-equals-settle spirit for simulation purposes: every candidate
   resolves to exactly one of `calculated` / `unresolved` / `unavailable`
   / `error` — never a guessed number standing in for any of those.
   Deliberately named and shaped to be impossible to confuse with a
   future real ledger (no balance, no debit/credit sign, no
   reservation/settlement state — see the owning migration's own
   docblock). Idempotent via a unique constraint on
   `(analysable, candidate_policy_key, candidate_policy_version,
   normalization_version)`.
6. **Prospective wiring** — `AnalyseContractWithAiJob`/
   `AnalyseTradePackageWithAiJob` call the simulator immediately after
   the customer-visible `completed` write, using the
   `normalized_input_char_count` now returned by
   `ContractAnalysisService::analyse()`/`TradePackageAnalysisService::analyse()`
   (computed once from text already extracted for the real analysis — no
   second extraction, no additional provider call, no additional
   customer-facing wait). A simulation failure is caught and logged; it
   can never fail the customer's own analysis.
7. **Historical backfill** — `ai:credits:backfill-simulations` (manual/
   on-demand only, same convention as `billing:subscriptions:check-integrity`).
   Never calls the AI provider or re-runs analysis. Re-extracts text
   locally from the original `FileUpload` and only trusts it if its hash
   still matches the analysis's own `document_hash`; a mismatch or a
   missing upload/file always records `unavailable`, never a guess.
   Idempotent, scoped (`--workflow`/`--analysis-id`/`--limit`), and
   supports `--dry-run`.
8. **Internal reporting** — `App\Services\Monitoring\AiTelemetryReportingService`
   / `App\Http\Controllers\Api\AiTelemetryReportingController`
   (`GET /admin/ai-telemetry/{summary,detail,export}`), gated
   `role:Super Admin|Admin` (matches the Pricing Management precedent —
   both roles are platform-wide in this codebase's role model). Every
   response is built exclusively from `AiAnalysisPresenter::internal*()`
   — never the customer-facing methods. The simulation portion of every
   response is explicitly flagged `is_approved_policy: false`. CSV export
   supported. Frontend: `frontend/src/app/admin/ai-usage/page.tsx`.

### 43.2 Test coverage

84 tests across: `UsageMetricsAiCountingTest` (6), `AiTelemetryIntegrityGuardTest`
(7), `AiInputNormalizerTest` (8), `AiCreditSimulationTest` (10, covering:
no ledger/entitlement side effects, idempotency, unavailable-never-invents-
credits, unresolved-never-becomes-zero, simulation-failure-never-fails-
analysis, provider/model-change-never-changes-normalized-size),
`AiCreditSimulationBackfillTest` (8, covering: reconstruction from the
original file, unavailable on missing upload, unavailable on hash
mismatch, never mutates the analysis row, dry-run writes nothing,
idempotent reruns), `AiTelemetryReportingTest` (9, covering: Super
Admin/Admin access, Client denial, unauthenticated denial, missing-cost
exclusion from spend totals, organisation filtering, non-enforcing
labelling, paginated detail shape, CSV export), plus the full pre-existing
`AiTelemetryTest`/`TradePackageAiTelemetryTest`/`AiAnalysisPresenterTest`/
`AiAnalysisApiTelemetryProtectionTest`/`ContractAnalysisDedupTest` suites
re-run clean (no regressions). A pre-existing, unrelated fixture bug was
found and fixed during this work: `AiTelemetryTest`/
`TradePackageAiTelemetryTest`'s faked Anthropic HTTP response was missing
`'type' => 'text'` on its content block, which `ClaudeAiProvider`'s
thinking-block fix (an earlier, separate fix) now requires — both tests
were silently failing before this phase touched them; fixed as part of
verifying no regressions, not left broken.

### 43.3 What remains explicitly out of scope (unchanged from Section 42)

No customer AI Credit balance, monthly grant, deduction, reservation,
settlement, immutable ledger, `FeatureGate` credit enforcement, Stripe
usage billing, purchased credit pack, overage billing, customer-facing
credit dashboard, customer-facing provider cost, customer-facing
provider/model name, speculative AI workflow, AI Chat functionality, or
invented approved commercial rate exists anywhere in this phase. The
`ai_analyses_per_month` → `ai_credits_per_month` entitlement migration
(Section 39) has still not been executed — it remains correctly blocked
on the same unresolved `charging_strategy` decision.

### 43.4 Final Decision Gate (as of this phase)

> **Historical snapshot, superseded.** The single current authoritative
> decision gate is Part Four §53. Kept here unmodified as the reasoning
> record for what was true as of G4C.2C-2 — do not treat the answers below
> as current.

- **Is telemetry collection reliable enough to begin observation?** Yes —
  the prospective simulation path is now live on every real analysis
  completion for both workflows, non-fatally, with no customer-facing
  behaviour change. The historical backfill command additionally lets
  already-completed analyses join the same dataset where their original
  file is still reconstructable.
- **Minimum sample size / observation period before Graham can be asked
  to approve a rate?** Not yet reached — this phase adds the
  *instrumentation*, not the sample. Recommend waiting for a genuine
  size spread within Contract Analysis (small/medium/large, not just the
  single large-document sample Part One already flagged) and at least
  one real Trade Package Analysis execution, across more than one
  organisation, before treating any `candidate_b` band comparison as
  informative.
- **Can any candidate be rejected immediately?** No — both remain
  explicitly provisional; rejecting either now would be exactly the kind
  of premature numeric commitment this document exists to prevent.
- **Is additional telemetry required before Graham can approve rates?**
  Yes, per the point above.
- **Should G4C.3 remain blocked?** **Yes.** G4C.3 remains blocked until
  representative telemetry supports an approved charging policy and
  entitlement allowance. This phase does not change that conclusion — it
  only builds the means to eventually reach it.

---

# Part Three — G4C.2D: Telemetry Maturity, Commercial Calibration Readiness, and Ledger Readiness Review

**Status: Workstreams 1–3 are built (additive telemetry/reporting only — no**
**ledger, balance, deduction, or enforcement). Workstreams 4–5 are review-only**
**deliverables, exactly as scoped — no migration, model, service, job, or**
**controller was created for either.**

This phase does not change §43.4's Final Decision Gate conclusion.
**G4C.3 remains blocked.** This phase improves the instrumentation and
produces two readiness reviews that a future phase can execute against —
it does not itself move the platform closer to enforcement.

## 44. Workstreams 1–3 (Built)

### 44.1 Workstream 1 — Telemetry Maturity

- **`telemetry_schema_version`** (`App\Support\AI\AiTelemetrySchema::CURRENT_VERSION`,
  currently `1`) added to `contract_ai_analyses`/`trade_package_ai_analyses`.
  This versions the STRUCTURE of collected telemetry (which columns exist
  and what they mean), deliberately kept separate from
  `AiInputNormalizer::VERSION` (how input is measured) and
  `candidate_policy_version` (what a candidate would charge) — the three
  answer different questions and must never be conflated into one number.
  Set explicitly at analysis-creation time
  (`AiController`/`TradePackageAiController`), protected by
  `AiTelemetryIntegrityGuard` once a row is terminal (same discipline as
  every other execution-telemetry column), and surfaced only through
  `AiAnalysisPresenter::internal*()` (never customer-facing). **Deliberately
  NOT backfilled to `1` for pre-existing rows** — a row created before this
  migration does not actually have the current telemetry shape (most
  execution-telemetry columns are null for anything predating Phase G4C.1),
  so backfilling the version number would misrepresent genuinely incomplete
  historical data as structurally current. `null` means "predates
  versioning," exactly the same discipline
  `SnapshotIntegrityClassifier`/`legacy_pre_snapshot` already established
  for entitlement snapshots.
- **Telemetry health checks** — `AiTelemetryReportingService::telemetryHealth()`
  (`GET /admin/ai-telemetry/health`, same `role:Super Admin|Admin` gate as
  the rest of this reporting surface). Six read-only checks, each reusing
  this service's own existing filtered queries rather than a new monitoring
  subsystem: legacy records (`telemetry_schema_version` null), incomplete
  telemetry (a terminal row with `provider_called` never recorded),
  missing provider cost, missing normalized input/simulation (a
  completed/confirmed analysis with zero `AiCreditSimulationResult` rows —
  either the prospective simulation call never ran, or the row predates
  simulation and hasn't been backfilled), impossible values
  (`completed_at` before `started_at`), and duplicated simulations (a
  defensive sanity check against the DB's own unique constraint — should
  structurally never fire). **No repair action exists** — unlike
  `billing:subscriptions:check-integrity --repair`, there is no
  "recoverable" telemetry case here: a missing execution-telemetry field
  can never be safely reconstructed after the fact (the one exception,
  missing simulation, is already closable via the pre-existing
  `ai:credits:backfill-simulations` command, which this check's own output
  explicitly points to).
- **Fields deliberately NOT added**: no new column was introduced without a
  named operational purpose behind it (per the phase's own instruction).
  Candidates considered and rejected: a separate "data quality score" field
  (redundant with the health check's own live computation — storing it
  would let it drift stale); a per-row "calibration eligible" boolean
  (already fully derivable from `status IN (completed, confirmed)`, adding
  a column would just be a second, driftable source of truth for the same
  fact).

### 44.2 Workstream 2 — Commercial Calibration Dashboard

Extended `AiTelemetryReportingService::summary()` (not a new service, not a
new endpoint) with a `calibration` block: completed/failed/excluded-from-
calibration counts, cache hit rate, average provider cost, total provider
spend, average execution duration, most-used workflow, organisations using
AI, and a normalized-input-size percentile summary (average/P50/P90/P99).
The percentile sample **deduplicates per analysis, not per simulation row**
— an analysis simulated against N candidate policies must not be counted N
times in the size distribution, since normalized input size doesn't vary
by candidate. Every figure is `null` when its underlying data doesn't
exist (e.g. `average_provider_cost` when every eligible row is missing
cost) — never fabricated as zero.

The existing per-candidate simulation summary (`simulationSummary()`)
gained `average_monthly_hypothetical_credits` (only computed, and only
non-null, when calculated rows span more than one distinct calendar month
— a single month's total divided by "1 month" is not a monthly rate, it's
just the total, and reporting it as a rate would misrepresent a
single-observation total as a trend) and `organizations_represented`.

Frontend: `frontend/src/app/admin/ai-usage/page.tsx` (extended, not
replaced) gained a "Commercial Calibration" card row, a "Telemetry Health"
panel (only rendered when at least one check is non-zero), and two new
columns (Avg Monthly Credits, Orgs) on the existing candidate comparison
table. No new page, no new route, no new frontend data-fetching pattern —
matches this phase's own instruction to extend proven components.

### 44.3 Workstream 3 — Commercial Approval Pack

`ai:credits:calibration-report` (Artisan command, manual/on-demand only —
same convention as `ai:credits:backfill-simulations`/
`billing:subscriptions:check-integrity`). Reads exclusively from
`AiTelemetryReportingService::summary()`/`telemetryHealth()` — no new
query logic, no direct model access. Writes a timestamped markdown file to
the `local` (private) disk under `internal-reports/ai-credits/` (or a
caller-supplied `--output` path), supporting the same `--workflow`/
`--organization-id`/`--date-from`/`--date-to` filters as the reporting
endpoints.

**Structural guarantee, not just a writing convention**: the report is
built from two fixed sections — "Observed Facts" (sample/participation,
workflow distribution, normalized input distribution, provider spend,
hypothetical candidate comparison, telemetry quality, and a readiness
checklist that is itself only a checklist of observations, not a verdict)
and "Commercial Recommendations," which is a **fixed, unconditional
statement that no rate is recommended** — this section's content does not
change based on sample size, organisation count, or checklist completeness,
because approving a commercial rate is categorically a founder/business
decision (§41) this command has no authority to make regardless of how
much data exists. This was verified by test (see §44.4): the report states
the same "no commercial rate recommendation" sentence whether run against
zero data or a populated dataset.

### 44.4 Tests (Workstreams 1–3)

- `tests/Unit/AiTelemetrySchemaTest.php` (4) — version constant shape,
  persistence/casting, legacy rows stay `null` rather than guessed,
  immutability via `AiTelemetryIntegrityGuard`.
- `tests/Feature/AiTelemetryReportingTest.php` (+6, 17 total) — calibration
  block cache-hit-rate/org-count/completed-count correctness, normalized
  input size percentile deduplication across multiple candidates
  simulating the same analysis, health endpoint flags incomplete telemetry
  and missing-simulation correctly, health endpoint requires Super
  Admin/Admin (Client denied).
- `tests/Feature/AiCreditCalibrationReportTest.php` (3) — report contains
  observed facts and the candidate key when data exists, report still
  contains the fixed no-recommendation statement and an explicit
  "no simulation data" line when the dataset is empty, `--output` writes
  to a caller-supplied path.

All new/modified tests pass (34 assertions across the above, run against
this environment's sqlite in-memory test database). The full existing
`AiTelemetryReportingTest`/`AiTelemetryIntegrityGuardTest` suites were
re-run and pass unchanged (no regression from the additive `summary()`
changes). **Environment note**: this environment has a pre-existing,
unrelated root-owned leftover directory
(`storage/framework/testing/disks/local/contracts`) that makes
`Storage::fake('local')` fail for ANY test using it (confirmed against
unrelated pre-existing tests, e.g. `SupportTicketControllerTest`,
`AiCreditSimulationTest`, `AiTelemetryTest`) — not something introduced or
fixable by this phase (no sudo access in this environment to remove a
root-owned directory). `AiCreditCalibrationReportTest` was written against
the real `local` disk with a scoped, self-cleaning test path instead of
the fake, specifically to avoid this pre-existing limitation.

## 45. Workstream 4 — G4C.3 Ledger Readiness Review (Review Only, Nothing Built)

This section extends §25's concept inventory into an integration-point and
lifecycle review. **No migration, model, service, job, or controller was
created for this section** — every item below is a plan for a future phase
to execute against, not work performed now.

### 45.1 Where the ledger should connect to what exists today

- **`AiCreditSimulationResult` is the closest existing analogue, not a
  seed for the ledger schema.** It is deliberately unkeyed to any account
  and carries no debit/credit sign (see its own model docblock) — a real
  ledger's `Reservation`/`Settlement` rows (§25) would need an
  organisation-scoped account reference this table intentionally lacks.
  The two tables should coexist, not merge: simulation stays the
  non-enforcing comparison mechanism even after a ledger exists, so a
  future policy change can still be calibrated against real traffic before
  being adopted for real charging.
- **`normalized_input_char_count`/`AiInputNormalizer::VERSION`** is the
  correct, already-built input for a future `Settlement.amount` under a
  banded policy — no new measurement mechanism should be invented for the
  ledger; it should read the same field simulation already reads.
- **`billing_entitlement_snapshots`/`EntitlementSnapshotService`** is the
  correct analogue for how a ledger's `Allowance grant` (§25) should be
  keyed and made immutable — one frozen row per commercial event, never
  updated after creation, exactly like a snapshot. A future ledger should
  reuse this pattern (a new snapshot-shaped table), not invent a second
  immutability mechanism.
- **`FeatureGate`** is the correct, already-built enforcement seam — a
  future ledger's balance check belongs behind `FeatureGate::allows()`/
  `limit()`, not a new authorization path. `FeatureGate` currently has
  zero real callers for `ai_credits_per_month` (the key doesn't exist yet
  — see §46) and should gain exactly one new resolution case, mirroring
  its existing dormant-key/override/snapshot/fallback chain — not a
  parallel "credit balance" resolution path.
- **`WebhookEventProcessor`/`SubscriptionLifecycleService`** are the
  correct precedent for "exactly one authoritative service performs a
  state transition, everything else calls it by name" — a future
  `AiCreditLedgerService` (name illustrative only, not proposed as final)
  should be the sole writer of reservation/settlement/release rows, the
  same way `SubscriptionLifecycleService` is the sole writer of
  `subscriptions.status`.

### 45.2 Accounting lifecycle

Reservation → Settlement is the correct model **only if** a future
implementation keeps §34's Reserve = Settle invariant intact — this phase
does not revisit that decision, it confirms it remains the right shape:
because charging is resolved from provider-independent normalized input
size determined BEFORE the provider call (not after, unlike tokens), there
is no "estimate now, true up later" step, so a reservation that never
needs adjustment can be created and settled in the same transaction the
analysis's own `completed`/`failed` write already uses. A failed analysis
requires a `Release` (reverses the reservation, never a negative
settlement) — this mirrows the existing pattern of `AiCreditSimulator`
recording `unavailable`/`unresolved` rather than a fabricated `0`.

**Monthly allowance lifecycle**: should be scheduled the same way
`SubscriptionAutomationService`/`ProcessSubscriptionAutomation` already
handles every other scheduled commercial state transition — a new
monthly-grant-issuance case added to that existing command, not a second
scheduler. This also means allowance issuance inherits the existing
`withoutOverlapping()` concurrency protection with no new locking design
required.

### 45.3 Concurrency and idempotency

- **Concurrency**: a reservation/settlement pair for a single analysis has
  no cross-request race condition risk today, because
  `AnalyseContractWithAiJob`/`AnalyseTradePackageWithAiJob` already
  guarantee at most one execution per analysis row (the existing
  `status !== 'pending'` idempotency guard at the top of each job's
  `handle()`). A future ledger write should piggyback on that same
  guarantee rather than introduce a new lock — introducing
  `Cache::lock()` here would be solving a problem that doesn't exist
  given the current job architecture.
- **Idempotency**: should follow `AiCreditSimulationResult`'s own pattern —
  a unique constraint on `(organization_id, analysis_type, analysis_id,
  event_type)` (illustrative shape only) so a retried job (there is none
  today, both jobs use `$tries = 1`, but a future architecture change
  could introduce one) can never double-reserve or double-settle the same
  analysis. This is a materially easier problem than
  `billing_webhook_events`' idempotency (which must handle genuine
  out-of-order redelivery from an external provider) — a ledger event here
  is always internally generated, never redelivered by a third party.

### 45.4 Audit and rollback

- **Audit**: every ledger row should carry the same minimum facts §40
  already specifies (policy version applied, actor for any manual
  adjustment, timestamp) — no new audit philosophy needed, `ActivityLog`
  (already used by `SubscriptionLifecycleService`/
  `PricingManagementService`) is the correct sink for ledger-affecting
  Super Admin actions (a manual adjustment or reversal), not a bespoke
  audit table.
- **Rollback scenarios**: a `Reversal` (§25) is the only correct mechanism
  for correcting an erroneous settlement — a ledger row must never be
  deleted or updated in place once written, mirroring
  `billing_entitlement_snapshots`' own immutability. This has one direct
  consequence for a future implementation: the ledger table's migration
  should omit `updated_at`-driven mutability entirely (an `updating` guard
  like `AiTelemetryIntegrityGuard`, or simply no update path in the owning
  service) rather than relying on convention alone.

### 45.5 Migration dependencies and reporting implications

- A ledger cannot be built before `ai_credits_per_month` exists as a real
  entitlement key (§46) — allowance grants need something to grant against.
- A ledger cannot be built before a founder-approved charging policy exists
  (§41/§43.4) — a `Settlement.amount` needs an approved rule to compute
  from, not a candidate.
- Once built, `AiTelemetryReportingService`/`ai:credits:calibration-report`
  should gain a ledger-vs-simulation reconciliation view (did the real
  settled amount match what the approved policy's own simulation would
  have predicted) — this is a natural, additive extension of the existing
  reporting surface, not a new reporting subsystem.

### 45.6 What remains a genuine open question (not resolved by this review)

- Whether purchased top-up packs (§25's `Purchased grant`) are commercially
  approved at all — this review assumes the concept inventory only, and
  takes no position on whether it will ever be built.
- The exact ledger table name/schema — deliberately still not proposed
  here, consistent with §25's own "no schema proposed" constraint.

## 46. Workstream 5 — Entitlement Migration Readiness Review (Review Only, No Migration Performed)

This section reviews and refines §39's existing recommendation
(`ai_analyses_per_month` → `ai_credits_per_month`); it does not execute
any part of it. **No `Feature` key was added, no `FeatureGate` code was
changed, and no entitlement row was migrated.**

### 46.1 Confirms §39's recommendation, with one addition

§39's five points (new key, not a rename; deprecate not delete; never
reinterpret an existing snapshot; explicit deliberate migration path;
commercial, not engineering-formula, grandfathering) all still hold and
are unchanged by this review. This section adds:

- **Sequencing check, reconfirmed**: the §39.1 `UsageMetricsService` Trade
  Package undercount fix (already shipped in G4C.2C-2, §43.1) satisfies
  §39.1's own "fix before, not during, the AI Credit rollout" instruction
  — this precondition for starting the migration is already met.
  `ai_credits_per_month` itself has still not been added to `Feature::ALL`
  — that remains correctly gated on §41/§43.4's approval blocker below.

### 46.2 Compatibility and rollback strategy

- **`FeatureGate` compatibility**: adding `ai_credits_per_month` requires
  exactly one new resolver case in `UsageMetricsService` (the same shape
  as the three existing `Feature::ALL`-driven usage cards) — `FeatureGate`
  itself needs no structural change, since it already resolves any
  `Feature` key generically through the existing
  snapshot → override → not-entitled chain. This was `Feature`'s exact
  design intent (Entitlement Spec §4) and is confirmed still true.
- **`pricing_plan_entitlements` compatibility**: `PlanEntitlementRepository::initializeDefaultsForPlan()`
  already gives every plan a conservative baseline for any `Feature::ALL`
  key with no configured row — a newly-added `ai_credits_per_month` key
  needs no special-cased seeding beyond what every other key already gets.
- **Rollback strategy**: because the old key is deprecated, not deleted
  (§39, point 2), rollback is structurally simple — stop referencing
  `ai_credits_per_month` in new entitlement snapshots and resume issuing
  `ai_analyses_per_month` snapshots; no data is lost because the old key's
  historical rows were never touched. This is a direct benefit of
  following Entitlement Spec §20's deprecation policy instead of an
  in-place rename, which would have made rollback destructive.

### 46.3 UI wording implications

- Any customer-facing usage card currently reading "X of Y analyses this
  month" must not silently start reading "X of Y credits" the moment
  banding is approved — the wording change is itself a customer-facing
  commercial communication (Commercial Strategy §10: "customer terms must
  never change silently"), not a copy-only frontend change. This is a
  product/communications decision for the migration's execution phase, not
  resolved here.
- `UsageMetricsService`'s existing usage-card shape (allowance, used,
  remaining) needs no structural change to display a credits-denominated
  card instead of an analyses-denominated one — only the new resolver
  case and the label.

### 46.4 Reporting implications

- `AiTelemetryReportingService`/`ai:credits:calibration-report` (this
  phase, §44) already report hypothetical credits under every candidate
  policy — once a real policy is approved and `ai_credits_per_month`
  exists, the same reporting surface is the natural place to add a
  real-vs-allowance reconciliation view, not a new report.
- `SubscriptionIntelligenceService`/`UsageMetricsService` would need their
  existing usage-card generation extended with the new key exactly as
  Phase G3's own architecture already anticipates (registry-driven,
  `Feature::ALL` iteration) — no redesign.

### 46.5 Migration sequencing (the concrete order a future phase should follow)

1. Founder approval of a charging policy (§41) — the hard blocker,
   unchanged by this review.
2. Add `ai_credits_per_month` to `Feature::ALL` + `pricing_plan_entitlements`
   defaults for each plan (a deliberate, reviewed commercial decision, not
   an automatic derivation from the old key's value — §39, point 5).
3. Add the new `UsageMetricsService` resolver case.
4. Communicate the change to affected customers (Commercial Strategy §10)
   before any snapshot under the new key is issued for an existing
   subscription.
5. Deliberately re-issue entitlement snapshots for existing subscriptions
   under the new key (§39, point 4) — never automatic, never bundled
   silently into an unrelated deploy.
6. Mark `ai_analyses_per_month` deprecated (not deleted) once no active
   subscription still needs it as its live key.

### 46.6 What remains a genuine open question (not resolved by this review)

- The actual grandfathering conversion formula (§39, point 5) — explicitly
  a commercial decision, not derivable from telemetry alone even once
  telemetry is representative.
- Whether `ai_credits_per_month` should carry a rollover policy (unused
  credits carrying to the next month) — not addressed anywhere in the
  Entitlement Specification today and out of scope for this review.

## 47. Final Decision Gate (G4C.2D) — Superseded

> **This section is superseded by Part Four §53** (Phase G4C.2E), which
> restates every question below against the current architecture — now
> including the structured, ten-requirement G4C.3 Readiness Gate
> (`App\Support\AI\AiCreditReadinessGate`) that this section's own
> qualitative "readiness checklist" (§44.3) was a precursor to. Retained
> here, compressed, only as the historical record of what this phase
> concluded at the time; do not treat it as current.
>
> **At the time of G4C.2D**: telemetry was judged reliable enough to
> observe (self-describing schema version, explicit health checks added),
> but the underlying sample was unchanged from §43.4 (no confirmed
> representative Contract Analysis size spread, no confirmed real Trade
> Package execution, single-organisation only in this environment).
> G4C.3 remained blocked on the same three conditions §43.4 first
> identified: representative telemetry, a founder-approved charging
> policy, and the entitlement migration reaching its first real step.
> See §53 for the current, structured version of this same assessment.

---

# Part Four — G4C.2E: Production Observation, Commercial Calibration & G4C.3 Readiness

**Status: this is the final phase before the immutable AI Credit Ledger**
**(G4C.3). It operationalizes the observation period and defines the exact**
**decision gate that will unlock G4C.3 — it does not unlock it. No AI**
**Credit balance, ledger table, reservation, settlement, monthly grant,**
**`FeatureGate` enforcement, customer deduction, Stripe billing, customer**
**AI Credit UI, commercial pricing, approved AI Credit value, or**
**entitlement migration was implemented in this phase.**

Execution order followed exactly as instructed: repository review →
telemetry/monitoring confirmation → runbook → calibration process →
Founder Approval Package + Readiness Gate (built, extending the existing
`ai:credits:calibration-report`) → monitoring review → documentation
consolidation → tests.

## 48. Repository Review (G4C.2E)

Confirmed before writing anything, per this phase's own instruction to
reuse existing architecture rather than introduce a second operational
framework:

- **Scheduling**: `routes/console.php` already has a single, consistent
  pattern (`Schedule::command(...)->withoutOverlapping()`, no
  `onOneServer()`) used by every scheduled command in this codebase
  (`suresign:send-deadline-reminders`, `calendar:sync`,
  `billing:webhooks:recover`, `billing:subscriptions:process-automation`).
  `ai:credits:calibration-report` and `ai:credits:backfill-simulations`
  deliberately stay **manual/on-demand**, matching
  `billing:subscriptions:check-integrity`'s own precedent for a report
  that would otherwise produce noisy duplicate output on a fixed schedule
  with no per-run action to take. The Production Observation Runbook
  (§49) documents a recommended *manual* cadence — it does not add either
  command to the scheduler.
- **Monitoring**: `App\Services\Monitoring\ApplicationMonitoringService::aiBlock()`
  **already implements** exactly one of the items this phase's Monitoring
  & Alerting Review (§52) was asked to consider — stuck/stale processing
  detection (`stuck_count`, a 20-minute-stale `processing` row cutoff,
  `oldest_processing_started_at`) — as part of the existing Super Admin
  Application Monitoring dashboard, with its own existing
  degrade-independently/`$warnings`/`$unavailable` convention. This was a
  genuine finding: **no new "stale telemetry" check was added to
  `AiTelemetryReportingService::telemetryHealth()`** because it would have
  duplicated an existing, already-monitored signal. §52 documents this
  explicitly rather than silently reusing it without attribution.
- **Notification/escalation**: `NotificationService`/`NotificationEngineService`
  (in-app) and `EmailNotificationService` (transactional email via Brevo)
  are this codebase's only two real notification mechanisms. Neither is a
  paging/alerting platform (on-call rotation, threshold-triggered pages,
  Slack webhooks) — confirmed there is no such system anywhere in this
  codebase. §52 documents monitored-vs-informational without inventing
  one, per this phase's explicit instruction not to build one.
- **Config-driven external truth**: `config/ai_pricing.php` and
  `config/ai_credit_simulation_policies.php` are this codebase's existing
  pattern for "provisional/effective-dated facts that live outside
  runtime-computed logic." `config/ai_credit_readiness.php` (§51) follows
  the same pattern for the four G4C.3 Readiness Gate requirements that
  are process/business facts, not computable from telemetry.
- **Classifier pattern**: `App\Services\Entitlements\SnapshotIntegrityClassifier`
  is the existing precedent for "a pure classifier taking already-computed
  data and returning a structured verdict, with no query of its own."
  `App\Support\AI\AiCreditReadinessGate` (§51) follows this exact shape.
- **Conclusion**: no new command, service, scheduler entry, or monitoring
  platform was introduced. `ai:credits:calibration-report` (G4C.2D) was
  extended in place; `AiTelemetryReportingService::telemetryHealth()`
  gained one additive check (`simulation_errors` — a genuine gap, see
  §52); one new pure classifier and one new config file were added, both
  following existing repository patterns exactly.

## 49. Production Observation Runbook

Process only — nothing below is enforced by runtime code, per this
phase's own instruction ("do not hardcode these values into runtime
behaviour unless existing scheduling architecture naturally supports it").
The only runtime-supported piece is cadence (§49, "Calibration report
generation frequency"), which is naturally supported by
`ai:credits:calibration-report` already existing as a manual command —
running it on a cadence is an operator action, not a new scheduled job.

- **Observation start**: the date `ai:credits:calibration-report` is first
  run against production and its output archived for comparison. Not yet
  started (§53).
- **Observation end**: when the G4C.3 Readiness Gate (§51) reads Ready for
  every one of its six telemetry-derived requirements. There is no fixed
  calendar end date — see §53 on why no minimum sample size is invented.
- **Minimum observation duration**: not a fixed duration. The gate is
  evidence-based, not calendar-based — a short period with a genuine size
  spread across multiple organisations and both workflows is sufficient;
  a long period without that spread is not.
- **Minimum execution count**: deliberately not specified as a number (see
  §53's explanation, unchanged from §43.4/§47) — the Readiness Gate's
  `representative_telemetry` requirement is qualitative (participation +
  workflow coverage + size spread), not a count threshold.
- **Minimum organisation count**: more than one (`organization_diversity`
  requirement, §51) — a single-organisation sample cannot establish
  cross-organisation variance.
- **Required workflow coverage**: both Contract Analysis and Trade Package
  Analysis must have at least one completed execution
  (`trade_package_coverage`/`representative_telemetry` requirements).
- **Required document-size spread**: at least one normalized-input-size
  sample where P50 ≠ P99 (i.e., not a single near-identical document
  repeated) — see `representative_telemetry`.
- **Trade Package coverage**: tracked as its own explicit requirement
  (`trade_package_coverage`) rather than folded into the general workflow
  coverage check, because this environment has specifically never had a
  real Trade Package execution (Part One's own telemetry-limitations
  finding, unchanged through every phase since).
- **Legacy record handling**: `telemetry_schema_version = null` rows
  (predating G4C.2D) are not excluded from observation, but are also
  never treated as if they matched the current telemetry shape — see
  `AiTelemetrySchema`'s own docblock. The Readiness Gate does not penalise
  their existence (`legacy_records` is informational, not part of
  `telemetry_health`'s findings count) since a legacy row is expected
  history, not a defect.
- **Simulation coverage expectations**: every calibration-eligible
  (completed/confirmed) execution should have a simulation result before
  the observation period ends. `ai:credits:backfill-simulations` should be
  run against any gap where the source document is still reconstructable;
  a gap that cannot be closed (source unavailable) should be documented as
  a permanent limitation of that specific historical record, not silently
  ignored.
- **Telemetry health expectations**: `incomplete_telemetry`,
  `missing_provider_cost`, `impossible_values`, `duplicated_simulations`,
  and `simulation_errors` (see §52) should all read zero before the
  observation period is considered clean. A non-zero `duplicated_simulations`
  is a **Blocked**, not merely Not Ready, finding (§51) — it indicates the
  database's own unique constraint was bypassed and requires investigation
  before any of the sample can be trusted.
- **Calibration report generation frequency**: recommended monthly during
  active observation (run `ai:credits:calibration-report`, archive the
  output alongside the previous month's for trend comparison — the report
  itself has no built-in history/diffing, so this is a manual filing
  discipline, not a feature). More frequently adds noise without adding
  evidence; less frequently risks missing when the gate transitions.
- **Internal review cadence**: the Commercial Calibration Process (§50)
  defines who reviews each generated report and how often that review
  itself happens (recommended: alongside each monthly report generation).
- **Escalation process**: if `AiCreditReadinessGate`'s `telemetry_health`
  or `commercial_confidence` item reads **Blocked** (not just Not Ready),
  escalate immediately to whoever owns `AiCreditSimulator`/
  `AiTelemetryReportingService` rather than waiting for the next scheduled
  review — a Blocked finding indicates a structural bug (e.g. a bypassed
  unique constraint), not merely insufficient data.
- **Success criteria**: every one of the ten Readiness Gate requirements
  reads Ready. This is a conjunction, not a majority — see §51's
  `overall_status` logic (any single Not Ready or Unknown item keeps the
  overall gate Blocked).

## 50. Commercial Calibration Process

The repeatable process for reviewing telemetry and eventually approving
(or rejecting) a candidate charging policy. This section defines the
process; it does not itself approve anything.

- **Who reviews telemetry**: whoever owns the AI Credits initiative
  (unnamed in this document — assign per your own team structure) reviews
  each `ai:credits:calibration-report` output as part of the internal
  review cadence (§49).
- **Who generates reports**: any Super Admin/Admin with CLI access can run
  `ai:credits:calibration-report` — it requires no special commercial
  authority, since it only summarises evidence and recommends nothing
  (§43.3/§44.3's invariant, unchanged).
- **Who approves candidate policies**: exclusively the founder (Graham,
  per §24/§41's existing "Decisions Requiring Business Approval"
  convention) — no other role in this system has, or should have, that
  authority. This is unchanged from every prior phase; this document does
  not introduce a delegated-approval path.
- **Required evidence**: the G4C.3 Readiness Gate (§51) reading Ready for
  all six telemetry-derived requirements, presented via the Founder
  Approval Package section of the same report.
- **Required confidence**: `commercial_confidence` (§51) reading Ready —
  itself a composite of the other five telemetry-derived requirements.
  There is no separate numeric "confidence score" — a composite Ready/Not
  Ready/Blocked/Unknown verdict is the confidence measure, deliberately
  not a fabricated percentage.
- **Representative sample definition**: more than one organisation, both
  real workflows with completed executions, and a genuine normalized-
  input-size spread (P50 ≠ P99) — exactly `representative_telemetry`'s
  definition (§51), reused here rather than redefined.
- **Reasons to delay approval**: any Not Ready or Unknown item in the
  Readiness Gate; any non-zero `simulation_errors`/`duplicated_simulations`/
  `impossible_values` in Telemetry Quality; a sample size under 10
  calibration-eligible executions (flagged as a Commercial Risk in the
  report, §44.3/G4C.2E §51's own report section).
- **Reasons to reject a candidate**: a candidate whose `unresolved`/
  `unavailable` count is disproportionately high relative to `calculated`
  for a representative sample (indicates the policy's own configuration —
  e.g. band thresholds — doesn't cover the real input distribution); a
  candidate's `average_monthly_hypothetical_credits` producing an
  obviously commercially unviable number when compared against
  `average_provider_cost` (a margin sanity check, not a formula this
  document computes automatically). Rejection is always a founder
  decision informed by these signals, never an automatic disqualification
  this system applies on its own.
- **Required documentation**: the `ai:credits:calibration-report` output
  itself (Founder Approval Package + Readiness Gate) is the required
  artifact for any approval decision — archive the exact report version
  reviewed alongside the decision, so a future audit can see precisely
  what evidence a decision was based on.
- **Approval checkpoints**: (1) Readiness Gate reads Ready for all six
  telemetry-derived requirements → (2) founder reviews the archived report
  → (3) founder approves a specific candidate policy, in writing, outside
  this system (§51's "Approval Status" section explicitly states no
  in-system approval workflow exists) → (4) `config/ai_credit_readiness.php`'s
  `founder_approval` entry is manually updated to `ready` with a note
  referencing the approval record → (5) G4C.3 implementation may begin.
- **Rollback process if later telemetry contradicts assumptions**: because
  `founder_approval`/`entitlement_migration_readiness`/
  `operational_readiness` are manually-set config values, reverting an
  approval is as simple as reverting the config entry — no data migration
  is needed since no ledger/entitlement exists yet at this stage. Once
  G4C.3 is implemented and real usage exists under an approved policy, any
  rollback becomes a genuine commercial decision (see the Ledger Readiness
  Review, §45, on `Reversal` as the only correct mechanism for correcting
  an already-settled charge) — this process section covers only the
  pre-ledger rollback case, which is comparatively trivial.

## 51. Founder Approval Package & G4C.3 Readiness Gate (Built)

Extends the existing `ai:credits:calibration-report` (G4C.2D, Workstream
3) rather than introducing a second report or command — the Founder
Approval Package and the Readiness Gate are two views over the same
evidence that command already gathers via `AiTelemetryReportingService`.

- **`App\Support\AI\AiCreditReadinessGate`** — a new pure classifier (no
  query of its own, mirrors `SnapshotIntegrityClassifier`'s shape).
  Evaluates ten requirements, each resolving to Ready/Not Ready/Blocked/
  Unknown: six computed live from `AiTelemetryReportingService::summary()`/
  `telemetryHealth()` (`representative_telemetry`, `telemetry_health`,
  `simulation_coverage`, `trade_package_coverage`, `organization_diversity`,
  `commercial_confidence` — a composite of the other five), and four read
  from `config/ai_credit_readiness.php` (`founder_approval`,
  `entitlement_migration_readiness`, `documentation`,
  `operational_readiness` — process/business facts no telemetry query can
  derive). `overall_status` is Ready only when literally every requirement
  is Ready; any Not Ready or Unknown keeps it Blocked (no partial credit),
  and any Blocked component (today: `duplicated_simulations > 0`)
  propagates Blocked rather than merely Not Ready.
- **`config/ai_credit_readiness.php`** — the single place the four
  non-computable requirements are recorded, following the same
  external-truth-file convention as `config/ai_pricing.php`/
  `config/ai_credit_simulation_policies.php`. Must be updated manually as
  the process in §50 advances; nothing in this codebase updates it
  automatically, deliberately — an automatic "founder_approval: ready"
  would be exactly the kind of invented approval this whole architecture
  exists to prevent.
- **Report restructuring**: `GenerateAiCreditCalibrationReport::render()`
  gained a `Simulation Coverage` subsection (calibration-eligible count,
  missing count, coverage percentage) under Observed Facts; a new
  "Founder Approval Package" section (Commercial Risks, Recommended Next
  Steps, Unknowns, the pre-existing fixed no-recommendation statement,
  Founder Decisions Required, Approval Status); and a new "G4C.3 Readiness
  Gate" section (the ten-requirement table plus overall status/reason).
  **The prior four-item "Calibration Readiness Checklist" (G4C.2D) was
  removed, not kept alongside the new gate** — it is fully subsumed by the
  new ten-requirement gate, and keeping both would have duplicated the
  same underlying facts under two different checklists in the same
  document, which this phase's own consolidation principle (applied here
  as much as to this policy document itself) argues against.
- **Commercial Risks / Recommended Next Steps / Unknowns** are all
  derived from the same underlying gaps already computed for the
  Readiness Gate (no new query, no new invented signal) — presented as
  prose for a human reader rather than a machine-checkable table.
- **Founder Decisions Required** is a fixed list (not derived from
  telemetry) — the actual outstanding business decisions this whole
  document has accumulated across every phase (charging strategy per
  workflow, entitlement migration grandfathering formula, purchased
  top-up pack approval, and now the sufficiency of the observation
  evidence itself).
- **Approval Status** is a fixed statement of fact ("Not submitted. No
  founder approval workflow or sign-off mechanism exists in this
  system...") — never a computed or settable field, since building an
  actual approval-tracking mechanism is explicitly out of scope for this
  phase (and arguably unnecessary — a markdown report reviewed and
  approved outside the system is sufficient at this stage).
- **Tests**: `AiCreditReadinessGateTest` (7, pure unit — no DB) covering
  all-ten-requirements presence, Unknown-on-no-data, Blocked-on-
  duplicated-simulations escalation, fully-representative-dataset reading
  Ready on all six telemetry-derived items while still Blocked overall on
  process state, overall Ready only when literally everything (including
  config-driven items) is Ready, and a missing config entry degrading to
  Unknown rather than crashing. `AiCreditCalibrationReportTest` (+4, 7
  total) covering the new Founder Approval Package sections, the
  Readiness Gate table containing all ten requirements, confirmation the
  superseded four-item checklist text no longer appears, and Simulation
  Coverage percentage rendering. `AiTelemetryReportingTest` (+1, 14 total)
  covering the new `simulation_errors` health field.

## 52. Monitoring & Alerting Review

No paging/alerting platform exists in this codebase (confirmed, §48) and
none was built here, per this phase's explicit instruction. This section
documents what should be monitored versus what is merely informational,
and what already has a home in existing architecture versus what would
require a future, deliberate decision to build real alerting.

**Already covered by existing architecture (no change made):**

- **Stuck/stale processing** — `ApplicationMonitoringService::aiBlock()`'s
  `stuck_count`/`oldest_processing_started_at` (a `processing` row stale
  more than 20 minutes), part of the existing Super Admin Application
  Monitoring dashboard. This is the "stale telemetry" signal — reusing it
  here rather than duplicating it in `AiTelemetryReportingService` was a
  direct result of this phase's repository review (§48).

**Added (one genuine gap, minimal, additive — Phase G4C.2E):**

- **Simulation failures** — `AiTelemetryReportingService::telemetryHealth()`
  gained `simulation_errors` (count of `AiCreditSimulationResult` rows
  with `simulation_status = 'error'` — a candidate policy that threw
  during simulation, caught and logged by `AiCreditSimulator`, never
  propagated to the customer's analysis). This was a real, previously
  uncounted gap: `AiCreditSimulator` already classified this status; no
  reporting surface previously counted it.

**Should be monitored, but only via the existing on-demand surfaces —
no new automation added:**

- **Duplicate simulations** and **impossible timestamps** — both already
  counted by `telemetryHealth()` (`duplicated_simulations`/
  `impossible_values`) and escalated to **Blocked** (not merely Not Ready)
  in the Readiness Gate (§51) precisely because they indicate a bug, not
  sample insufficiency. Recommend: whoever reviews the monthly calibration
  report (§49/§50) treats a non-zero value here as an immediate escalation
  (§49's "Escalation process"), not something to wait on until the next
  scheduled review.
- **Repeated missing provider cost** — already counted
  (`missing_provider_cost`). "Repeated" (a trend across many executions,
  vs. one-off) is a human judgement call when reading the monthly report,
  not a new trend-detection mechanism — building one would be exactly the
  kind of speculative infrastructure this phase was told not to build
  without a demonstrated need.

**Deliberately NOT built — documented as a future recommendation only:**

- **Unexpected execution spikes** and **sudden cost increases** — genuine
  trend/threshold detection (comparing a recent window against a
  historical baseline) that does not fit the "additional inexpensive
  health check" bar the rest of `telemetryHealth()` meets: it would
  require inventing a threshold (how much of a spike is "unexpected"?)
  with no evidence yet to justify one, which conflicts with this whole
  architecture's "no invented numbers" discipline (§32.3/§37).
  **Recommendation for a future phase**: once real production volume
  exists, revisit whether `ApplicationMonitoringService::aiBlock()`'s
  existing daily counters (`started_today`/`completed_today`/
  `failed_today`) are sufficient to eyeball a spike manually, before
  building automatic detection.
- **Alert fatigue** — a process consideration, not a code change: only
  Blocked-tier findings (duplicated simulations, impossible timestamps)
  should ever interrupt someone outside the normal review cadence; every
  other check (missing cost, incomplete telemetry, simulation errors) is
  Not Ready-tier and belongs in the monthly report, not a page. This
  distinction is already encoded in `AiCreditReadinessGate`'s own
  Blocked-vs-Not-Ready escalation logic (§51) — it does not need a
  separate alert-routing rule to exist.
- **A real paging/Slack/on-call platform** — out of scope entirely; no
  such system exists anywhere in this codebase today, and building one
  for AI Credits alone (rather than as a platform-wide decision) would be
  a second operational framework, which this phase was explicitly told to
  avoid.

## 53. Final Decision Gate (G4C.2E) — Current, Authoritative

**This section supersedes §43.4 and §47**, which are retained above only
as historical snapshots (both now carry an explicit superseded notice
pointing here). This is the single current answer to the questions this
document has asked at the end of every prior AI Credits phase.

- **Has the engineering work required before G4C.3 now been completed?**
  Yes. Every telemetry, simulation, presentation, reporting, calibration,
  and readiness-evaluation mechanism this document's own prior phases
  identified as necessary now exists: provider-independent normalization
  (G4C.2C-2), customer/internal telemetry separation (G4C.2C-1),
  execution-telemetry immutability (G4C.2C-2), non-enforcing simulation
  (G4C.2C-2), telemetry schema versioning and health checks (G4C.2D,
  G4C.2E), a calibration dashboard (G4C.2D), and a structured, ten-
  requirement Readiness Gate combined with a Founder Approval Package in
  one artifact (G4C.2E, §51). No further architectural work is identified
  as missing.
- **Is any architectural work still missing?** No. The remaining gaps are
  evidentiary, not architectural — see the Readiness Gate's own Not
  Ready/Unknown items when run against real data, which name specific
  *missing observations* (an organisation, a workflow execution, a size
  spread), never a missing *mechanism*.
- **Is the platform now simply waiting for operational evidence?** Yes.
  Every remaining blocker is either (a) real production usage accruing
  (representative telemetry, Trade Package coverage, organisation
  diversity — all Unknown/Not Ready only for lack of data, not lack of a
  way to measure it) or (b) a decision only a founder can make (charging
  policy approval, entitlement migration approval) or (c) a process step
  that is trivial once (a) and (b) happen (updating four lines in
  `config/ai_credit_readiness.php`).
- **What exact events or approvals will transition the project into
  G4C.3?** In order: (1) the production observation period (§49) runs
  until `AiCreditReadinessGate`'s six telemetry-derived requirements all
  read Ready against real data; (2) the founder reviews the archived
  `ai:credits:calibration-report` output and approves a specific charging
  policy per real workflow, in writing, outside this system (§50's
  approval checkpoints); (3) `config/ai_credit_readiness.php`'s
  `founder_approval` entry is updated to `ready`; (4) the entitlement
  migration (§46) reaches at least its step 2 (the new `Feature` key
  exists) and `entitlement_migration_readiness` is updated to `ready`;
  (5) a formal production observation period is recorded as complete and
  `operational_readiness` is updated to `ready`. Only once all ten
  requirements read Ready does `AiCreditReadinessGate::evaluate()`'s
  `overall_status` become `ready` — that is the literal, mechanical
  signal that G4C.3 implementation may begin.
- **Should any additional engineering be performed before representative
  telemetry exists?** No. Building further mechanism now (a ledger, an
  alerting platform, entitlement migration execution, or a numeric
  charging policy) ahead of the evidence this phase's own gate is designed
  to require would be exactly the premature commitment every phase since
  G4C.2 has argued against. The correct next action is operational, not
  engineering: run the observation period (§49) and periodic calibration
  reviews (§50).
- **Is G4C.3 still blocked?** **Yes**, on all four non-telemetry-derived
  requirements today (`founder_approval`, `entitlement_migration_readiness`,
  and `operational_readiness` all `not_ready`; `documentation` alone reads
  `ready`) plus however many of the six telemetry-derived requirements
  read Not Ready/Unknown against real production data (this environment
  cannot query the real production database to state their current
  values — see §44.4's unrelated environment note). `AiCreditReadinessGate`'s
  `overall_status` will report `blocked` until every one of the ten
  reads `ready` — there is no partial-credit state.

## 54. Holistic Architecture Review (G4C.2A–G4C.2E)

Performed as this phase's own closing instruction: a review of the entire
AI Credits architecture built across G4C.2A–G4C.2E, looking for
unnecessary complexity, duplicated concepts, overlapping responsibilities,
or simplification opportunities — without changing commercial intent.

**Two real duplications were found and fixed during this same phase**
(both introduced within G4C.2E itself, not inherited from earlier phases):

1. `GenerateAiCreditCalibrationReport::render()` independently recomputed
   `workflowsWithData`/`sizeSpreadPresent`/`tradePackageCompleted` using
   the exact same formulas `AiCreditReadinessGate::evaluate()` already
   computes internally. Fixed by having `AiCreditReadinessGate::evaluate()`
   return a `signals` key exposing these three derived facts, and having
   the report command consume them instead of recomputing — one
   definition of each formula now exists, not two.
2. `AiTelemetryReportingService::summary()` and `::telemetryHealth()`
   independently issued the identical fetch-and-concat query for
   `ContractAiAnalysis`/`TradePackageAiAnalysis`. Fixed by extracting a
   shared private `fetchAnalyses()` — both public methods now read from
   one definition. **Not fully eliminated**: `ai:credits:calibration-report`
   still executes this query twice per run (once per public method call),
   since fully de-duplicating that would require changing both methods'
   public signatures (to accept a pre-fetched collection), rippling into
   their existing callers/tests, for a query-count optimisation this
   service's own pre-existing "Scale note" already treats as acceptable at
   today's volumes. Documented as a deliberate, bounded trade-off, not an
   oversight — revisit only if real volume makes it measurably costly.

**Reviewed and confirmed NOT duplicative** (three superficially similar
"version" concepts, each answering a genuinely different question):

- `App\Support\AI\AiTelemetrySchema::CURRENT_VERSION` — versions the
  STRUCTURE of collected telemetry (which columns exist).
- `App\Services\AI\AiInputNormalizer::VERSION` — versions the MEASUREMENT
  METHOD (how normalized character count is computed from raw text).
- `candidate_policy_version` (`config/ai_credit_simulation_policies.php`)
  — versions the COMMERCIAL POLICY (what a candidate would charge).

Collapsing these into one version number would be a real regression: a
future change to any one of the three (e.g. a normalization rule fix)
must never be silently reinterpreted as a change to either of the other
two, which is exactly why `AiCreditSimulationResult` stores all three
independently. No change made.

**Reviewed and confirmed appropriately separated** (not overlapping
responsibilities):

- `AiTelemetryReportingService::telemetryHealth()` (data-quality defect
  detection: is the collected data clean) versus
  `App\Support\AI\AiCreditReadinessGate` (sufficiency assessment: is there
  enough clean data, plus enough process/business approval, to proceed).
  The gate consumes the health check's output as one of several inputs
  rather than re-implementing defect detection itself — a correct layered
  design, not duplicated logic.
- `App\Http\Controllers\Api\AiTelemetryReportingController` (live,
  filterable, paginated JSON dashboard) versus
  `ai:credits:calibration-report` (an archived, point-in-time markdown
  document for management review). Different audiences and different
  artifact lifetimes justify two presentations of the same underlying
  service — the command calls the service rather than re-querying
  independently, so this is not duplicated data access.
- Three AI Credits config files (`ai_pricing.php` — provider cost rates;
  `ai_credit_simulation_policies.php` — candidate charging policies;
  `ai_credit_readiness.php` — process/business state) each govern a
  genuinely distinct domain and follow the same established
  effective-dated-external-truth-file pattern. Not excessive.

**Noted, but explicitly out of scope to act on now**: this document has
grown to over 2,700 lines across four Parts, preserving the full
reasoning history the organisation has asked to keep (§43.4/§47 were
compressed/superseded this phase rather than deleted, per explicit
instruction). A future documentation phase could consider archiving Part
One's oldest, fully-superseded reasoning (Sections 1–24, predating even
the Part Two freeze) into a separate historical appendix file — not done
here, since restructuring risks breaking the many `§N` cross-references
scattered through this document, `CLAUDE.md`, and `project-context.md`,
and no functional or commercial benefit would result from doing it now.

**Conclusion**: with the two fixes above applied, the AI Credits
architecture across G4C.2A–G4C.2E is coherent — every remaining
similarity between components was reviewed and found to reflect a
genuine, load-bearing distinction rather than accidental duplication. The
architecture is ready to transition from engineering into the observation
phase (§49/§50); no further simplification is recommended before G4C.3
begins.

# Part Five — G4C.2F: Controlled Calibration Corpus (Pre-Launch)

Because SureSign has no production customers yet, §49's Production
Observation Runbook has nothing to observe. This Part defines a
**controlled, internal calibration programme** as a deliberately
separate, clearly-labelled evidence source — it does not replace §49,
and it does not change §53's readiness mechanics. No ledger, balance,
enforcement, or entitlement migration is introduced by this Part.

## 55. Controlled Calibration Evidence vs. Production Customer Evidence

Two evidence sources exist, and this document draws a hard line between
them:

**Controlled calibration evidence** — internal or explicitly-authorised
documents deliberately run through the real, unmodified Contract
Analysis / Trade Package Analysis pipelines to evaluate workflow
behaviour, input-size sensitivity, telemetry completeness, provider
economics, and hypothetical credit policies. Produced by a small,
identifiable internal effort, not by customers choosing to use the
product.

**Production customer evidence** — actual customer-originated usage,
capable of demonstrating organisation diversity, real adoption, real
monthly consumption patterns, and operational behaviour under conditions
SureSign does not control. Only this evidence can satisfy
`organization_diversity` and the organisation-count component of
`representative_telemetry` (§51) — no volume of controlled calibration
runs can substitute, because they all originate from the same
handful of internal organisations by construction.

**Hard rules**:

- Controlled calibration evidence must never be presented as, or
  conflated with, production customer usage in any report, dashboard, or
  founder-facing document.
- The current internal test organisation(s) must never be counted as
  multiple organisations through artificial duplication (e.g. creating
  several near-identical internal orgs purely to inflate
  `organizations_using_ai`). `organization_diversity` measures genuine
  customer diversity, not internal test-account count.
- A calibration-eligible execution's *telemetry completeness* and
  *input-size behaviour* are legitimate things controlled evidence can
  establish. Its *commercial representativeness* (would real customers'
  documents actually look like this, at this frequency) cannot be
  established by controlled evidence alone.

See `internal-docs/commercial/ai-credits-calibration-corpus.md` for the
manifest tracking exactly which documents/executions are controlled
calibration evidence, their legal-use classification, and their
telemetry outcome.

## 56. Execution-Context Approach (G4C.2F)

No schema migration is introduced by this phase. Two options were
compared:

**Option A — dedicated internal organisation + external manifest
(adopted for G4C.2F)**: use one clearly-identified internal
organisation (today: "Test Company", org id 6) for calibration runs,
track which specific executions are calibration evidence in the external
manifest (`ai-credits-calibration-corpus.md`), and filter reporting by
`organization_id` when a calibration-only view is needed. No migration,
immediate, sufficient for a small pre-launch corpus. Risk: calibration
status is not intrinsic to the analysis row — a manifest omission or an
ordinary QA run against the same organisation could be miscounted without
someone cross-checking the manifest.

**Option B — explicit `execution_context` field
(`customer`/`calibration`/`qa`) on both analysis tables**: durable,
queryable, and the correct design once production usage exists and
reporting needs to reliably separate calibration noise from customer
data at scale. Requires a migration and changes to both
`ContractAnalysisService`/`TradePackageAnalysisService` (or their
callers) to stamp the value, plus back-population rules for existing
rows.

**Decision**: Option A for G4C.2F — there is no demonstrated immediate
necessity for Option B at today's evidence volume (single digits of
analyses, one internal organisation). Option B should be built as part
of, or immediately before, the production launch itself (i.e. before any
real customer executions need to be reliably distinguished from ongoing
internal QA/calibration activity) — revisit this decision at that point,
not before.

## 57. G4C.2F — Existing Evidence Recovery (Completed)

Before requesting any new calibration documents, the existing repository
was checked for usable evidence already produced by ordinary engineering
work (bug investigation, feature testing) rather than a deliberate
calibration effort.

**Findings**:

- One real, provider-backed Trade Package Analysis execution exists
  (org 6, `TradePackageAiAnalysis#1`, 47,582 normalized chars — **small**
  band, not the "40+ page" / large sample an earlier phase framing
  assumed).
- One real, provider-backed Contract Analysis execution exists
  (org 6, `ContractAiAnalysis#8`, 280,909 normalized chars — **large**
  band), plus one cache-hit reuse of the same document
  (`ContractAiAnalysis#9`) and three earlier failed attempts on the same
  document (`#5`–`#7`, pre-dating the output-ceiling/content-block fixes
  described in this codebase's `CLAUDE.md` AI Workflow Context).
- `ContractAiAnalysis#8`/`#9` had no `AiCreditSimulationResult` rows —
  the sole reason `simulation_coverage` read Not Ready. Recovered via
  `php artisan ai:credits:backfill-simulations --workflow=contract_analysis
  --analysis-id=8` (and `--analysis-id=9`) — scoped to exactly these two
  IDs rather than the whole workflow, so the three failed attempts on the
  same document were not also given simulation rows (they are not
  calibration-eligible, and doing so would have inflated the calibration
  report's per-candidate "calculated" counts with non-billable executions
  of the same document). Both dry-runs confirmed a hash match, one
  affected record each, and no provider call before the real backfill
  was run. `simulation_coverage` now reads Ready.
- **Size spread remains Not Ready** despite two genuinely different
  document sizes (47,582 and 280,909 normalized chars) existing. The
  `representative_telemetry` size-spread check (P50 ≠ P99) samples one
  value *per analysis*, not per underlying document — with three
  analysis rows behind the large document (`#8` real, `#9` cache-hit) and
  one behind the small document, the sample is `[47582, 280909, 280909]`,
  giving P50 = P99 = 280909. This is not treated as a defect to fix here:
  each analysis execution is a legitimate, independent data point in
  general (a real customer could submit the same document twice), and
  redefining "spread" to dedupe by document hash would be a readiness-gate
  threshold change, which this phase is explicitly not authorised to make.
  It is recorded here as a known limitation of today's two-document
  sample, resolved by adding more distinct documents — not by changing
  the formula.
- Neither existing document has a recorded legal-use classification —
  both are marked `pending_review` in the calibration corpus manifest.
  They are valid, real telemetry but not yet certified calibration
  evidence under this phase's own eligibility rules.

**No new AI provider calls were made.** All figures above were recovered
from data already in the database (`ai:credits:backfill-simulations`
never calls the provider, by construction — see that command's own
docblock).

# Part Six — G4C.2G, Parts 1–5: Unique-Document Metric Correction

The §57 finding above ("size spread remains Not Ready despite two
genuinely different document sizes... not treated as a defect to fix
here") was revisited and, on reflection, corrected — the earlier framing
conflated a genuine measurement defect (the same document counted twice)
with a threshold change (redefining what counts as "spread"). Fixing the
former is not the latter: `AiCreditReadinessGate`'s formula
(`sample_size > 1 && P50 !== P99`) was not touched anywhere in this
phase — only the data fed into it.

## 58. Metric Semantic Layers (Built)

`App\Services\Monitoring\AiTelemetryReportingService` now classifies
every figure it produces into exactly one of four layers (documented in
the class's own docblock, reproduced here as the canonical table):

- **Document metric** — one observation per unique source document,
  identified by (analysable model class, `document_hash`) — a provider-
  backed execution and any cache-hit reuse of the same document collapse
  to one observation. Computed once, centrally, by the new
  `uniqueCalibrationDocuments()` — the single place document identity is
  resolved.
- **Execution metric** — one observation per analysis row, cache hits
  included (a cache hit is a real request, just not a new document or a
  new provider-cost sample).
- **Provider metric** — one observation per execution where
  `provider_called === true` only. Totals still safely include cache-hit
  $0 rows (adding zero is inert); averages/rates must never include them,
  or a real per-call cost gets diluted downward by requests that made no
  call at all.
- **Customer metric** — one observation per distinct real `Organization`.
  Never satisfied by an internal test organisation, however many times it
  is used (§55).

| Metric (`AiTelemetryReportingService::summary()` unless noted) | Layer | Cache hits contribute? | Rationale |
|---|---|---|---|
| `total_analyses`, `by_status`, `provider_called.*`, `by_failure_category` | Execution | Yes | Raw request volume/outcome — a cache hit is a real request |
| `total_estimated_cost`, `by_workflow.*.total_estimated_cost` | Provider (total) | Yes, as $0 (inert) | A true total of real spend; a $0 contribution never changes a sum |
| `analyses_missing_cost` | Execution (coarse) | No (cache hits always record $0, never null) | Flags genuine data gaps, not cache-hit outcomes — see `telemetryHealth()['missing_provider_cost']` for the precise, terminal/non-failed-only version |
| `by_workflow.*.count`, `.completed`, `.failed` | Execution | Yes | Per-workflow request volume/outcome |
| `by_workflow.*.unique_documents`, `calibration.unique_documents` | Document | No (collapses to the one document reused) | Document-size/identity statistics must not be weighted by retry/reuse count |
| `simulation` (candidate policy comparison) | Document | Only if canonical (chosen when no provider-backed alternative exists) | A candidate policy prices a *document*, not a *request* — a document analysed once for real and cache-hit-reused twice must contribute one hypothetical-credit observation, not three |
| `calibration.completed_executions`, `.failed_executions`, `.excluded_from_calibration` | Execution | Yes | Execution-level calibration-eligibility accounting |
| `calibration.cache_hit_rate` | Execution | Yes (by definition) | This metric's entire purpose is to measure how often a request is a cache hit |
| `calibration.average_provider_cost` | Provider | **No** (fixed in this phase — previously included cache-hit $0 rows, diluting the average) | An average real-call cost must reflect only calls that were actually made |
| `calibration.total_provider_spend` | Provider (total) | Yes, as $0 (inert) | Same reasoning as `total_estimated_cost` |
| `calibration.average_execution_duration_ms` | Execution | Yes | Legitimate execution-level signal (cache hits are typically much faster — that's real information, not noise to exclude) |
| `calibration.most_used_workflow` | Execution | Yes | Usage-volume signal, appropriately per-request |
| `calibration.organizations_using_ai` | Customer | Yes (a cache-hit request still proves that organisation used the feature) | Organisation diversity is about who made a request, not document identity — never deduplicated by document |
| `calibration.normalized_input_size` (percentiles) | Document | No (fixed in this phase — the root defect) | Document-size distribution must reflect how many genuinely different documents exist |
| `telemetryHealth()`'s six checks (`legacy_records`, `incomplete_telemetry`, `missing_provider_cost`, `impossible_values`, `simulation_errors`, `missing_normalized_input_or_simulation`) and `calibration_eligible_total` | Execution | Yes | Each execution's own telemetry completeness matters independently of whether it shares a document with another row |

## 59. Cache-Hit Semantics (Centralised)

Restated once, centrally, rather than left implicit per-metric:

- A cache hit is a **real execution** — it happened, a real customer (or
  test) request triggered it, and it belongs in every execution-level
  count.
- A cache hit is **never a new document** — `uniqueCalibrationDocuments()`
  collapses it into its canonical document, chosen as the provider-backed
  execution when one exists (it carries real token/cost telemetry),
  otherwise the most recent.
- A cache hit is **never a provider-cost sample** — its real, correctly-
  recorded $0 cost is mathematically inert in a total (adding zero changes
  nothing) but must be excluded from any average/rate computed over
  provider cost, or it silently understates the true per-call cost.
- A cache hit **may legitimately count as customer/adoption usage later**
  (§55's production-evidence definition) — this phase makes no change to
  that future semantics. It is today's *document-size* and *provider-
  cost* layers, specifically, that a cache hit must never distort — not
  whether it "counts as usage" in general.

## 60. What Was Fixed vs. What Was Deliberately Left Alone

**Fixed** (a real measurement defect, not a threshold change):
`AiTelemetryReportingService::normalizedInputSizes()` and
`simulationSummary()` sampled one value per *execution*; now sample one
value per *unique document* via the new `uniqueCalibrationDocuments()`.
`average_provider_cost` no longer includes cache-hit $0 rows. Both fixes
are additive/corrective — no existing method signature outside this
service changed, and `AiCreditReadinessGate` required zero changes.

**Deliberately left alone**: `AiCreditReadinessGate`'s formulas (the size-
spread check, the ten-requirement structure, every status transition
rule) — none were touched. `organizations_using_ai`/`organization_diversity`
remain execution-rollup-based, correctly unaffected by document dedup
(organisation diversity is about who made a request, never about
document identity). §55/§56's controlled-vs-production distinction and
execution-context recommendation (Option A, no migration) are unchanged.

## 61. Recalculated Evidence (Live, This Phase)

Re-running `php artisan ai:credits:calibration-report` against the same
real data as §57, with no new executions:

| Metric | Before this phase | After this phase |
|---|---|---|
| Unique documents | *(not previously reported)* | 2 (1 Contract, 1 Trade Package) |
| Normalized input size sample size | 3 (execution-based) | 2 (document-based) |
| P50 / P90 / P99 | 280,909 / 280,909 / 280,909 | 47,582 / 280,909 / 280,909 |
| `representative_telemetry` | Not Ready — missing org diversity AND size spread | Not Ready — missing org diversity **only** |
| `simulation_coverage`, `trade_package_coverage`, `telemetry_health`, `documentation` | Ready | Ready (unchanged) |
| `organization_diversity`, `commercial_confidence`, `founder_approval`, `entitlement_migration_readiness`, `operational_readiness` | Not Ready | Not Ready (unchanged) |
| Overall | Blocked | Blocked (unchanged) |

**This is not "the corpus is now representative."** Two documents in one
organisation demonstrate that the metric now correctly registers a real
size spread when one exists — they do not demonstrate stable percentiles,
document-category diversity, or anything about real customer behaviour.
`representative_telemetry`'s remaining Not Ready reason is now,
precisely, organisation count — the one thing no amount of controlled
calibration can supply (§55).

## 62. Test Coverage (Built)

Ten new tests in `AiTelemetryReportingTest` confirm: a provider-backed
execution plus a cache-hit reuse of the same hash produce one document
size sample; repeated cache hits never move P50/P90/P99; two genuinely
different hashes produce a real, measurable spread; cache hits never
contribute a zero-cost provider sample; provider-backed cost is counted
once in totals; a failed analysis contributes neither a size nor a cost
sample even if it has a simulation row; a missing `document_hash` is
excluded from unique-document counts, never treated as zero; a Contract
Analysis and a Trade Package Analysis sharing an identical hash never
collapse into one document; organisation diversity remains based on real
distinct organisation IDs (unaffected by document dedup, including when
two different real organisations submit the identical document); and
`representative_telemetry` reads Ready for its size-spread component once
real unique-document spread exists, using the exact pre-existing formula.
All existing tests in this service's four test files (46 total) remain
green; the two test factories that previously omitted `document_hash`
now set one by default (a distinct value per call), matching what a real
terminal analysis always has.

# Part Seven — G4C.3A: Immutable Ledger Foundation (Policy-Agnostic, No Enforcement)

## 63. Scope Reframing (Decision Record)

Every phase through G4C.2G concluded that ledger implementation itself was
blocked by the same requirements that block commercial activation (founder
approval, representative telemetry, entitlement migration readiness,
production observation — see §53). G4C.3A revisits that conclusion and
draws a narrower line:

**The G4C.3 Readiness Gate (`AiCreditReadinessGate`) governs commercial
activation — enabling real customer charging and enforcement — not the
existence of a dormant, policy-agnostic ledger with enforcement disabled
by construction.** A ledger nobody calls and that never blocks anything
has no customer-facing effect and carries none of the risk the gate exists
to prevent. This is a deliberate architectural decision made in this
phase, not a pre-existing reading of §53 — recorded here explicitly so it
is never mistaken for a silent reinterpretation later.

**What this does NOT change**: the gate itself, its ten requirements, its
Ready/Not Ready/Blocked/Unknown logic, `config/ai_credit_readiness.php`,
or the founder-approval process (§50/§51) — none were touched. G4C.3
(commercial activation: real balances customers can spend, enforcement
switched on, entitlement migration, Stripe involvement) remains exactly
as blocked as §53 describes. Only the ledger's *construction*, dormant
and disconnected from every real workflow, is unblocked by this decision.

## 64. G4C.3A Scope Boundary

**Built**: one immutable ledger table (`ai_credit_ledger_entries`), a
derived balance engine, and a reservation/settle/release/grant/
adjust/expire state machine as plain services.

**Explicitly deferred, not built**:
- **Workflow integration** — nothing in `AnalyseContractWithAiJob`/
  `AnalyseTradePackageWithAiJob`/`ContractAnalysisService`/
  `TradePackageAnalysisService` calls this ledger. A future phase adds
  exactly one integration point per workflow.
- **Any UI** — no admin pages, no organisation-facing balance display.
- **A `CreditPolicyResolver`** — the ledger never decides how much
  anything costs; every method takes an already-resolved `$amount`. This
  is what makes it genuinely policy-agnostic — a future integration phase
  decides amounts (via `AiCreditSimulator`'s existing bands, or whatever a
  founder eventually approves), the ledger only records them.
- **Named "shadow mode"** — meaningless with no caller yet. Its intent is
  preserved architecturally via `AiCreditBalanceService::
  hasSufficientBalance()`, a pure query a future caller can check before
  deciding whether to call `reserve()` — enforcement lives entirely in
  that future caller's decision, never in the ledger itself.
- **`refund`** — no partial-refund/cap semantics were decided; the
  transaction type does not exist at all in this phase (not even as a
  placeholder), rather than encode an undecided rule.
- **`CreditAccount`** — an "account" is just `organization_id`; a
  mutable summary row would recreate the exact problem §64's balance
  engine exists to avoid.
- **A mutable `CreditReservation` table** — a reservation's open/settled/
  released state is derived entirely from the ledger itself (do two
  ledger rows exist for this reference, and which types), never tracked
  in a second, separately-mutable place.

## 65. Schema

`ai_credit_ledger_entries` — see the migration's own docblock
(`2026_08_12_000001_create_ai_credit_ledger_entries_table.php`) for full
column-by-column reasoning. Summary: `organization_id` (required),
`workflow` (nullable — null for org-wide entries), `transaction_type`
(one of nine — see `App\Support\AI\AiCreditTransactionType`),
`reference_type`/`reference_id` (nullable polymorphic, required in
practice for reserve/settle/release, enforced by the service not the
DB), `amount` (decimal, always positive — direction comes only from
`transaction_type`), `reason` (required text), `actor_type`/`actor_id`
(`user`|`system`), `idempotency_key` (required, globally unique),
`created_at` only (`const UPDATED_AT = null` on the model; both
`updating` and `deleting` throw — see `App\Models\AiCreditLedgerEntry`).

Two unique constraints: `idempotency_key` (universal — the general
idempotency mechanism, required on every write regardless of whether a
reference exists) and `(reference_type, reference_id, transaction_type)`
(the reservation-lifecycle invariant — at most one reserve, one settle,
one release per reference; a no-op for reference-less rows since MySQL
never matches two NULLs as equal).

## 66. Transaction Types

`grant | reserve | settle | release | adjustment_credit | adjustment_debit
| expiry | migration_credit | migration_debit` — see
`App\Support\AI\AiCreditTransactionType`. No plain `adjustment`/
`migration` — direction must be visible from the type itself, never a
signed amount or a separate mutable flag. `migration_credit`/
`migration_debit` are reserved constants with no service method writing
them yet (no prior ledger exists in this system to migrate from).

## 67. Balance Formulas (Corrected)

```
issued    = grant + migration_credit
consumed  = settle
reserved  = reserve - settle - release
available = issued + adjustment_credit - consumed - reserved
            - adjustment_debit - expiry - migration_debit
```

**The correction this phase made**: an earlier draft of this formula
omitted `consumed` as its own subtracted term, computing `available =
issued - reserved` only. Since `reserved` returns to zero once a
reservation is settled, that earlier formula would have silently
restored the settled amount back to `available` — a real accounting
bug, not a style issue. Verified against the worked example, live
against real MySQL (not just the sqlite test suite):

| Step | Issued | Consumed | Reserved | Available |
|---|---|---|---|---|
| Grant 100 | 100 | 0 | 0 | 100 |
| Reserve 20 | 100 | 0 | 20 | 80 |
| Settle 20 | 100 | 20 | 0 | **80** (not 100) |

`App\Services\AI\AiCreditBalanceService::balanceFor()` computes this from
a single grouped `SUM(amount) GROUP BY transaction_type` query per
organisation — never cached, correctness first (a caching layer may be
added later purely as a performance optimisation in front of this
method, never as a second source of truth).

## 68. Service APIs

`App\Services\AI\AiCreditLedgerService` — the sole writer:

```php
reserve(int $organizationId, string $workflow, string $referenceType, int $referenceId, float $amount, string $reason, string $idempotencyKey, ?int $actorUserId = null): AiCreditLedgerEntry
settle(string $referenceType, int $referenceId, string $reason, string $idempotencyKey, ?int $actorUserId = null): AiCreditLedgerEntry   // amount derived from the reserve entry — never a parameter
release(string $referenceType, int $referenceId, string $reason, string $idempotencyKey, ?int $actorUserId = null): AiCreditLedgerEntry  // amount derived from the reserve entry — never a parameter
grant(int $organizationId, float $amount, string $reason, string $idempotencyKey, int $actorUserId, ?string $workflow = null): AiCreditLedgerEntry
adjustCredit(int $organizationId, float $amount, string $reason, string $idempotencyKey, int $actorUserId): AiCreditLedgerEntry
adjustDebit(int $organizationId, float $amount, string $reason, string $idempotencyKey, int $actorUserId): AiCreditLedgerEntry
expire(int $organizationId, float $amount, string $reason, string $idempotencyKey, ?int $actorUserId = null): AiCreditLedgerEntry
```

`settle()`/`release()` having no `$amount` parameter at all is a
structural enforcement of the AI Credit Policy's "Reserved = Settled"
principle (§32) — not a convention a caller could accidentally violate.

`App\Services\AI\AiCreditBalanceService::balanceFor(int $organizationId): array`
and `::hasSufficientBalance(int $organizationId, float $amount): bool`
(the latter unused by anything in this phase — reserved for a future
enforcement decision point).

## 69. Concurrency and Idempotency Strategy

No `Cache::lock()` anywhere in this phase — there is no real concurrent
caller yet for it to protect, and correctness must not depend on Redis
being reachable (a real, previously-raised concern about this exact class
of infrastructure — see `WebhookEventProcessor`'s equivalent reasoning).
Every write happens inside a `DB::transaction()`:

- `reserve()` has nothing to lock until the row exists — its safety comes
  entirely from the two unique constraints plus graceful recovery from a
  duplicate-key `QueryException` (re-fetch and return the existing entry
  if parameters match; throw `AiCreditLedgerConflictException` if they
  don't).
- `settle()`/`release()` `lockForUpdate()` the existing reserve row —
  not because either ever modifies it, but purely to serialize two
  concurrent settle/release attempts on the same reservation: the second
  transaction blocks until the first commits, by which point the first's
  new row is already visible to it.

Verified against real MySQL directly (not only the sqlite test suite,
per this codebase's own standing rule that sqlite passing doesn't prove
MySQL correctness): grant → reserve → settle → idempotent-retry-reserve →
immutability-guard-on-update, all exercised live inside the running
`suresign_backend`/`suresign_mysql` containers.

## 70. Tests (Built)

22 tests in `tests/Unit/AiCreditLedgerServiceTest.php`: immutability
(update and delete both throw); the full worked example; release
restoring available without consuming; every invalid state transition
(settle/release with no reserve, settle after release, release after
settle); idempotent duplicate reserve/settle; a conflicting duplicate
reserve (different amount) throwing; a reused idempotency key across
two unrelated organisations throwing; required-field validation (reason,
idempotency key, positive amount); balance arithmetic for
adjustment_credit/adjustment_debit/expiry, each confirmed to leave
`reserved` untouched; organisation isolation; `hasSufficientBalance()`;
actor recording for both user- and system-initiated entries. All pass;
combined with the existing 46 AI Credits tests, 68 total, zero
regressions.

## 71. What Remains Blocked

Unchanged from §53: real customer balances, enforcement, entitlement
migration, Stripe involvement, any UI, and workflow integration all
remain future work, gated by exactly the same requirements as before.
This phase's only claim is that the ledger itself now exists, correctly,
dormant, and disconnected from every real system — nothing more.

# Part Eight — G4C.3BC: Workflow Integration (Shadow Mode, No Enforcement)

**This is now the standard integration pattern every future AI workflow
should follow.** Contract Analysis and Trade Package Analysis both call
`App\Services\AI\AiCreditWorkflowLifecycle` at exactly the same three
points; a future provider-backed workflow adds one call site using this
same service, not a new mechanism.

## 72. Repository Findings

Both jobs (`AnalyseContractWithAiJob`/`AnalyseTradePackageWithAiJob`) were
found to be structurally near-identical: `$tries=1`, `$timeout=480`
(deliberately below the queue's `retry_after=600`), an existing
`status !== 'pending'` duplicate-delivery guard, an identical try/catch
shape, an identical mid-flight-cancellation branch, and — a real,
pre-existing gap unrelated to credits — **neither job defined a
`failed(\Throwable $exception): void` method**, so a hard timeout (the
worker killing the child process directly, never reaching the job's own
catch block) left the analysis row stuck at `status='processing'`
forever with no automatic recovery. Both jobs now define `failed()` —
this closes that pre-existing gap and, as a direct consequence, is also
where a hard timeout releases any open shadow reservation.

## 73. Lifecycle Integration Points (Both Workflows, Identical)

| Step | Location |
|---|---|
| Resolve shadow amount | `ContractAnalysisService`/`TradePackageAnalysisService::extractAndRecordDocumentMetrics()` (extracted from the top of the existing `analyse()`, zero behaviour change to existing callers — `analyse()` calls it itself when no `$prepared` array is passed), then `AiCreditWorkflowLifecycle::reserveFor()` |
| Check balance | Inside `reserveFor()`, via `AiCreditBalanceService::hasSufficientBalance()` |
| Reserve | Inside `reserveFor()`, via `AiCreditLedgerService::reserve()` — before the provider is ever called |
| Settle | Job's success block, immediately after `status` is set to `completed` |
| Release | Job's existing catch block (real failure); the mid-flight-cancellation branch; the new `failed()` method (timeout) |

Every exit path is accounted for: file-not-found/extraction failures
happen before `reserveFor()` is reached (nothing to release); the catch
block releases on any provider/validation exception; the cancellation
branch releases; `failed()` releases on a hard timeout. A cache-hit
execution goes through the identical reserve→settle path as a real
provider call — from the credits perspective, a cache hit is still a
real request against a real document, and both use the same shadow
amount (the same document has the same normalized size either way).

## 74. Shadow Credit Amount Resolution

No pricing logic was duplicated or invented. `config/ai_credit_shadow.php`
(`active_candidate`, default `null`) names one ALREADY-CONFIGURED
candidate from `config/ai_credit_simulation_policies.php`.
`AiCreditSimulator::resolveShadowAmount()` reuses that class's own
existing `resolvePeriod()`/`resolveBand()` logic — the exact same
calculation `simulate()` already performs — exposed as a single-value
query instead of a multi-candidate write. **This selection is for
internal shadow accounting only. It is not founder approval and does not
make the named candidate an approved commercial rate** —
`is_approved_policy` remains `false` everywhere it is already asserted,
unchanged by this phase.

## 75. Explicit "Unresolved" Recording (Never a Silent Skip)

Per explicit instruction, an unconfigured or unresolvable shadow policy
never causes the lifecycle to disappear silently. `AiCreditWorkflowLifecycle::reserveFor()`
always returns `credit_reservation_amount`/`shadow_enforcement_result`,
and the job always persists them — `unresolved` (amount `null`) when no
candidate is configured or it cannot resolve for this size/instant,
`sufficient`/`insufficient` otherwise. No ledger call happens in the
`unresolved` case (there is no amount to reserve), but the fact that
nothing happened is itself recorded on the analysis row, distinguishably
from a real insufficient-balance shadow result.

## 76. Shadow Result Storage — the Analysis Row Remains the Single Source of Truth

Per explicit instruction, no new reporting surface was introduced.
`credit_reservation_amount`/`shadow_enforcement_result` are two additive,
nullable columns on the existing `contract_ai_analyses`/
`trade_package_ai_analyses` tables — the same one-row-per-execution
telemetry record every other execution fact already lives on
(`provider_called`, `failure_category`, etc.), now also protected by the
existing `AiTelemetryIntegrityGuard` once the row is terminal. The
G4C.3A ledger itself was NOT extended with these fields — they are
facts about the *execution*, not intrinsic facts of a ledger
*transaction*, and belong with every other execution fact instead of
a second, competing record of the same thing.

## 77. Shared Integration Layer

`App\Services\AI\AiCreditWorkflowLifecycle` — `reserveFor()`/`settleFor()`/`releaseFor()`
— designed only from Contract Analysis's and Trade Package Analysis's
actual common shape. No hooks were added for AI Chat, Document
Extraction, Recommendations, Summaries, or any other workflow that
doesn't functionally exist today. Every method is non-fatal by
contract — a credit-lifecycle failure logs and returns, never turning a
real AI analysis into an error, mirroring `AiCreditSimulator`'s own
existing non-fatal contract.

## 78. Idempotency Identity

`reference_type` = the analysis model's class (`ContractAiAnalysis::class`/
`TradePackageAiAnalysis::class`), `reference_id` = the analysis id — reused
directly from the G4C.3A ledger's own polymorphic reference design, no new
concept. `idempotency_key` is a pure, deterministic function of
`{workflow}:{reserve|settle|release}:{analysis id}` — a genuine retry
always recomputes the identical key. A duplicate queue delivery is
already blocked earlier, at the job's pre-existing `status !== 'pending'`
guard, before the credit lifecycle is even reached — the ledger's own
idempotency (proven in G4C.3A) is defense-in-depth, not the only
protection.

## 79. Schema/Config Changes

One additive migration
(`2026_08_13_000001_add_shadow_credit_fields_to_ai_analyses_tables`) —
`credit_reservation_amount`/`shadow_enforcement_result`, nullable, on
both analysis tables. One new config file (`config/ai_credit_shadow.php`).
**No changes to the G4C.3A ledger schema or accounting model** — the
repository review did not surface a defect requiring one.

## 80. Tests (Built)

11 new tests in `tests/Feature/AiCreditWorkflowIntegrationTest.php`:
Contract success (reserve→settle, `available` correctly stays at
`issued − consumed`, never bouncing back to the pre-reservation value —
the exact G4C.3A accounting fix, now proven end-to-end through a real
job); Contract provider failure (release, full restoration); a
validation failure before extraction (no reservation ever opened);
timeout via the new `failed()` handler (release + stuck-row recovery);
duplicate delivery (no second reservation); a cached result (still
reserves/settles using the shared document's amount); insufficient
balance (execution proceeds normally, recorded as `insufficient`); an
unresolved shadow policy (recorded as `unresolved`, zero ledger rows);
the equivalent Trade Package success/failure pair, confirming identical
behaviour; organisation isolation through two real end-to-end analyses.
All pass; combined with the existing 126 AI Credits/AI Analysis tests,
137 total, zero regressions — including confirming the
`extractAndRecordDocumentMetrics()` extraction refactor is fully
behaviour-preserving for every existing caller.

## 81. What Remains Blocked

Unchanged: real customer balances, enforcement, entitlement migration,
Stripe involvement, and any UI remain future work. This phase's only
claim is that both real AI workflows now correctly exercise the ledger
end-to-end, in shadow mode, with no orphaned reservations across any
exit path found in the repository review — and nothing here blocks or
enables anything commercial.

# Part Nine — G4C.3D-1: Operations Dashboard (Read-Only, Internal Only)

Super Admin/Admin can now **observe** the G4C.3A/G4C.3BC ledger and shadow
accounting through a UI for the first time — nothing here mutates
anything, and nothing here is customer-visible.

## 82. Repository Review Findings

`/admin/ai-usage` (`AiTelemetryReportingController`/`AiTelemetryReportingService`,
G4C.2D) already covers provider spend, cache-hit rate, average provider
cost, and execution time in detail — confirmed a "Provider Usage" page
would have duplicated it near-completely, so none was built; extending
that existing page is the recommended future step if ledger-derived
figures ever need to sit alongside it. The Super Admin sidebar
(`AdminSidebar.tsx`) uses a flat grouped nav with no sub-navigation
mechanism anywhere in the codebase — the "AI Credits" section is a new
top-level nav group with flat items, not a new UI pattern. No charting
library exists in this codebase at all (confirmed — not merely absent
from this area); per explicit instruction, none was introduced here
either. `Modal`, `Badge`, `PaginationBar`, `EmptyState`, and `CountUp`
(a reduced-motion-aware count-up animation) were all reused directly,
unmodified except where noted below.

## 83. Scope Decisions

Per explicit instruction, no new reporting/orchestration service was
introduced — `AiCreditsOperationsController` calls `AiCreditBalanceService`
directly (extended with `platformBalance()` and `consumedByWorkflow()`/
`platformConsumedByWorkflow()` — the exact same formulas, summed with or
without an `organization_id` filter, never a new calculation) plus a
handful of focused Eloquent queries with no independent logic worth
extracting. No charts — metric cards, tables, filters, recent activity,
and empty states only; today's real dataset (single digits of documents,
one organisation) would make a trend chart mostly empty space. No
Policies or Settings page — neither had a defined content spec, and
`AdminSidebar.tsx`'s own existing pattern (`superAdminOnly`/`pageKey`)
gives no basis to invent one.

## 84. Endpoints (Read-Only)

`GET /admin/ai-credits/{summary,organizations,organizations/{id},
transactions,shadow-activity}` — `role:Super Admin|Admin` only, same
gate and same tenancy reasoning as `/admin/ai-telemetry/*` (neither role
is organisation-scoped in this codebase's role model, confirmed again
via `OrganizationController::subscription()`'s equivalent lack of a
per-request ownership check). No Client-role access exists anywhere in
this phase.

## 85. "AI Workflow Usage" (Organisation Detail)

Per explicit instruction: total analyses come from the existing
`ContractAiAnalysis`/`TradePackageAiAnalysis` counts; credits consumed
and average-per-analysis come from `AiCreditBalanceService::
consumedByWorkflow()` — a narrower, workflow-grouped slice of the exact
same `settle` rows `balanceFor()`'s `consumed` figure already sums, not
a new calculation; shadow sufficient/insufficient counts come from the
existing `shadow_enforcement_result` column. No figure here is computed
independently in the frontend — every number is either returned directly
from a backend query or is a simple division (`consumed / settled_count`)
performed once, server-side.

## 86. What Remains Blocked / Deferred (G4C.3D-2)

Deferred exactly as scoped: grants, credit/debit adjustments, expiry
actions, confirmation/audit workflows, the organisation-facing AI
Credits portal, Client-role visibility, customer-facing balance
terminology, Policies, Settings. The organisation-facing portal in
particular still requires an explicit product decision before any
implementation — showing a real customer a real balance number could
imply commercial activation even with enforcement disabled, and that
framing question was deliberately left unresolved by this phase, not
assumed.

## 87. Tests (Built)

8 new tests in `tests/Feature/AiCreditsOperationsTest.php`: `role:Client`
denied on every route; unauthenticated denied; platform-wide balance
correctly sums across organisations; the organisations list returns
correct per-organisation balances; organisation detail's workflow-usage
figures match a real reserve→settle sequence exactly; transaction
filters (organisation + type) work; no mutating route exists for
transactions (404 on PUT/DELETE); shadow-activity filters by workflow
and status. All pass; combined with the existing 137 AI Credits tests,
145 total, zero regressions. Frontend: `tsc --noEmit` and `eslint` both
clean on every new file; a full `next build` was attempted but fails on
an unrelated, pre-existing `/_global-error` prerender issue — confirmed
pre-existing by reproducing the identical failure with every new file
removed via `git stash`, restored immediately after. All five new pages
verified live (HTTP 200) against the running `suresign_frontend`
container; all five new API routes verified live against the running
`suresign_backend`/`suresign_mysql` containers (401 unauthenticated with
the correct `Accept` header, matching every existing protected route's
behaviour exactly — a bare `curl` without that header 500s identically
on pre-existing routes too, e.g. `/admin/ai-telemetry/summary`,
confirmed as a pre-existing environment quirk, not a defect introduced
here).

## 88. Documentation

`CLAUDE.md` (new controller/service-method entries), `project-context.md`,
this Part. No customer-facing User Guide/Help Centre changes — nothing
in this phase is customer-visible.

# Part Ten — G4C.3E: Customer-Facing "Monthly AI Usage" Meter

**This is the first customer-facing surface this entire AI Credits
initiative has produced.** It is deliberately narrow: a single 0-100%
number and a renewal date. No ledger, reservation, settlement, or
adjustment concept is exposed, structurally, not just by convention.

## 89. Product Decision (Provisional, Not Founder-Approved)

Essential=100, Professional=1,000 monthly AI-credit allowance, Enterprise
custom — internal product configuration, explicitly not a per-credit
price and not a marketing claim. Recorded formally as Entitlement
Specification v1 §4a, an amendment to that document's previously-closed
ten-key registry (`ai_credits_per_month` is the eleventh key) — a
deliberate exception, not a silent addition, because analysis count
(`ai_analyses_per_month`) and weighted credit consumption measure
different things and both must remain live simultaneously. The
entitlement migration Section 20 of that document (and this document's
own Part Two, §39) previously anticipated — deprecating
`ai_analyses_per_month` in favour of `ai_credits_per_month` — remains a
separate, still-blocked, future decision. This amendment does not
perform it.

## 90. Architecture

- `App\Services\Intelligence\AiCreditUsageService` — owns BOTH the
  current-period usage query and the presentation shaping. Deliberately
  NOT added to `AiCreditBalanceService`, which stays scoped exclusively
  to all-time balance computation (issued/consumed/reserved/available) —
  the balance engine and this usage-reporting engine are separate
  responsibilities so a future change to either can never silently
  affect the other.
- Usage window: UTC calendar month (`now()->startOfMonth()` to `+1
  month`) — matching `ai_analyses_per_month`'s own already-decided reset
  convention (Entitlement Specification v1 §12), not the subscription's
  Stripe billing anniversary. No new ledger transaction type, no
  scheduled reset job, no change to `AiCreditWorkflowLifecycle` — the
  query window itself moving to next month is what returns usage to 0%.
- Usage definition: `settle` transactions only, summed within the
  window. An open `reserve` never inflates the visible percentage.
  `usage_percent = min(100, round(settled / allowance * 100))` — clamped
  structurally; the unclamped figure is never returned to the client at
  all (not merely hidden in the UI).
- Availability gate (`available: false` hides the meter entirely, never
  a placeholder percentage): the `customer_meter_enabled` flag is off; no
  organisation resolves for the user; `SubscriptionAccessPolicy` resolves
  anything other than `FULL`/`TRIAL`/`GRACE`; or `ai_credits_per_month`
  resolves unlimited, null, or ≤0.
- `config/ai_credit_shadow.php` gained two independent flags:
  `customer_meter_enabled` (gates the meter itself) and
  `enforcement_enabled` (gates nothing yet — reserved for the future
  decision to actually block execution via
  `AiCreditBalanceService::hasSufficientBalance()`). Neither implies the
  other; the customer meter is expected to ship with enforcement still
  off.
- `App\Http\Controllers\Api\AiCreditUsageController`
  (`GET /billing/ai-credit-usage`) — organisation derived only from the
  authenticated user, mirroring `SubscriptionIntelligenceController`
  exactly; no id ever accepted from the caller.
- `App\Services\Intelligence\UsageMetricsService::usageForOrganization()`
  explicitly excludes `AI_CREDITS_PER_MONTH` from its generic USAGE-key
  sweep — that generic path would otherwise surface the key's mere
  existence (a "Not yet measurable" card) on the existing Subscription
  Intelligence Centre, which this phase's own product direction
  forbids.
- Frontend: `AiCreditUsageMeterCard.tsx` — a genuinely new component, not
  a relabelling of the existing `AiUsageMeterCard.tsx` (which stays,
  unmodified, showing analysis count under accurate wording). Rendered
  only inside `SubscriptionIntelligenceSection` (the viewer's own
  organisation) — deliberately not added to `OrganizationSubscriptionSection`
  (the admin org-detail view), which has no safe organisation-scoped
  variant of this endpoint; building one is out of scope here.

## 91. Tests (Built)

8 new in `tests/Feature/AiCreditUsageTest.php`: unavailable when the flag
is off, unavailable with no subscription, unavailable when no allowance
is configured, correct percentage from settled-only usage (an open
reserve excluded), clamping at 100 with the raw over-allowance figure
absent from the response entirely, the response containing only the five
documented fields (no raw allowance/used leak), the two flags proven
independent, and an explicit cross-organisation isolation test (a second
organisation's real usage never appears, including when an
`organization_id` query parameter is deliberately supplied — the
endpoint has no such parameter at all). Two existing tests updated:
`FeatureTest`'s registry-size assertion (ten → eleven, with a new test
confirming the new key's non-dormant-but-not-customer-visible shape) and
nothing else — `UsageMetricsService`'s own tests needed no changes since
the new key is excluded before reaching its generic resolution path.
All pass; combined with the existing 358 AI Credits/entitlement tests
this phase touches, 368 total, zero regressions.

## 92. What Remains Blocked / Deferred

Enforcement (`enforcement_enabled` stays `false`; nothing reads it to
block anything yet), the entitlement migration (Section 20), founder
approval of the underlying rate, and the meter's own release itself
(`customer_meter_enabled` defaults `false` — someone must deliberately
turn it on) all remain exactly as gated as before. This phase's only
claim is that the presentation layer is built and correct, ready for
that release decision whenever it's made.

# Part Eleven — G4C.3G: Founder Approval Recorded, Entitlement Migration Executed

**The first real founder decision in this entire initiative was made on
2026-07-27.** This Part records exactly what was and wasn't decided, and
executes the entitlement migration §46.5 had already sequenced.

## 93. The Decision, Precisely

Approved: the **banded** charging model (`candidate_b`) over flat
(`candidate_a`) — a large document consumes more of the monthly
allowance than a small one, matching real Anthropic cost variance.
Approved: provisional monthly allowances — Essential 100, Professional
1,000, Enterprise custom/negotiated.

**Not approved, and not the same decision**: the specific band
thresholds and per-band credit values inside `candidate_b` (today:
≤50k/150k/300k normalized chars → 2/5/9/15 credits). These remain the
same illustrative placeholders built in G4C.2C-2 for calibration
comparison — approving the *model* (banded, not flat) is a distinct,
smaller decision from approving the *exact numbers*, and only the
former happened here. Also not approved: any customer-facing commercial
rate/price — nothing here authorises billing anyone anything.

## 94. What Changed

- `config/ai_credit_readiness.php` — `founder_approval` and
  `entitlement_migration_readiness` both now read `ready`, with the
  decision recorded in each entry's own `notes`. `operational_readiness`
  (a real production observation period) and the four telemetry-derived
  requirements requiring actual customer diversity are unchanged — this
  decision does not and cannot manufacture that evidence.
- `config/ai_credit_shadow.php` — `active_candidate` (operational —
  drives real shadow reservation/settlement amounts) and the new,
  deliberately separate `approved_candidate` (record-only — drives
  nothing except the reporting flag below) both set to `candidate_b` via
  `.env`. Kept as two distinct config keys, even though they hold the
  same value today, so "what we operationally use" is never silently
  conflated with "what has actually been approved."
- `AiTelemetryReportingService::simulationSummary()`'s `is_approved_policy`
  — previously hardcoded `false` for every candidate, unconditionally, in
  every prior phase (correctly, since no approval had ever happened). Now
  reads `config('ai_credit_shadow.approved_candidate')` — the ONE place
  this can become `true`, and only for the one matching candidate.
  `ai:credits:calibration-report` and `/admin/ai-usage` both gained an
  "Approved (internal)" column reflecting it, each explicitly captioned
  that this means internal accounting approval, never a customer
  commercial rate.
- **Entitlement migration executed** (Entitlement Specification v1 §46.5,
  steps already satisfied before this phase: step 1 founder approval,
  step 2 `ai_credits_per_month` added to the registry — both done in
  G4C.3E):
  - `Feature::AI_ANALYSES_PER_MONTH` marked `deprecated_in_favor_of:
    Feature::AI_CREDITS_PER_MONTH` in the registry (`Feature::isDeprecated()`/
    `deprecatedInFavorOf()`, new) — per Entitlement Spec §20's explicit
    policy: deprecated, never deleted. Existing rows/snapshots under the
    old key remain fully valid and resolvable forever; nothing was
    migrated or rewritten.
  - `UsageMetricsService`'s generic USAGE-key sweep was deliberately
    **not** extended with a resolver case for `ai_credits_per_month`
    (§46.5 step 3's literal suggestion) — reviewed and rejected, because
    two better-suited, already-built surfaces exist: the customer's own
    `AiCreditUsageService` meter, and Super Admin's AI Credits Operations
    Dashboard (workflow-level breakdown, not just an allowance-vs-used
    card). Adding a third, generic path would have been exactly the
    "parallel reporting system" this codebase's own conventions warn
    against.
  - §46.5 step 4 (customer communication before issuing a new-key
    snapshot to an *existing* subscription) and step 5 (deliberately
    re-issuing snapshots) are correctly not performed as a broad rollout
    — this environment has no production customers on the old key to
    communicate with or re-snapshot. `FeatureGate` already resolves
    `ai_credits_per_month` correctly for the one real test subscription
    checked (a `legacy_pre_snapshot`-classified row, falling back to live
    `PlanEntitlementRepository` resolution) — verified live, not assumed.

## 95. What This Does NOT Do

Does not enable enforcement (`enforcement_enabled` untouched). Does not
add a grants/adjustments admin UI. Does not touch Stripe or any billing
integration. Does not manufacture organisation diversity or production
observation evidence — `representative_telemetry`, `organization_diversity`,
`commercial_confidence`, and `operational_readiness` remain `not_ready`
in the live readiness gate, exactly as before, because no amount of
founder approval changes how many real organisations have used the
product. **G4C.3 (full commercial activation) remains blocked** — this
phase closed two of the six requirements that were genuinely closeable
by decision alone, not all ten.

## 96. Tests (Built)

4 new: `Feature::isDeprecated()`/`deprecatedInFavorOf()` behavior (the
deprecated key, and confirming every other key is correctly NOT
deprecated by default); `is_approved_policy` reflecting the configured
candidate exactly (true for the approved one, false for every other,
including when unconfigured). One existing test rewritten (it had
asserted `is_approved_policy` is *always* false — no longer true by
design; replaced with two tests covering both the approved and
unconfigured cases) and one existing test hardened to explicitly set
`customer_meter_enabled = false` rather than relying on the environment's
own default, since this environment's `.env` now legitimately sets it
`true` for real local testing. All pass; 318 total across touched
suites, zero regressions. Verified live against the real database: the
calibration report correctly shows `candidate_b` as `Approved (internal):
Yes` and `candidate_a` as `No`; the readiness gate correctly reads
`founder_approval`/`entitlement_migration_readiness` as `Ready` while
the four evidence-dependent requirements remain `Not Ready`.

# Part Twelve — G4C.3H: Grants/Adjustments Admin UI

Following the approved phase sequencing (Migration → **Grants UI** →
Enforcement → Billing tie-in), this phase built the first write-capable
AI Credits surface — the operator-facing counterpart to `AiCreditsOperationsController`,
which stayed strictly read-only per its own docblock's invariant.

## 97. What Was Built

`App\Http\Controllers\Api\AiCreditsGrantController` — a SEPARATE
controller, never merged into the read-only one — exposes
`POST /admin/ai-credits/organizations/{organization}/{grant,adjust-credit,adjust-debit,expire}`,
gated `role:Super Admin` ONLY (not Admin — a narrower gate than the
read-only dashboard's `Super Admin|Admin`) plus `throttle:30,1`, mirroring
`OrganizationSubscriptionAssignmentController`'s G4B.2 precedent exactly.
Every mutation delegates to the existing `AiCreditLedgerService`
(`grant()`/`adjustCredit()`/`adjustDebit()`/`expire()`) — no new
ledger-writing code path was added anywhere. `ManageAiCreditsRequest`
requires `amount` (numeric, >0), `reason` (string, ≥10 chars), and
`confirmed` (`accepted`) — the same shape for all four actions, since
which ledger transaction type is written is implied by which endpoint
was called, never a request field. Each call generates a fresh
UUID-based idempotency key (a genuine new admin-initiated event, never a
retry of a prior one) and writes a scoped `ActivityLog` entry.

Frontend: the Organisation detail page
(`app/admin/ai-credits/organizations/[id]/page.tsx`) gained Grant/Adjust
Credit/Adjust Debit/Expire buttons, visible to Super Admin only, each
opening a confirmation dialog requiring a reason and an explicit
checkbox — mirroring `OrganizationSubscriptionSection.tsx`'s
`AssignSubscriptionDialog` pattern.

## 98. Tests and Verification

10 new backend tests (`AiCreditsGrantTest`): Super Admin can perform
every action; Admin and Client are both denied on every endpoint;
validation (reason length, `confirmed`, positive amount); adjust-debit/
expire correctly decrease `available` with no prior reservation required;
adjust-credit increases it; an `ActivityLog` entry is recorded and scoped
to the correct organisation; repeated calls each get a distinct
idempotency key. All pass. Live-verified end-to-end against the real dev
database (grant → real ledger entry → updated balance → reflected on the
detail endpoint), then the throwaway organisation and ledger entry were
deleted.

## 99. What This Does NOT Do

Does not enable enforcement. Does not touch Stripe or billing. Does not
add a Client-facing or organisation-facing grants view — Super Admin
only, matching the read-only dashboard's own operator-only scope.

# Part Thirteen — G4C.3I: Real Enforcement Gate (Built, Deliberately OFF)

Following the approved phase sequencing (Migration → Grants UI →
**Enforcement** → Billing tie-in), this phase built the real,
code-complete enforcement path — but did **not** turn it on. Before
implementing, the live G4C.3 Readiness Gate was checked and read
`BLOCKED` (`representative_telemetry`, `organization_diversity`,
`commercial_confidence`, and `operational_readiness` all `NOT_READY` —
only one organisation has real telemetry, and no production observation
period has run). Given that, and given the real customer impact of
wrongly blocking AI analysis, the user was asked explicitly what
"Enforcement" should mean right now rather than assuming; the answer was
to build it fully but leave `enforcement_enabled` off in production,
switched on later once the gate clears.

## 100. What Was Built

`App\Services\AI\AiCreditWorkflowLifecycle::shouldBlock(array $reservation): bool`
— the actual enforcement decision. Returns true only when
`config('ai_credit_shadow.enforcement_enabled')` is true AND the
reservation's `shadow_enforcement_result` is the *resolved* `insufficient`
— never `unresolved` (no invented number is ever enforced against), and
never when the flag is off (today's shadow-only behaviour is completely
unchanged; confirmed live — the flag still reads `false` in this
environment, since `.env` never set `AI_CREDIT_ENFORCEMENT_ENABLED`).

Both `AnalyseContractWithAiJob`/`AnalyseTradePackageWithAiJob` call
`shouldBlock()` immediately after `reserveFor()`, before the provider is
ever called. If true, each throws the new
`App\Support\AI\AiCreditEnforcementException` (extends `RuntimeException`)
with a customer-safe message — "This organisation's monthly AI usage
allowance has been used. AI analysis will resume once your allowance
resets, or contact support to increase it." (no "credit"/"ledger"
wording, consistent with the G4C.3F customer-terminology discipline).
This exception deliberately flows through each job's *existing* catch
block unchanged — no new error-handling, release, or notification code
was written. The only other change was teaching
`App\Services\AI\AiFailureClassifier` to recognise this exception type
first (an `instanceof` check, not a message-fragment match, since the
exception type itself is the unambiguous signal) and classify it as the
new `App\Support\AI\AiFailureCategory::INSUFFICIENT_CREDITS`.

## 101. Tests and Verification

16 new tests: a pure decision-table unit test for `shouldBlock()` (5
cases: enabled+insufficient blocks, disabled never blocks, sufficient
never blocks even when enabled, unresolved never blocks even when
enabled, missing config key defaults to not blocking); a classifier unit
test for the new category; and 4 new integration tests in the existing
`AiCreditWorkflowIntegrationTest` (contract and trade package: blocked
before the provider is ever reached — asserted via `Http::assertNothingSent()`
— with the reservation released and `failure_category = insufficient_credits`;
a sufficient balance is never blocked even with enforcement on; an
unresolved shadow policy is never blocked even with enforcement on). All
pass, alongside the full existing suite with zero regressions (5
pre-existing, unrelated failures confirmed unchanged: Redis presence
flakiness, a missing `docker-compose.prod.yml` mount inside the test
container, and two file-upload storage-path tests — none touch AI
Credits code).

Live-verified end-to-end against the real dev database: a throwaway
organisation with zero balance, `active_candidate` and
`enforcement_enabled` toggled on directly via `config()`, ran through the
real `AnalyseContractWithAiJob::handle()` — the analysis correctly ended
`failed` with `failure_category = insufficient_credits` and the exact
customer-safe message, no reservation was left outstanding, and the
provider was never called. The throwaway organisation, project, contract,
upload, and ledger entries were all deleted immediately after.

## 102. What This Does NOT Do

Does not set `AI_CREDIT_ENFORCEMENT_ENABLED=true` anywhere — enforcement
remains off in every environment, including this dev environment's own
`.env`. Does not manufacture readiness-gate evidence. Does not touch
Stripe or billing. **Flipping the flag on in production is the
remaining, separate, deliberate decision** — gated on the Readiness Gate
reading `Ready`, not on any further code change.

> **Addendum (later phase):** `config('ai_credit_shadow.enforcement_enabled')`
> / `AI_CREDIT_ENFORCEMENT_ENABLED` above are now historical twice over.
> They first moved to a runtime, database-backed boolean
> (`suresign_settings.ai_credit_enforcement_enabled`), then — before that
> boolean ever shipped — were superseded again by a single explicit
> three-state operating mode, `suresign_settings.ai_credit_operating_mode`
> (`App\Support\AI\AiCreditOperatingMode` — `disabled`/`shadow`/`enforced`,
> defaults `shadow`), controlled via `GET`/`PUT /admin/ai-credits/
> operating-mode`. `DISABLED` is a new state this section didn't
> anticipate: it stops the entire AI Credit accounting lifecycle
> (reservation, simulation, settlement, release) from running at all,
> not merely from blocking. The decision this section describes — that
> moving to `ENFORCED` in production is gated on the Readiness Gate
> reaching `Ready` — is unchanged; only the mechanism (now a Super Admin
> mode change instead of a deploy) and the vocabulary (a mode, not a
> boolean) are new. See CLAUDE.md's AI Workflow Context section for the
> current wiring.
