# SureSign AI Credits — Architecture & Usage Investigation (Phase G4C.0)

Investigation-only phase. No application code, migrations, or database
records were changed. No ledger, reservation, or deduction behaviour was
implemented. This document is the deliverable for that investigation —
see `internal-docs/super-admin/subscription-billing.md`'s G4C.0 section
for the short cross-reference and explicit confirmations.

## 1. Executive conclusion

SureSign has exactly **two** real AI-provider-backed workflows today —
Contract AI Analysis and Trade Package (subcontract) AI Analysis — both
calling Anthropic Claude through the same `AiProviderInterface`
abstraction. A third apparent AI surface (an AI chat/conversation feature
with its own `ai_conversations`/`ai_messages` tables and registered API
routes) is **not functional**: the routes point at controller methods
that do not exist on `AiController`. The Prompt Library is confirmed to
make zero provider calls. No Commercial, Delivery Document, or Risk
module has any AI integration.

The local development database contains **zero** AI analysis rows of any
kind (confirmed by direct query). Every conclusion below about token
counts, costs, durations, or failure rates is therefore **architectural**,
derived from reading the code, not statistical. G4C.1 should not set
numeric credit bands from this investigation — it should instrument
production first.

## 2. Investigation scope and evidence sources

- Full read of `app/Services/AI/*`, `app/Http/Controllers/Api/AiController.php`
  and `TradePackageAiController.php`, `app/Jobs/AnalyseContractWithAiJob.php`
  and `AnalyseTradePackageWithAiJob.php`, `app/Models/ContractAiAnalysis.php`,
  `TradePackageAiAnalysis.php`, `AiConversation.php`, `AiMessage.php`,
  `AiOutput.php`, and their migrations.
- Repo-wide grep for AI/Claude/Anthropic references across
  `app/Services/Commercial/*`, `DeliveryDocumentController`, risk-related
  services, and `TradePackages/*` sync services — to confirm which
  candidate modules do or do not call a provider.
- `routes/api.php`'s `ai/*` and `contracts/*/ai-*` route group.
- Read-only queries against the local development MySQL database
  (`suresign` at `localhost:3307`) — `SELECT`/`COUNT`/`GROUP BY` only, no
  writes, no migrations run.
- `config/ai.php`, `App\Support\Entitlements\Feature::AI_ANALYSES_PER_MONTH`,
  and `UsageMetricsService::aiAnalysesThisMonth()` (existing G3 usage
  counting).

## 3. Authoritative AI workflow inventory

### 3.1 Contract AI Analysis — **live provider-backed workflow**

- **Purpose**: extracts structured contract data (parties, payment terms,
  key dates, programme milestones, retention rules) from an uploaded
  contract document.
- **Entry point**: `POST /contracts/{contract}/ai-analysis` →
  `AiController::startAnalysis()`.
- **Service**: `App\Services\AI\ContractAnalysisService`.
- **Provider call boundary**: `ContractAnalysisService::analyse()` →
  `$this->makeProvider()->complete(...)` → `ClaudeAiProvider::complete()`
  → Anthropic Messages API (`https://api.anthropic.com/v1`, per
  `config/ai.php`).
- **Job**: `App\Jobs\AnalyseContractWithAiJob` (queue `default`, `$tries = 1`,
  `$timeout = 480`).
- **Model/table**: `App\Models\ContractAiAnalysis` / `contract_ai_analyses`.
- **Stored outputs**: `raw_response_text`, `raw_response_json`,
  `confirmed_data_json`, `summary`, `tokens_input`, `tokens_output`,
  `estimated_cost`, `document_hash`, `stop_reason`, `error_message`.
- **UX**: start → poll/notify on completion → operator reviews → confirm
  (`POST .../confirm`) writes back into contract/programme data via
  `ContractIntelligenceSyncService` → optional PDF "Contract Intelligence
  Brief" generation (no further AI call).
- **Synchronous/async**: asynchronous (queued job); the HTTP request only
  creates the `pending` row and dispatches the job.
- **Repeatable**: yes, `force_new` bypasses the "reuse existing completed
  analysis" prompt; one active (`pending`/`processing`) analysis per
  contract at a time (enforced by the controller).
- **Identical-input reuse**: yes — see §9.

### 3.2 Trade Package (Subcontract) AI Analysis — **live provider-backed workflow**

- **Purpose**: extracts subcontract onboarding data (parties, package
  scope, key terms) matched against `TradePackageCatalogueService`'s
  package list.
- **Entry point**: `POST /trade-packages/{tradePackage}/ai-analysis` →
  `TradePackageAiController::startAnalysis()`.
- **Service**: `App\Services\AI\TradePackageAnalysisService`, which
  composes (not extends) `ContractAnalysisService` for text extraction,
  provider construction, the enabled check, and cost estimation — a
  deliberate reuse rather than duplication for the generic pieces.
- **Provider call boundary**: same as above, via the same
  `ClaudeAiProvider::complete()`.
- **Job**: `App\Jobs\AnalyseTradePackageWithAiJob` — identical
  `$tries = 1` / `$timeout = 480` configuration.
- **Model/table**: `App\Models\TradePackageAiAnalysis` /
  `trade_package_ai_analyses` — structurally near-identical to
  `contract_ai_analyses` (same column set: tokens, cost, hash, status,
  stop_reason).
- **UX/repeatability/reuse**: same shape as Contract AI Analysis.

### 3.3 Prompt Library — **manual prompt/template workflow with no provider call**

- `App\Models\PromptTemplate`/`PromptCategory`, `PromptRenderService`
  (variable substitution only), `PromptFavorite`, `PromptCopyLog`.
- Confirmed: no reference to `AiProviderInterface`/`ClaudeAiProvider`
  anywhere in the prompt-library code path. The user copies a rendered
  prompt to their clipboard and pastes it into an external AI tool
  themselves.
- **Classification**: zero API usage, zero direct AI Credit consumption.
  Per the approved instruction, this only becomes a future billable
  workflow if SureSign itself later executes these prompts — not today.

### 3.4 AI Chat / Conversations — **AI-ready schema/placeholder only, non-functional**

- `App\Models\AiConversation` (real fillable/relations),
  `App\Models\AiMessage` and `App\Models\AiOutput` (both **empty stub
  models** — no fillable, no relations beyond Eloquent defaults).
- `routes/api.php` registers `POST/GET /ai/conversations`,
  `POST/GET /ai/conversations/{conversation}/messages`,
  `POST /ai/summarize`, `POST /ai/draft-document` — all pointed at
  `AiController::class`.
- **Confirmed**: `AiController` (fully read, §"AiController" above) has
  **no** `startConversation`, `listConversations`, `sendMessage`,
  `getMessages`, `summarize`, or `draftDocument` methods. Calling any of
  these routes today would fail with a "method does not exist" runtime
  error.
- The only other reference to `AiConversation`/`AiMessage` in the
  codebase is `AdminController`'s Super Admin dashboard, which counts
  `AiConversation::whereMonth(...)` for a "monthly AI usage" metric — a
  count of rows that **no working code path ever creates**, so this
  metric is currently always zero in practice.
- **Classification**: AI-ready schema and route placeholder only. This
  matches CLAUDE.md's own existing caution about this exact feature (the
  "AI Assistant chat page" documented as working before being caught and
  corrected). G4C.0 independently reconfirms that finding at the code
  level.

### 3.5 Commercial AI, Delivery Documents AI, Risk-generation AI — **not found in the current codebase**

Repo-wide search for provider calls (`ClaudeAiProvider`,
`AiProviderInterface`, `->complete(`) across `app/Services/Commercial/*`,
`DeliveryDocumentController`, and every risk-related service returned
zero matches. These are ordinary non-AI modules. Nothing in their code
receives, generates, or references AI output. Any documentation or
naming that suggests otherwise is not reflected in the implementation.

### 3.6 Contract/Trade Package Intelligence Sync Services — **AI-assisted downstream use of an existing analysis**

- `App\Services\ContractIntelligenceSyncService`,
  `App\Services\TradePackages\TradePackageIntelligenceSyncService`.
- Confirmed: **zero** calls to `AiProviderInterface`/`ClaudeAiProvider`/
  `->complete(` in either file. They only read the already-confirmed
  `confirmed_data_json` from an existing `ContractAiAnalysis`/
  `TradePackageAiAnalysis` row and write it into programme milestones,
  contract fields, calendar events, etc.
- **Classification**: downstream consumers of AI output, not AI
  workflows themselves. They should never independently consume AI
  Credits — the credit charge belongs entirely to the originating
  analysis.

## 4. Candidate modules confirmed not to be provider-backed

| Candidate | Classification | Evidence |
|---|---|---|
| Prompt Library | Manual prompt/template, zero API calls | No `AiProviderInterface` reference anywhere in `PromptRenderService`/`PromptController` |
| AI chat/conversations | AI-ready schema/placeholder only | Routes reference `AiController` methods that don't exist |
| Commercial module | Ordinary non-AI module | Zero AI/Claude references in `Commercial/*` |
| Delivery Documents | Ordinary non-AI module | Zero AI/Claude references in `DeliveryDocumentController` |
| Risk (Contract Risks) | Ordinary non-AI module | Zero AI/Claude references found |
| Contract/Trade Package Intelligence Sync | AI-assisted downstream use | Reads existing `confirmed_data_json`, never calls the provider |

## 5. Provider and execution architecture

- **Abstraction**: `App\Services\AI\AiProviderInterface::complete(string $systemPrompt, string $userPrompt): array`
  — returns `['text', 'tokens_input', 'tokens_output', 'stop_reason']`.
  One concrete implementation exists: `ClaudeAiProvider`.
- **Token source of truth**: `ClaudeAiProvider::complete()` reads
  `tokens_input`/`tokens_output` directly from Anthropic's own API
  response (`$body['usage']['input_tokens']` /
  `$body['usage']['output_tokens']`) — genuinely provider-reported, not
  locally estimated. Only `estimated_cost` is a local calculation.
- **Configuration**: `config/ai.php` — master enable flag
  (`AI_FEATURE_ENABLED`, overridden by `suresign_settings.ai_enabled` at
  runtime per `ContractAnalysisService::isEnabled()`), model name
  (default `claude-sonnet-5`), HTTP timeout (420s), max output tokens
  (16,000), and max input characters (150,000, ≈37k tokens) sent to the
  model.
- **Queue/timeout layering** (identical for both jobs): HTTP timeout
  (420s) < job timeout (480s) < queue `retry_after` (600s) — deliberately
  ordered so the job's own timeout fires before the queue considers the
  job abandoned and redelivers it.
- **Retry policy**: `$tries = 1` on both jobs — **no automatic Laravel
  queue retry**. Combined with an idempotency guard inside `handle()`
  (`if ($analysis->status !== 'pending') return;`), a duplicate job
  delivery (e.g. a worker restart before `retry_after` elapses) is a
  documented, deliberate no-op rather than a second provider call.

## 6. Existing tables and telemetry semantics

### `contract_ai_analyses` / `trade_package_ai_analyses` (structurally identical)

| Column | Meaning | Written where | Provider-reported or local? |
|---|---|---|---|
| `status` | `pending→processing→completed/confirmed/failed/cancelled` | Controller (create/cancel/confirm), Job (processing/completed/failed) | Local state machine |
| `provider` | Always `'anthropic'` today (no other implementation exists) | Controller, from `suresign_settings.ai_provider` fallback | Local |
| `model` | e.g. `claude-sonnet-5` | Controller, from `suresign_settings.ai_model` fallback | Local (config), reflects what was requested |
| `document_hash` | SHA-256 of extracted text | `ContractAnalysisService::analyse()`/`TradePackageAnalysisService::analyse()`, written **before** the cache lookup | Local, deterministic |
| `tokens_input`/`tokens_output` | Provider-reported token counts | Written by the analysis service (pre-parse) **and again** by the job on success | **Provider-reported**, passed through unchanged |
| `estimated_cost` | `(tokens_input × $3 + tokens_output × $15) / 1,000,000` | Computed **twice** — once in the service (pre-parse persist) and again in the job's success handler with an inline duplicate of the same formula | Local, derived from provider tokens |
| `stop_reason` | Anthropic's own stop reason (e.g. `max_tokens`) | Provider response, passed through | Provider-reported |
| `raw_response_text` / `raw_response_json` | Full model output, parsed JSON | Service (pre-parse) / Job (post-parse) | Provider-reported (text), locally parsed (json) |
| `error_message` | Sanitised failure message shown to the customer | Job's catch block — only `RuntimeException` messages are trusted verbatim; anything else is genericised | Local |
| `started_at`/`completed_at` | Processing window | Job | Local, derivable duration = `completed_at − started_at` |

**Confirmed telemetry findings**:
- **Duplicate cost calculation**: `estimated_cost` is computed in two
  places using the same formula (service, then job) — not a bug (both
  reads produce the same number from the same tokens), but a real
  duplication worth resolving before a ledger depends on either value as
  authoritative.
- **Partial token retention on failure**: a JSON-parse failure *after* a
  real provider call retains `tokens_input`/`tokens_output` (persisted
  before parsing) — this failure mode legitimately consumed provider
  resources and should be billable. A failure *before* any provider call
  (missing file, empty text, unsupported type) never reaches that
  persist step — `tokens_input`/`tokens_output` remain `null`, correctly
  representing zero provider cost.
- **No duration column exists** — only `started_at`/`completed_at`
  timestamps, from which duration must be derived at query time; there is
  no stored `duration_ms` or similar.
- **No retry/attempt counter column** — `$tries = 1` makes this largely
  moot for Laravel-level retries, but there is also no field recording
  "this is attempt N for this logical document" if a user manually
  triggers `force_new` after a failure.
- **Consistency between the two tables**: identical column set and
  identical persistence pattern in both services — Contract and Trade
  Package analyses record data consistently with each other.

### `ai_conversations` / `ai_messages`

Real columns exist (`token_count` on `AiConversation`, for example) but,
per §3.4, no working code path ever creates a row in either table. Their
schema should be treated as speculative/unused for G4C purposes.

## 7. Read-only development-data sample

**Direct query result against `suresign` at `localhost:3307`** (read-only,
no writes):

| Table | Row count |
|---|---|
| `contract_ai_analyses` | **0** |
| `trade_package_ai_analyses` | **0** |
| `ai_conversations` | **0** |
| `ai_messages` | **0** |
| `organizations` (for context) | 3 |
| `contracts` (for context) | 0 |
| `trade_packages` (for context) | 0 |
| `file_uploads` (for context) | 0 |

**There is no AI analysis data of any kind in this environment.** This
is not a small sample to qualify cautiously — it is an empty one. No
date range, no status breakdown, no organisation/contract distribution,
no cache-hit count, and no null-telemetry count can be produced, because
there are no rows to compute any of these from. This environment appears
to be a fresh/skeleton database (zero contracts and zero trade packages
exist at all, not just zero analyses of them) rather than a demo dataset
with representative history.

## 8. Token, cost, duration and failure findings

**Not computable from this environment** — see §7. No count, null count,
minimum, median, mean, p75, p90, p95, maximum, outlier, or total can be
reported for tokens, cost, or duration. Reporting any number here would
be fabrication, which the approved instructions explicitly prohibit.

What **can** be stated architecturally (from §6, not from data):
- Token counts are provider-reported and therefore trustworthy once real
  usage exists.
- Cost is a deterministic function of tokens only — no other billed
  provider dimension exists in this codebase's Claude integration.
- `max_input_chars = 150,000` (config) puts a hard architectural ceiling
  on input token count per single analysis (~37,500 tokens by the
  config's own ~4-chars/token estimate) — useful for provisionally
  bounding what a token-band model's upper band should even represent.
- `max_tokens = 16,000` similarly ceilings output tokens per analysis.

## 9. Cache and duplicate-analysis behaviour

Traced directly from `ContractAnalysisService::analyse()` and
`TradePackageAnalysisService::analyse()` (identical logic in both):

1. Extract text from the uploaded file.
2. Compute `document_hash = sha256(extracted_text)`; persist it on the
   **current** (new) analysis row immediately.
3. Look up an existing row with the **same hash AND same model**, status
   `completed` or `confirmed`, **excluding the current row's own id**.
4. **If found** (exact cache hit): return the cached `raw_response_json`
   with `tokens_input = 0, tokens_output = 0` — **no provider call is
   made**. Confirmed no call to `makeProvider()`/`complete()` occurs on
   this path.
5. **If not found**: call the provider, persist tokens/cost/raw response
   before parsing (see §6's partial-retention finding), then parse.

**Confirmed answers to the required questions**:
- **A cache hit still creates a NEW analysis row** — the `pending`
  row was already created by the controller before the job ran; the
  cache hit only changes what the job does *inside* that existing row
  (zero-token completion), it does not skip row creation or return an
  old row's id to the caller. This matters directly for ledger design:
  a future zero-charge event must be attached to the *new* analysis id,
  not silently omitted.
- **The provider is not called again** on an exact hit — confirmed, this
  is a genuine provider-free reuse, not "free" in name only.
- **Cache eligibility is scoped by hash + model only** — not by
  organisation, project, or contract. A hash match from a *different*
  organisation's identical document content would currently be reused
  too (the `WHERE` clause has no organisation/tenant filter). This is a
  correctness-neutral fact today (result content is identical either
  way, and Anthropic's response for identical input+model is
  deterministic-ish content, not tenant-specific) but is worth flagging
  explicitly for the future ledger: a cross-organisation cache hit must
  still correctly attribute the *zero-charge event* to the requesting
  organisation, not the organisation that originally produced the cached
  row.
- **No invalidation mechanism exists** — a cached analysis is eligible
  forever (no expiry, no "re-check periodically" logic). Re-running
  `reparseAnalysis()` doesn't invalidate anything either — it only
  re-attempts parsing an already-stored raw response, making **no**
  provider call (confirmed: neither `AiController::reparseAnalysis()`
  nor `TradePackageAiController::reparseAnalysis()` reference
  `makeProvider()`).
- **User confirmation does not affect reuse eligibility** — the cache
  lookup filters on `status IN ('completed','confirmed')`, so both a
  merely-completed and an already-confirmed prior analysis are equally
  eligible as a cache source.
- **A retry after failure is a fresh provider request**, not a cache hit
  — a `failed` row is never matched by the cache lookup's status filter,
  and `force_new`/a fresh `startAnalysis()` call creates a new `pending`
  row that goes through the full extract→hash→lookup→call sequence again
  (and would only hit cache if some *other* completed/confirmed row
  shares its hash).

**Recommendation validated**: "exact cache hit → zero AI Credits" is
already fully supported by the existing architecture — no provider call
occurs on that path today. This is safe to adopt in a future ledger
without any code change to the analysis path itself.

## 10. Current cost drivers and telemetry gaps

| Candidate driver | Classification | Currently... |
|---|---|---|
| Token count (input + output) | **Major** | Measured (provider-reported, persisted) |
| Number of uploaded files per analysis | Negligible today | Not applicable — both workflows analyse exactly one file per analysis; no multi-document merge exists in either service |
| Document size (chars sent) | Moderate (proxy for tokens) | Inferable (extracted text length is known at analyse-time but not persisted) |
| OCR | Not applicable | No OCR step exists anywhere in `extractText()` — PDF extraction uses `pdftotext`, DOCX uses PhpWord's text runs; no image/OCR pipeline |
| Image processing | Not applicable | No image handling in either analysis service |
| Retries | Negligible (architecturally suppressed) | `$tries = 1` + idempotency guard means duplicate provider calls from queue retry are already prevented |
| Provider latency | Negligible (not a cost driver) | Affects duration, not token cost; not billed by Anthropic |
| Workflow type (Contract vs Trade Package) | Moderate | Currently unmeasured as a distinct cost signal, though the two workflows use different prompts (`ContractAnalysisPrompt` vs `SubcontractAnalysisPrompt`) which could plausibly produce different typical token profiles — unconfirmed without data |
| Extraction complexity / contract length | Major (via tokens) | Inferable via `max_input_chars` ceiling; not directly measured as its own field |

**Classification by driver type**:
- **Provider cost driver**: tokens (input/output) — the only one that
  actually determines what SureSign pays Anthropic.
- **SureSign compute/storage cost driver**: storage of
  `raw_response_json`/`raw_response_text` (already covered by the
  existing `storage_gb` entitlement's `SUM(file_size)`-style accounting
  elsewhere — but note `contract_ai_analyses`/`trade_package_ai_analyses`
  are **not** currently included in `UsageMetricsService::storageGbUsed()`'s
  SUM, since that method only sums `documents`/`file_uploads`/
  `adjudication_documents`/`document_versions`; raw AI response text
  is currently invisible to the storage entitlement).
- **Commercial complexity signal**: workflow type, document length —
  plausible future inputs to a workflow/token-band hybrid, unconfirmed by
  data.
- **Customer-facing pricing signal**: none of the above are exposed to
  the customer today; any future exposure must go through "SureSign AI
  Credits" language only (see §12/§16).

**Telemetry gaps to close before any numeric costing model**: no
persisted document-length field, no persisted duration field, no
workflow-type-segmented cost aggregation, no cache-hit counter/flag
column (cache-hit is only inferable today by cross-referencing
`document_hash` matches across rows — there is no explicit
`was_cache_hit` boolean).

## 11. Comparison of commercial costing models

| Model | Advantages | Disadvantages |
|---|---|---|
| **Fixed** (1 analysis = 1 credit) | Simplest to explain; trivial to implement on top of existing `ai_analyses_per_month` counting (`UsageMetricsService` already counts this way); no telemetry dependency | Ignores real cost variance between a 2-page and a 150-page contract; a plan's allowance either overprices small contracts or underprices large ones |
| **Workflow-based** (Contract vs Trade Package have distinct fixed costs) | Slightly more accurate than pure fixed; still simple; matches the two real, distinct workflows found in §3 | Still ignores document-size variance within a workflow; requires picking two more numbers instead of one, with no data to justify either yet |
| **Token bands** (Small/Standard/Large/Very Large) | Reflects real cost variance; still simple enough for customers to understand ("your contract was Large — 12 credits") | Requires real token distribution data to set band boundaries sensibly — **not available** (§7/§8); risk of arbitrary boundaries if set now |
| **Pure token accounting** | Maximally accurate; directly proportional to actual Anthropic cost | Least customer-understandable (exposes a unit customers have no intuition for); risks looking like disguised per-token billing rather than a flat "credits" abstraction; also the hardest to keep provider-independent, since a future non-Anthropic provider's tokenization may not compare 1:1 |
| **Hybrid (workflow × token band)** | Best long-term accuracy/understandability balance; naturally extensible to future workflows (e.g. a future "Trade Package Bulk" workflow just adds a row) | Most parameters to set correctly; the most exposed to being wrong if set from zero real data |

## 12. Recommended provisional AI Credit model

**Recommendation: Hybrid (workflow × token band), marked PROVISIONAL
PENDING TELEMETRY** — not production-ready today.

Reasoning:
- **Architecture**: both real workflows already record provider-reported
  tokens per analysis and are already structurally identical, so a
  hybrid model requires no new instrumentation to *compute* from once
  enough volume exists — only to *calibrate*.
- **UX**: "Contract Intelligence — Large — 12 SureSign AI Credits" is a
  believable, explainable line item; a bare token count is not.
- **Future scalability**: naturally extends to a future third workflow
  (e.g. a Delivery Document AI feature, should one ever be built) without
  redesigning the model — it just adds a new workflow row with its own
  band thresholds.
- **Customer understanding**: matches the "SureSign AI Credits" framing
  already used in this codebase's cancellation/reparse customer messages
  (`"No AI credits were used."` / `"the in-progress AI usage may still be
  charged"`) — customers are already primed for a credit-based mental
  model, not a token-based one.
- **Provider independence**: workflow names and band labels ("Small",
  "Large") never need to change if the underlying provider changes;
  only the internal token-to-band mapping would need recalibration.

**What is explicitly NOT recommended now**: setting the actual band
token-count boundaries or credit values. With zero real analyses to
sample, any number here would be invented, which §5/§9 of the approved
instructions explicitly prohibit. See §19 for exactly what's needed
first.

## 13. Future ledger architecture

**Conceptual only — no migration, no code.**

Distinct concepts, deliberately not merged with the existing analysis
tables:

| Ledger concept | Purpose | Relationship to `contract_ai_analyses`/`trade_package_ai_analyses` |
|---|---|---|
| **Allowance** | The plan-derived monthly SureSign AI Credit entitlement for an organisation | Read from the entitlement snapshot (§14) — not stored per-analysis |
| **Reservation** | A provisional hold against the allowance made at estimate/dispatch time, before the provider call | New concept; references the analysis id it corresponds to |
| **Settlement** | The final, real credit charge once tokens are known (post provider call) | References the same analysis id; derives its amount from that analysis's `tokens_input`/`tokens_output` |
| **Release/refund** | Reverses a reservation that never settled (failed before/without provider cost) | References the reservation/settlement it reverses |
| **Manual adjustment** | Super Admin goodwill/correction credit, unrelated to any analysis | No analysis reference; has its own reason/actor per G4B-style audit conventions |
| **Cache-hit zero-charge event** | An explicit record that this analysis incurred zero cost due to reuse — not merely the absence of a debit | References the analysis id and (for traceability) the id of the analysis it reused from |
| **Expiry** | Only if a future policy requires unused monthly credits to lapse | Not recommended to design now — no approved policy exists |

**Recommended entry shape**: **typed debit/credit entries, each
individually immutable**, correlated to an analysis via its id — not a
single mutable running balance, and not raw signed deltas with no type
distinction. Reasoning:
- A signed-delta-only ledger (just `+5`/`-5` rows) loses the *reason*
  each row exists without inspecting adjacent rows — harder to audit,
  harder to build a correct "why did this organisation run negative"
  investigation tool for Super Admin.
- Typed entries (`reservation`, `settlement`, `release`, `adjustment`,
  `cache_hit_zero_charge`) let a balance be *computed* as a pure
  aggregate query (`SUM` of settlements and adjustments, netted against
  active reservations) — matching this project's own established
  pattern of "the ledger is the source of truth; a balance is a
  computed view of it," directly parallel to how `billing_entitlement_snapshots`
  already treats "current entitlements" as a computed/resolved value
  rather than a stored mutable field on `Subscription` itself.
- **A mutable running balance column must not be the source of truth** —
  confirmed consistent with this same principle already applied
  elsewhere in this codebase (e.g. G4B.1's explicit choice not to add a
  mutable organisation-level AI credit balance field, when that
  boundary question was raised in Phase G0/Stage 1).

## 14. Allowance source and entitlement relationship

The proposed chain —

```
Pricing Plan → Entitlement Snapshot → Organisation → AI Credit Allowance → Ledger → Remaining Credits
```

— **is confirmed still correct** and requires no architectural change.
Evidence:
- `Feature::AI_ANALYSES_PER_MONTH` already exists as an entitlement key
  (currently a flat analysis-count allowance, not yet a credit amount).
- `EntitlementSnapshotService`/`SubscriptionEntitlementSnapshot` already
  freeze a subscription's resolved entitlements at each commercial event
  — the natural, existing place a future AI Credit allowance number
  would be read from, exactly like every other usage entitlement today.
- `FeatureGate::limit()` already resolves this key through the approved
  snapshot-first precedence — a future ledger's "what's the allowance"
  question should call this, never re-derive plan defaults itself.
- **Remaining credits must be a computed value** (allowance − settled
  usage − active reservations + adjustments), read live from the ledger
  at query time — not cached as a stored balance, absent a proven
  performance need (see §18).

## 15. Reservation, settlement, retry and refund design

**Charging boundary per real workflow** (Contract AI Analysis and Trade
Package AI Analysis — identical shape for both):

| Point | Recommended behaviour |
|---|---|
| Estimation | Shown to the operator before dispatch — a band estimate ("10–15 credits"), never a hard promise, computed from document length as a token-count proxy since exact tokens aren't known pre-call |
| Reservation | Created when the `pending` analysis row is created (same instant as today's `ContractAiAnalysis::create()`) — before the job ever runs |
| Provider invocation | Unchanged — `ClaudeAiProvider::complete()` |
| Settlement | Created when the job successfully persists `tokens_input`/`tokens_output` (today's existing persist-before-parse step) — settlement amount is derived from real tokens, replacing the reservation |
| Refund/release | Created when a `failed` status is reached **and** no tokens were ever persisted (a pre-provider-call failure) — full release, zero settlement |
| Partial-failure charge | When a `failed` status is reached **and** tokens *were* persisted (a post-call JSON-parse failure) — settle at the real token cost; this call happened and cost real money regardless of whether SureSign could parse the result |
| Retry (`force_new`) | A **new** reservation for the **new** analysis row — never modifies or reopens the prior (failed) row's ledger entries |
| Cache reuse | A `cache_hit_zero_charge` entry, zero-amount, referencing the new analysis id — never a full reservation+settlement cycle |
| Duplicate job dispatch (queue redelivery) | **Already prevented** by the existing `$tries = 1` + status-guard idempotency check (§5) — the future ledger's reservation should be keyed 1:1 to the analysis id, so even in the hypothetical case this guard ever failed, a second settlement attempt against an already-settled analysis id would be rejected as a duplicate, never double-charged |

This directly satisfies the requirement that "one logical analysis"
(one `ContractAiAnalysis`/`TradePackageAiAnalysis` row) can never be
charged twice from a retry — the analysis id is the natural, existing
idempotency key, and it already uniquely and reliably identifies one
attempt.

## 16. Organisation and Super Admin visibility proposals

**Organisation-facing dashboard** (extending the existing Subscription
Intelligence Centre's `AiUsageMeterCard` rather than building a parallel
surface): Remaining Credits, Credits Used, Credits Reserved (only once
reservations exist), monthly trend, top workflows by credit consumption,
most expensive individual analyses, recent AI activity (reusing the
existing `ActivityLog`-based timeline pattern already used for
subscriptions), low-credit warning banner. All customer-visible language
must say "SureSign AI Credits," never "Claude," "tokens," or a model
name — consistent with §16/provider-independence and with this
project's existing customer-facing cancellation/reparse copy already
using "AI credits" language.

**Super Admin-facing** (extending `OrganizationSubscriptionAdminService`/
the G4A Organisation Subscription Administration page, the same pattern
G4B.2 already followed for manual/complimentary subscriptions): view an
organisation's AI Credit ledger and balance, search usage by
workflow/date/organisation, investigate a specific failed/reserved entry,
and — as a distinct, explicitly-audited future action — grant/adjust
credits (mirroring G4B.2's reason/confirmation/`ActivityLog` pattern
exactly, never a silent balance edit).

## 17. Security and tenant-isolation findings

- **Organisation ownership is unambiguous for both real workflows**:
  `contract_ai_analyses.organization_id` and
  `trade_package_ai_analyses.organization_id` are both direct,
  **non-nullable** foreign keys, set explicitly by the controller at
  creation time from `$contract->organization_id`/
  `$tradePackage->organization_id` — never derived only through a later
  join, and never nullable. No legacy-row ambiguity risk was found for
  these two tables (both were introduced together with the rest of this
  system, structurally consistent from row one).
- **Deleted-parent behaviour**: `contract_ai_analyses` cascades on
  `contract_id`/`project_id`/`organization_id` delete (per the creation
  migration's `constrained()->cascadeOnDelete()` for all three) — an
  analysis row cannot outlive its organisation. Good for ledger
  correlation safety: a future ledger entry keyed to an analysis id can
  trust that the analysis's `organization_id` was valid at least until
  the analysis itself was deleted (if ever).
- **One confirmed cross-tenant risk to design around, not a live
  vulnerability**: the cache-hit lookup (§9) matches purely on
  `document_hash` + `model` + status, with **no organisation-scoping
  clause**. Today this only affects which *cached content* is served
  (identical either way for identical input) — it does not leak one
  organisation's actual contract text to another (the requesting
  organisation already possesses the identical document to have produced
  the same hash). But a future ledger must still explicitly attribute
  the resulting zero-charge event to the *requesting* organisation, not
  silently reference across a tenant boundary in a way a future report
  or export could mishandle.
- **Authorization**: both controllers enforce
  `organization_id`-equality (or Super Admin/Admin bypass) on every
  analysis-scoped action, consistent with this codebase's established
  `authorize()`-per-controller convention — no policy-class gap found.
- **Data retention**: no automatic deletion/expiry of AI analysis rows
  exists (raw responses persist indefinitely) — a future ledger's
  retention policy is an open question, not something this
  investigation can resolve without a business decision.
- **Audit logging**: `ai_analysis.confirmed`/`ai_analysis.cancelled`/
  `ai_analysis.brief_generated` (and trade-package equivalents) already
  exist via `ActivityLog` — a real, working precedent a future
  `ai_credit.reserved`/`.settled`/`.released`/`.adjusted` set of actions
  can follow directly.

**No evidence of cross-organisation data leakage was found.**

## 18. Performance and reporting considerations

- **Future scaling concerns**: a growing ledger table, monthly
  aggregation for organisation dashboards, and Super Admin cross-org
  search are all foreseeable at volume — none are a concern at current
  (zero-row) scale.
- **Balance calculation**: per §13, remaining credits should be computed
  from the ledger at read time. A cached/materialized aggregate (e.g. a
  denormalized "credits used this period" counter, refreshed
  periodically or on write) is a reasonable **future** optimization —
  but only once real query-latency evidence justifies it, mirroring this
  project's existing storage-usage precedent
  (`UsageMetricsService::storageGbUsed()`'s 10-minute cache, itself only
  added because a live `SUM` aggregate across four tables was
  demonstrably non-trivial). **The ledger must remain authoritative
  regardless** — any cache is a read-side optimization, never a second
  source of truth.
- **Existing reporting**: `UsageMetricsService::aiAnalysesThisMonth()`
  already provides a real, working per-organisation monthly count (UTC
  calendar month, excluding `pending`/`cancelled`) — the direct precedent
  for how a future "credits used this period" aggregate should be scoped
  and windowed.
- **Future reports** (all provisional, no implementation): organisation
  AI Credit usage, plan-level AI Credit utilisation (allowance vs. actual
  usage across all organisations on a plan), average credits per
  analysis by workflow, usage growth over time, and a simple linear
  forecast toward monthly allowance exhaustion — every one of these
  should be built on the ledger and the existing entitlement snapshot,
  never a new, separate source of truth.

## 19. Production telemetry plan needed before numeric pricing

Before setting any token-band boundary or workflow credit value, collect:

- **What to instrument**: nothing new needs to be built to *start*
  collecting the core signal — `tokens_input`/`tokens_output`/
  `estimated_cost` are already recorded on every completed analysis
  today. What's missing (§10) and should be added before serious
  calibration: a persisted extracted-document-length field, a persisted
  duration field (`completed_at − started_at` is derivable but not
  stored, making cross-sectional analysis harder), an explicit
  `was_cache_hit` boolean (currently only inferable by cross-referencing
  hashes), and a workflow-type tag if these are ever aggregated in one
  place. None of this is being added in G4C.0 — it's the concrete list
  for whoever scopes the next data-readiness step.
- **Observation period**: a minimum of one full billing cycle (30 days)
  of genuine multi-organisation usage after any pilot/beta customers
  begin using Contract AI Analysis and Trade Package AI Analysis in
  earnest — not a synthetic burst.
- **Minimum sample size**: at minimum several dozen completed analyses
  spread across more than one organisation and more than one document
  size/type before any percentile (even a median) should be treated as
  representative; per the approved instruction, p95 specifically should
  not be reported at all until the sample is large enough for the tail
  to be meaningful (order of 100+ analyses as a rough floor).
- **Outlier handling**: cap/trim extreme outliers (e.g. the
  `max_input_chars`/`max_tokens` ceiling already bounds the very top of
  the range) before computing central-tendency statistics for band
  boundaries, and report them separately rather than letting a handful
  of unusually large contracts distort a "Standard" band's definition.

## 20. Recommended G4C.1 scope and blockers

**Recommendation: collect production telemetry before any ledger
implementation.** Of the three permitted options, this is the correct
one — not "proceed with ledger foundation, provisional costing," because
even the ledger's *shape* (specifically, which telemetry fields the
settlement calculation reads) depends on closing the gaps in §19 first;
building the ledger schema now risks needing a second migration almost
immediately once real fields are found to be missing. Not "instrumentation
only" in isolation either — the two straightforward telemetry additions
identified (document length, cache-hit flag) are small enough to bundle
with a first, deliberately conservative ledger-foundation migration once
approved, rather than treating them as a fully separate phase.

**Concretely, G4C.1 should be scoped to**: (1) add the small set of
missing telemetry fields identified in §19 to the existing
`contract_ai_analyses`/`trade_package_ai_analyses` tables (additive,
non-breaking, no ledger yet); (2) resolve the duplicate `estimated_cost`
computation (§6) so there is exactly one authoritative place it's
computed; (3) begin a real production observation period. Only after
that period yields a meaningful sample should a subsequent phase build
the actual ledger schema and numeric costing bands.

**Blockers**: zero real usage data (§7/§8) is the primary blocker to
anything numeric. The non-functional AI chat feature (§3.4) is not a
blocker for AI Credits specifically, but is worth flagging separately as
existing dead/broken route surface unrelated to this investigation's
scope to fix.

## 21. Documentation updated (G4C.0)

- This document (new).
- `internal-docs/super-admin/subscription-billing.md` — new G4C.0
  cross-reference section.
- `project-context.md` — new G4C.0 entry.
- `CLAUDE.md` — new note under the AI Workflow Context section pointing
  to this document and flagging the non-functional AI chat routes.

## 22. G4C.1 — AI Usage Telemetry Foundation (implemented)

Closes the concrete telemetry gaps §19/§20 identified. Still NOT AI
Credits — no ledger, balance, reservation, settlement, or deduction
exists anywhere in the codebase after this phase. Scope was restricted,
as recommended in §20, to: (1) the small set of missing telemetry fields
on the two real workflows' tables, (2) resolving the duplicate
`estimated_cost` computation, and (3) correcting one internal metric
found to be silently counting the non-functional AI chat feature. No
ledger schema was started — a real production observation period (§19)
should happen first.

### 22.1 Schema changes (additive only, no ledger/balance fields)

Both `contract_ai_analyses` and `trade_package_ai_analyses` gained
identical new nullable columns (migrations
`2026_08_09_000001_add_telemetry_fields_to_contract_ai_analyses_table.php`
/ `..._000002_..._trade_package_ai_analyses_table.php`):

| Column | Type | Written by | Meaning |
|---|---|---|---|
| `workflow` | `string(50)`, nullable, backfilled for existing rows | Controller, at `pending`-row creation | `App\Support\AI\AiWorkflow::CONTRACT_ANALYSIS` / `::TRADE_PACKAGE_ANALYSIS` — the single normalized workflow identifier |
| `document_char_count` | `unsignedInteger`, nullable | `ContractAnalysisService::analyse()` / `TradePackageAnalysisService::analyse()`, immediately after text extraction | `mb_strlen()` of the extracted document text — a future token-count proxy (§10/§19) |
| `document_file_type` | `string(10)`, nullable | Same as above, via the new `ContractAnalysisService::fileExtension()` helper | Lowercase extension of the analysed upload (`pdf`/`docx`/`txt`) — no OCR, no page count (not reasonably available without new processing — see §22.5) |
| `provider_called` | `boolean`, nullable | Same service, at the exact cache-hit-vs-real-call decision point — `false` on cache hit, `true` immediately before the real `$provider->complete()` call (so it's still `true` even if that call then fails) | The requested "cache hit telemetry" — an authoritative, explicit flag rather than something inferred later from cross-referencing `document_hash` |
| `duration_ms` | `unsignedInteger`, nullable | The owning job (`AnalyseContractWithAiJob`/`AnalyseTradePackageWithAiJob`), at each terminal (`completed`/`failed`) transition | `completed_at − started_at` in milliseconds — computed once from timestamps the job already owns, never duplicated |
| `queue_attempt` | `unsignedInteger`, nullable | Same job, at the `processing` transition | `$this->job->attempts()` — both jobs still run `$tries = 1`, so this mainly documents no queue-level retry occurred, not a real multi-attempt counter |
| `is_final_attempt` | `boolean`, nullable | Same job, same point | `attempt >= $tries` |
| `failure_category` | `string(30)`, nullable | The job's `catch` block, via `AiFailureClassifier::classify()` | One of `App\Support\AI\AiFailureCategory`'s six values; only ever set when `status = 'failed'` |

No column here is a credit, balance, allowance, or reservation. Nothing
customer-visible changed — none of these columns are currently returned
to a different audience than `raw_response_json`/`tokens_input`/etc.
already were (same controllers, same authorization, same response
shape).

### 22.2 Workflow identifier

`App\Support\AI\AiWorkflow` is the single authoritative definition
(`CONTRACT_ANALYSIS = 'contract_analysis'`,
`TRADE_PACKAGE_ANALYSIS = 'trade_package_analysis'`) — deliberately
restricted to the two confirmed real workflows from §3; adding a case
for Prompt Library or AI Chat would misrepresent them as provider-backed.
A genuinely new future provider-backed workflow adds one constant here,
nothing else invents its own string.

### 22.3 Cache-hit telemetry

`provider_called` is the concrete field recorded for "did an exact
cache reuse happen, or did Claude actually get called." It's `null`
for any analysis that failed *before* reaching that decision point
(e.g. missing file, unsupported type) — which itself is informative:
`provider_called IS NULL AND status = 'failed'` unambiguously identifies
a failure that never had a chance to cost anything, without needing to
infer it from the absence of `tokens_input`.

### 22.4 estimated_cost — single authoritative owner (duplication resolved)

Previously computed twice with the same formula: once in
`ContractAnalysisService::analyse()`/`TradePackageAnalysisService::analyse()`
(persisted before JSON parsing, so it survives a parse failure), and
again independently in the job's success handler. The job's copy is
now deleted entirely — the service is the sole writer, in both branches:
the real-call branch (unchanged formula, `$3/M in + $15/M out`) and the
cache-hit branch (now explicitly `0.0`, matching what the old job-side
formula always produced for zero tokens, so the customer-visible number
is byte-for-byte unchanged — only where it's computed changed). Verified
empirically (§22.6): identical estimated_cost for identical token counts
before and after this change.

### 22.5 Document metrics — what was and wasn't added

Added: extracted-text character count, file extension. Both are cheap
(already-known values at the point they're recorded — no new
processing). Explicitly **not added**: page count. No page-counting
capability exists anywhere in `extractText()` (`pdftotext` for PDF,
PhpWord text-run traversal for DOCX, both text-only) and adding one
purely for telemetry would violate the standing instruction not to
introduce expensive processing or approximate a value that can't be
reliably obtained. If page count is ever needed, that's a deliberate,
separate scoping decision, not something this phase invented.

### 22.6 Reporting / internal metric correction

`AdminController::dashboard()`'s `monthly_ai_usage` stat previously
counted `AiConversation::whereMonth(...)` — per §3.4/§6, a table no
working code path ever writes to, so this was always 0 in practice
regardless of real AI usage. It now sums `ContractAiAnalysis` +
`TradePackageAiAnalysis` rows created in the current calendar month
(same platform-timezone convention `ApplicationMonitoringService`'s
`aiBlock()` already used). Confirmed via repo-wide search that no
frontend code currently reads `monthly_ai_usage`/`ai_requests` from this
endpoint's response — this is a pure correctness fix with zero visible
UI change. `ApplicationMonitoringService::aiBlock()` was already
correct (already summed the two real tables) and needed no change.

### 22.7 Retry telemetry

`queue_attempt`/`is_final_attempt` record Laravel's own queue-attempt
number at the point a job starts processing. Given `$tries = 1` on both
jobs, this is architecturally almost always `1`/`true` — it documents
the existing no-retry guarantee rather than introducing new retry
behaviour. A user-level retry (`force_new` after a failure) was already,
and remains, a **new** analysis row with its own fresh telemetry — never
a mutation of the failed row's fields. This matches §15's ledger design
note that a retry must always be a new reservation against a new
analysis id, never a reopened one.

### 22.8 Failure classification

`App\Services\AI\AiFailureClassifier::classify()` maps a caught
`Throwable` to one of `App\Support\AI\AiFailureCategory`'s values, based
only on exception type and this pipeline's own already-curated,
customer-safe message text (never raw provider bodies — no new
disclosure surface). `TIMEOUT` vs `TRANSPORT_ERROR` is distinguished for
a caught `Illuminate\Http\Client\ConnectionException` by checking for
"timed out" in its message; anything not recognized falls to `UNKNOWN`
rather than guessing. `CANCELLED` is deliberately not a category here —
a cancelled analysis never reaches the job's `catch` block, and
`status = 'cancelled'` already fully represents that outcome. Covered by
`tests/Unit/AiFailureClassifierTest.php` (9 cases).

### 22.9 Tests and verification

New tests: `tests/Unit/AiFailureClassifierTest.php` (pure unit, all
classification branches), `tests/Feature/AiTelemetryTest.php`,
`tests/Feature/TradePackageAiTelemetryTest.php` (mirrors the same
scenarios for the second real workflow), and
`tests/Feature/AdminDashboardAiUsageTest.php` (confirms the corrected
metric, not the old `AiConversation` count). Full backend suite: 1618
passed. See `project-context.md`'s G4C.1 entry for the sandbox-specific
storage-permission caveat that blocked three of the new tests from
executing in this particular environment (pre-existing and unrelated —
confirmed by reproducing the identical error against unmodified,
pre-existing tests) and how their logic was independently verified
instead.

### 22.10 Explicit confirmations

No AI Credits, ledger, balance, reservation, settlement, or deduction
was implemented. No customer-visible AI behaviour, provider execution
path, or AI output changed. No subscription, entitlement, Stripe, trial,
manual/complimentary subscription, simulation, or impersonation
behaviour changed. `FeatureGate`/`SubscriptionAccessPolicy` were not
touched. All schema changes are additive and backward-compatible; no
destructive migration, no dropped/renamed column, no ledger table.

### 22.11 Recommended G4C.2 scope

Per §20's original recommendation, now that the telemetry gap is closed:
begin a genuine production observation period (§19's stated minimum — a
full billing cycle, several dozen+ completed analyses spread across more
than one organisation) using the fields this phase added, before setting
any token-band boundary or workflow credit value. Only after that
sample exists should a subsequent phase design the actual ledger schema
(§13) and provisional costing bands (§11/§12).

## 23. Large Contract Analysis Investigation — Root Cause and Resolution (deferred chunking)

A real local test with a genuine 110-page construction contract
("CC1085 CURO DRAFT CONTRACT", 284,411 extracted characters,
~58,897 input tokens) failed twice, surfacing two real, unrelated bugs
— neither requiring the chunked-analysis architecture a draft phase
(`task14.md`/"G4C.1B — Large Contract Analysis Hardening") had proposed
building in response.

### 23.1 First failure — output token ceiling

`config('ai.anthropic.max_tokens')` was hardcoded to 16,000. The
110-page contract's structured extraction genuinely needed more output
than that to complete, and the response was cut off mid-JSON
(`stop_reason: max_tokens`), correctly detected as unparseable and
rejected (never accepted as a false success).

**Resolution**: the real, provider-confirmed maximum output for
`claude-sonnet-5` was checked directly (live API calls, 2026-07-26,
trivial/near-zero-cost prompts): 64,000 and 100,000 were accepted;
200,000 was rejected with the API's own error stating the true ceiling
— `"max_tokens: 200000 > 128000, which is the maximum allowed number of
output tokens for claude-sonnet-5"`. `ANTHROPIC_MAX_TOKENS` is now
configured to **128,000** (`docker-compose.dev.yml`) — the model's real
maximum, not a guessed intermediate value. Setting the ceiling this
high costs nothing extra; Anthropic bills only actual generated tokens.

### 23.2 Second failure — extended-thinking content-block parsing bug

After raising the ceiling, the same contract failed differently:
`"The AI service returned an unexpected response."` — `tokens_input`/
`tokens_output` both null, i.e. the failure happened before the
provider's usage data was even read.

**Root cause**: `ClaudeAiProvider::complete()` read
`$body['content'][0]['text']` unconditionally, assuming the first
content block is always the final text response. With
`output_config.effort` set (this pipeline always sets one — see
`suresign_settings.ai_effort`), Claude Sonnet 5 can prepend a `thinking`
content block ahead of the actual `text` block for a sufficiently
complex/long request — confirmed live: a moderately complex reasoning
prompt returned `content` of `[{"type":"thinking",...},
{"type":"text",...}]`, so `content[0]['text']` was null even though the
real answer was in `content[1]`. A trivial prompt did not trigger this
(no thinking block), which is why the bug wasn't visible in earlier
provider tests using short prompts.

**Fix**: `ClaudeAiProvider::complete()` now finds the block where
`type === 'text'` anywhere in the `content` array
(`collect($body['content'] ?? [])->firstWhere('type', 'text')`),
instead of assuming position 0. Covered by
`tests/Unit/ClaudeAiProviderThinkingBlockTest.php` (thinking-block-first
case and text-only case).

### 23.3 Validated outcome

With both fixes applied, the same 110-page contract was re-run for
real: `status = completed`, `stop_reason = end_turn` (finished
naturally), `tokens_input = 58,897`, `tokens_output = 30,287` — well
under the 128,000 ceiling — full valid JSON with all 19 expected
top-level sections populated, no error. See §24.3 for the corrected
cost figure for this same run.

### 23.4 Decision: chunked analysis deferred

**Architectural Decision Record**: Chunked analysis has been
intentionally deferred because production evidence demonstrates that
the current Claude Sonnet 5 implementation successfully processes
representative large construction contracts within the verified
128,000-token output ceiling. Extrapolating linearly from the measured
~275 output tokens/page, that ceiling covers roughly ~465 pages before
truncation risk returns — comfortably beyond the 110-page (and even a
hypothetical 300-page) case with no code beyond the two fixes above.
Building a chunk-persistence/merge/dedup/orchestration architecture
now would have solved a problem with zero confirmed real occurrences
(§7 — this environment had zero real analyses before this
investigation). The architecture will be revisited only if future
production telemetry demonstrates a real operational need — i.e. a
real document's required output genuinely exceeds 128,000 tokens even
with both fixes in place. `task14.md`'s full chunking spec remains
on file as a reference design for that future case, not scheduled
work.

## 24. G4C.1A — Local AI Telemetry Observation & Validation

Audits and validates the G4C.1 telemetry model against real analyses
(the §23 investigation produced the first ones this environment has
ever had), fixes issues the audit found, and documents observed
behaviour. Still not AI Credits — no ledger, balance, allowance,
reservation, settlement, or enforcement exists anywhere in the
codebase after this phase.

### 24.1 Telemetry completeness audit

Both `contract_ai_analyses` and `trade_package_ai_analyses` carry an
identical column set (confirmed via `Schema::getColumnListing()` on
both tables): `workflow`, `provider`, `model`, `document_hash`,
`document_char_count`, `document_file_type`, `provider_called`,
`tokens_input`, `tokens_output`, `estimated_cost`, `stop_reason`,
`failure_category`, `status`, `started_at`/`completed_at`/
`duration_ms`, `queue_attempt`/`is_final_attempt`, `created_at`. No
missing field was found among what G4C.1 was scoped to add.

**One confirmed gap, not fixed in this phase**: no `prompt_version`/
`schema_version` column exists. `ContractAnalysisPrompt`'s system
prompt is internally documented as "schema v2.0" (see its own
docblock/comment), but that version string isn't persisted anywhere —
so there is currently no way to query "which analyses used which
prompt schema" without re-reading each row's `raw_response_json` shape.
Recorded here as a known limitation for a future phase, not invented
now (G4C.1A's own scope explicitly excludes new speculative fields).

**Cache-hit representation**: there is no separate `cache_hit` boolean
— `provider_called = false` is the authoritative signal, per G4C.1's
original design. Confirmed still correct and sufficient; no change
made.

### 24.2 Cost calculation audit — pricing bug found and fixed

`ContractAnalysisService::estimateCost()` (the single authoritative
cost calculation used by both workflows, per G4C.1) was hardcoded to
$3/M input, $15/M output. Checked directly against Anthropic's own
current pricing documentation
(`https://platform.claude.com/docs/en/about-claude/pricing`, fetched
2026-07-26): Claude Sonnet 5 is on **introductory pricing — $2/M
input, $10/M output — through 2026-08-31**; the $3/$15 rate the code
used doesn't take effect until **2026-09-01**. Today (2026-07-26) is
inside the introductory window, so every cost estimate the application
had produced up to this point was **50% too high**. Confirmed on the
real 110-page analysis: previously stored as `$0.630996`
($3/$15 math), corrected to `$0.420664` ($2/$10 math) — the same
`tokens_input`/`tokens_output`, only the price-per-token constants
changed.

**Fix**: `ContractAnalysisService` now has explicit
`PRICE_PER_MILLION_INPUT_TOKENS = 2.0` / `PRICE_PER_MILLION_OUTPUT_TOKENS
= 10.0` constants (replacing the inline `* 3 + * 15` formula), with a
docblock recording the exact source, the introductory-window end date,
and the standard rate that takes over on 2026-09-01 — so updating this
is a one-line, well-signposted change on that date, not a rediscovery.
Rounding remains `round(..., 6)`, unchanged. Existing tests that
duplicated the old hardcoded formula (`AiTelemetryTest`,
`TradePackageAiTelemetryTest`) now call
`ContractAnalysisService::estimateCost()` directly instead of
re-deriving the arithmetic, so they can never drift from the real
formula again. New `tests/Unit/ContractAnalysisCostTest.php` pins the
current rate and the real 110-page measurement as a regression anchor —
if it starts failing on/after 2026-09-01, that is the reminder to
update the two constants.

### 24.3 Real analyses validated

This environment's only real contract (`CC1085 CURO DRAFT CONTRACT`,
project id 1, org id 6) produced, across the §23 investigation and
this phase's own validation:

| Analysis id | Scenario | Status | tokens in/out | Cost (corrected) | Duration | Notes |
|---|---|---|---|---|---|---|
| 5 | pre-fix, model 404 | failed | –/– | – | – | Unrelated model-string bug, fixed earlier |
| 6 | pre-fix, 16k ceiling | failed | 58,897/16,000 | – | – | Truncated; reclassified `output_truncated` (was `provider_rejection`) per §24.4 |
| 7 | 64k ceiling, pre thinking-block fix | failed | –/– | – | – | `provider_rejection` (correctly — a real, distinct bug, not truncation) |
| 8 | 128k ceiling + both fixes | **completed** | 58,897/30,287 | **$0.420664** | 237,983 ms (~4 min) | The reference benchmark — full valid v2.0 schema, all 19 sections populated |
| 9 | identical document re-run | **completed (cache hit)** | 0/0 | **$0** | 1,092 ms | `provider_called = false`, confirmed real (not simulated) cache reuse |

**No small or medium contract, and no Trade Package document, exists
in this local environment** — confirmed by direct query
(`Contract::all()` returns exactly one row; `TradePackage::all()`
returns zero). Per this phase's own instruction not to fabricate
analyses that don't exist, no synthetic small/medium sample is
reported here. This is the same "zero real usage" starting point G4C.0
found — §23/§24 are the first real analyses this environment has ever
produced.

### 24.4 Usage trends — explicitly not meaningful yet

Per the same honesty standard G4C.0 set for percentiles: **no usage
trend is reported here as if representative**. The entire dataset is
five rows, all created by manual investigation of one document in one
session, not organic customer usage. Any "average tokens" or "cache-hit
frequency" computed from n=5 (mostly deliberately-triggered failures)
would misrepresent noise as signal. The only honest statements
possible: the one successful real analysis used 58,897 input / 30,287
output tokens over ~4 minutes at $0.42; the one cache hit completed in
~1 second at $0. Nothing more should be inferred until real
multi-organisation volume exists (§22.11's G4C.2 recommendation is
unchanged by this).

### 24.5 Failure classification audit — `output_truncated` added

Confirmed `AiFailureClassifier` correctly separates validation
failures, internal exceptions, and connection-level timeout/transport
errors. **One real gap found**: a truncated response (§23.1) was
classified as the generic `provider_rejection` — technically not
wrong (a real provider call did happen), but imprecise, since the
pipeline already has a direct, reliable signal
(`stop_reason === 'max_tokens'`, surfaced via a distinct exception
message from `ContractAnalysisService`/`TradePackageAnalysisService`)
that a *different* failure — `"AI service returned an unexpected
response"`/`"AI request could not be completed"` — does not have.

**Fix**: added `App\Support\AI\AiFailureCategory::OUTPUT_TRUNCATED`
and split its message fragment out of `AiFailureClassifier`'s
provider-rejection list into its own check. Re-classifying analysis
id=6's stored error message under the new logic now correctly returns
`output_truncated` (verified directly, then the historical row was
updated to match, since it's local test data, not customer data).
Covered by a new case in `tests/Unit/AiFailureClassifierTest.php`
(now 10 cases, all passing). `CANCELLED` remains deliberately
unclassified (§22.8) — unchanged.

### 24.6 Cache validation — confirmed correct, live

Verified directly against real data (analysis id=9 above), not just by
code review: re-running analysis against the identical document
(`file_upload_id = 4`, same `document_hash`, same model) produced
`provider_called = false`, `tokens_input = 0`, `tokens_output = 0`,
`estimated_cost = 0.0`, and `raw_response_json` identical to the
original (id=8) — a real, confirmed zero-cost cache reuse, not a
simulated one.

**Tenant-isolation finding carried forward unchanged from G4C.0/§17**:
the cache lookup still matches on `document_hash` + `model` + status
only, with no organisation-scoping clause. This phase did not change
that — per its own instruction not to make a cache-policy decision
silently, and per G4C.0's existing conclusion that this is
correctness-neutral today (a hash match requires already possessing
the identical document) but should be tightened before any future
ledger charges based on cache identity. No cross-organisation test was
possible with current data (both real analyses belong to the same
organisation, id 6).

### 24.7 `executive_summary` / `contract_summary` field mismatch — fixed

The schema mismatch first noticed during §23's validation: schema
v2.0 (see `ContractAnalysisPrompt`'s own "Schema v2.0 specific rules"
comment) produces `executive_summary.commercial_summary`, but
`AnalyseContractWithAiJob` and `AiController::reparseAnalysis()` both
still read a flat top-level `contract_summary` key that v2.0 never
produces — silently leaving `ContractAiAnalysis::summary` null for
every v2.0 analysis (confirmed: analysis id=8 had `summary = null`
before this fix, despite a fully populated, valid result).

**Frontend was already correct** — `frontend/.../contracts/page.tsx`
already branches on `isV2 = 'contract_overview' in result` and renders
`executive_summary.commercial_summary` for v2.0 results; the
`contract_summary` branch is its own intentional v1-legacy fallback,
not a bug. Confirmed via code read, not assumed. No frontend change
was needed.

**No consumer currently reads the persisted `ContractAiAnalysis::summary`
/`TradePackageAiAnalysis::summary` column at all** (confirmed by
repo-wide grep) — today's impact was limited to that column silently
being wrong/empty, not a visible customer-facing break. Still worth
fixing correctly, since a future list/admin view reasonably could read
it.

**Fix**: added `ContractAnalysisPrompt::extractSummary(array $data):
?string` as the single canonical resolution — prefers
`executive_summary.commercial_summary` (v2.0), falls back to the flat
`contract_summary` (v1) only for already-completed legacy analyses,
truncates to 1000 chars via the existing `Str::limit` convention.
`AnalyseContractWithAiJob` and `AiController::reparseAnalysis()` both
now call this one method instead of independently guessing a key.
Trade Package's equivalent (`general.subcontract_title`) was checked
and confirmed still current — no mismatch there. Covered by
`tests/Unit/ContractAnalysisPromptSummaryTest.php` (6 cases: v2.0 key,
v1 fallback, v2.0-wins-when-both-present, neither-present, partial
`executive_summary` without `commercial_summary`, and truncation
length). The one real affected row (analysis id=8) was backfilled to
its correct summary text as part of validating the fix, not left
stale.

### 24.8 Tests and regression

New/updated: `tests/Unit/ClaudeAiProviderThinkingBlockTest.php` (2),
`tests/Unit/ContractAnalysisPromptSummaryTest.php` (6),
`tests/Unit/ContractAnalysisCostTest.php` (4),
`tests/Unit/AiFailureClassifierTest.php` (+1, now 10),
`tests/Feature/AiTelemetryTest.php`/`TradePackageAiTelemetryTest.php`
(cost assertions now call the real service method instead of
duplicating the formula). Full backend suite: 1631 passed. The same
26 pre-existing issues from this sandbox's known storage-permission
gap and one unrelated already-modified test
(`PaymentApplicationExcelDisclosureTest`, confirmed via `git status`
predating this phase) remain — zero new regressions introduced by
this phase.

### 24.9 Explicit confirmations

No AI Credits, ledger, balance, allowance, reservation, or settlement
was implemented. No subscription, entitlement, `FeatureGate`, Stripe,
or pricing *behaviour* changed (the `estimated_cost` **calculation**
was corrected for accuracy, which is explicitly this phase's purpose —
not a pricing/billing feature). No customer-facing AI usage UI was
implemented. Chunked analysis was intentionally deferred based on
measured production evidence (§23.4). The validated 110-page analysis
(id=8, §23.3/§24.3) is the current reference benchmark. Prompt
Library, AI Chat, Commercial AI, Delivery Document AI, and
Risk-generation AI remain untouched and out of scope.

### 24.10 Recommendation before AI Credits

Unchanged from §22.11: begin a genuine production observation period
before any ledger/costing work. This phase adds one clarification —
observation should now also confirm the corrected $2/$10 introductory
pricing holds through 2026-08-31 and that whoever picks this back up
after that date updates `ContractAnalysisService`'s two pricing
constants to $3/$15 first, or any cost-based conclusions drawn from
telemetry after that date will again be systematically wrong in the
opposite direction (underestimating).

**Superseded by §25** — §24.2's fix used hardcoded constants with a
manual-update reminder for the 2026-09-01 switch. §25 replaces that
with a date-aware schedule so no manual edit (and no risk of forgetting
it) is needed when the rate changes.

## 25. G4C.1A.1 — Effective-Dated AI Provider Pricing

§24.2's fix corrected the *current* rate but left a real risk: it was
still two hardcoded constants, relying on a human remembering to edit
them on 2026-09-01. If that edit were missed (or made late), every
cost calculated after the switch would silently use the wrong rate
again — the same class of bug §24.2 had just fixed, recurring
automatically on a schedule. This phase removes the manual step
entirely.

### 25.1 What changed

- **New config**: `config/ai_pricing.php` — a per-model array of dated
  rate periods (`effective_from`, `effective_until` — inclusive
  whole-day boundaries; `null` until-date means "still current").
  Currently holds `claude-sonnet-5`'s two known periods (the same
  $2/$10 introductory and $3/$15 standard rates from §24.2, now data
  instead of code).
- **New class**: `App\Services\AI\AiPricingSchedule::rateFor(string
  $model, DateTimeInterface $at): ?array` — the single place a rate is
  resolved. Returns `null` (never a guessed rate) when the model has no
  configured schedule, or no period covers the given instant — a
  missing/incomplete config fails safe (no cost persisted) rather than
  silently mis-pricing.
- **`ContractAnalysisService::estimateCost()` signature changed**: now
  `estimateCost(int $tokensInput, int $tokensOutput, string $model,
  DateTimeInterface $at): ?float` — takes the model and an explicit
  instant instead of reading two hardcoded constants. Returns `null`
  when `AiPricingSchedule::rateFor()` does, so `estimated_cost` stays
  null (already a valid, nullable state) rather than ever holding a
  fabricated number.

### 25.2 Provider-call timestamp, not current date

The critical design constraint: rate selection must use **when the
provider call actually happened**, never "today," so a historical
analysis's cost is stable forever and a future recalculation (audit,
backfill, dispute) always reproduces the original number. Both
`ContractAnalysisService::analyse()` and
`TradePackageAnalysisService::analyse()` now capture `$calledAt =
now()` once, immediately after the real provider call returns (the
same point they already persist `tokens_input`/`tokens_output`/
`raw_response_text`), and pass that explicit instant into
`estimateCost()` — the method itself never calls `now()` internally.
This is what makes `AiPricingSchedule`/`estimateCost()` pure and
independently testable: given the same `(tokens, model, instant)`
triple, the result never changes, regardless of wall-clock time at the
moment someone asks.

### 25.3 Verified live (not just unit-tested)

Directly against the running container, using the real
`ContractAnalysisService`:

| Instant | Rate resolved | Cost for the real 110-page tokens (58,897 in / 30,287 out) |
|---|---|---|
| `now()` (2026-07-27, inside introductory window) | $2/$10 | `$0.420664` |
| `2026-07-26 14:53:53` (the real historical provider-call instant) | $2/$10 | `$0.420664` — identical, confirming historical stability |
| `2026-09-05` (after the switch) | $3/$15 | `$0.630996` |

### 25.4 Tests

`tests/Unit/AiPricingScheduleTest.php` (6 cases): last day of the
introductory window (`2026-08-31 23:59:59`), first day of the standard
window (`2026-09-01 00:00:00`), first day of the introductory window,
a fixed historical instant reproducing the same rate regardless of
wall-clock time, unknown model → null, instant before any configured
period → null. `tests/Unit/ContractAnalysisCostTest.php` rewritten (5
cases) to cover the new signature: rate-at-instant integration, the
real 110-page measurement pinned against its exact historical instant,
six-decimal rounding, zero-tokens, and unknown-model safe failure (now
asserting `null`, not a guessed number). Existing
`AiTelemetryTest`/`TradePackageAiTelemetryTest` cost assertions updated
to the new 4-arg call. Full backend suite: 1638 passed, same
pre-existing sandbox/unrelated issues as §24.8, zero new regressions.

### 25.5 Explicit confirmations

No AI Credits, ledger, balance, allowance, reservation, settlement, or
customer charging was implemented. No subscription limits, Stripe
changes, or customer-facing dashboard were added. This phase changes
*how* a cost is calculated (data-driven, date-aware, fail-safe) — not
*whether* one is charged, tracked, or enforced anywhere.

### 25.6 Recommendation — continued telemetry collection, still not a ledger

Per this phase's own framing: the next step is continued telemetry
collection, not numeric AI Credit implementation. Representative
samples are still needed before any ledger/costing design — a small
Contract Analysis, a medium one, additional large contracts beyond the
single 110-page case, at least one real Trade Package Analysis (zero
exist in this environment today — §24.3), real cache hits at volume,
and genuine failures/retries occurring organically rather than via
manual investigation. §22.11/§24.10's original recommendation stands
unchanged: no ledger schema or costing band work begins until that
sample exists.

### 25.7 Superseded by the AI Credit Policy document (Phase G4C.2 onward)

This §25.6 recommendation was carried forward and acted on: Phase G4C.2
produced `internal-docs/commercial/ai-credit-policy-and-consumption-model-v1.md`
(the approved-architecture, not-yet-approved-numbers specification), and
Phase G4C.2C-2 (that document's §43) built the actual non-enforcing
simulation and internal reporting this section anticipated. For any
question about current AI Credit simulation/telemetry-reporting state,
that document's §43 is authoritative going forward — this section is
kept as the historical reasoning trail that led there.
