# AI Credit Calibration Corpus Manifest (G4C.2F)

Non-enforcing, internal-only. This manifest tracks which documents have been
(or are proposed to be) run through the real Contract Analysis / Trade Package
Analysis pipelines for **controlled AI Credit calibration purposes**, and how
each one is classified. It does not contain document content — only metadata
already present in the application database (`document_hash`, character
counts, telemetry) plus manually-recorded legal/classification facts.

See `ai-credit-policy-and-consumption-model-v1.md` §55 for the definition of
controlled calibration evidence vs. production customer evidence that this
manifest exists to support, and §56 for how this manifest fits into the
overall calibration process.

**This manifest never invents a fact.** Any field the repository does not
support is recorded as `unknown` or `pending_review` — never guessed.

## Legal-use states

- `approved_internal` — SureSign-owned/authored document, cleared for internal use.
- `publicly_licensed` — sourced under a licence that explicitly permits repeated internal AI processing.
- `permission_confirmed` — explicit permission obtained from the document's owner for this use.
- `anonymised_authorised` — a real historical document, anonymised, with authorisation to use the anonymised form.
- `pending_review` — not yet classified; must not be treated as approved calibration evidence.
- `excluded` — reviewed and determined ineligible; must never be executed or counted.

## Evidence states

- `valid_provider_backed` — a genuine, real Claude API call occurred; full telemetry present.
- `valid_cache_hit` — reused a prior completed analysis's result (`provider_called=false`); valid telemetry, but not a fresh provider-cost sample.
- `pending_execution` — eligible and queued, not yet run.
- `excluded_failed` — the execution failed; not calibration-eligible (`status=failed`).
- `excluded_incomplete` — terminal but missing required telemetry fields.
- `excluded_legacy` — predates `telemetry_schema_version` versioning (schema version is `null`); usable but flagged as pre-versioning.
- `excluded_missing_source` — original `FileUpload` no longer reconstructable.
- `excluded_telemetry_error` — a telemetry-quality check (see `AiTelemetryReportingService::telemetryHealth()`) flags this row.

## Batches

- **Batch 0** — evidence recovered from ordinary engineering work (bug
  investigation, feature testing) that happened to be real, provider-
  backed executions — not collected under a deliberate calibration
  process. Both documents in this manifest today are Batch 0.
- **Batch 1** — the first deliberately approved controlled corpus,
  selected specifically to fill missing size bands, run only after
  documents are supplied, legal-use is confirmed per document, and a
  spend ceiling is explicitly approved. Not yet populated — see the AI
  Credit Policy document's Batch 1 Input Gate for the exact document/
  legal/spend checklist blocking this.

## Unique Document Register (G4C.2G — document identity is authoritative here)

One row per unique document (identity = analysable model class +
`document_hash`, matching `AiTelemetryReportingService::
uniqueCalibrationDocuments()` exactly — see the policy document §58). This
is the correct table to read for size-spread/commercial-statistics
questions; the Execution Register below exists for full per-request
traceability, including non-calibration-eligible attempts.

| Unique Doc ID | Workflow | Document hash | Batch | Approved for calibration | Legal-use status | Legal confirm. date | Provider-backed reference | Cache-hit references | Included in commercial statistics | Exclusion reason | Notes |
|---|---|---|---|---|---|---|---|---|---|---|---|
| DOC-001 | contract_analysis | 280909-char document, contract_id=4 (see Execution Register for the hash's full value) | 0 | No | pending_review | unknown | ContractAiAnalysis#8 | ContractAiAnalysis#9 | Yes (unique-document layer only — see caveat below) | — | The canonical reference is the provider-backed execution (#8); the cache-hit (#9) is excluded from cost/size sampling by construction, not by this manifest |
| DOC-002 | trade_package_analysis | 701204c2...894b02d | 0 | No | pending_review | unknown | TradePackageAiAnalysis#1 | (none) | Yes (unique-document layer only — see caveat below) | — | Only real Trade Package execution in this environment; small band, not large |

**Caveat on "Included in commercial statistics" above**: both documents
already appear in `AiTelemetryReportingService::summary()`'s document-
layer figures (size percentiles, hypothetical-credit distributions) as of
G4C.2G, because that is simply what the corrected code does with whatever
calibration-eligible data exists — it does not check this manifest's
`approved_for_calibration` field. This manifest's legal-use gate is a
**process control on future executions** (§ Batch 1 Input Gate: no
`pending_review` document may be executed going forward), not a filter
the reporting code currently enforces retroactively on Batch 0. Treat any
report figure drawn from these two documents as **recovered telemetry,
not certified calibration evidence**, until their legal-use status is
resolved.

## Execution Register (full per-request traceability)

| ID | Description | Workflow | Source org | Contract/TP ref | Unique Doc ID | Batch | Legal-use status | Legal confirm. date | Source class. | Confidentiality class. | File type | Text source | Page count | Raw chars | Normalized chars | Expected band | Actual band | Analysis ref | Provider-backed | Cache-hit | Simulation coverage | Evidence status | Exclusion reason | Execution date | Notes |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| CAL-001a | Real construction contract, internal filename "CC1085 CURO DRAFT CONTRACT" (org-supplied name; not independently verified by this manifest) | contract_analysis | Test Company (org 6) | contract_id=4 | DOC-001 | 0 | pending_review | unknown | unknown — no provenance record exists in the repository | unknown | docx | text-native (PHPWord extraction) | unknown | 284,411 | 280,909 | n/a (pre-dates corpus) | large (candidate_b, ≤300,000) | ContractAiAnalysis#5 | false | n/a | n/a (status=failed) | excluded_failed | provider_rejection (pre-fix bug, since resolved) | 2026-07-26 | Pre-fix attempt, before the output-ceiling/content-block fixes described in CLAUDE.md's AI Workflow Context |
| CAL-001b | Same document as CAL-001a | contract_analysis | Test Company (org 6) | contract_id=4 | DOC-001 | 0 | pending_review | unknown | unknown | unknown | docx | text-native | unknown | 284,411 | 280,909 | n/a | large | ContractAiAnalysis#6 | true | false | n/a (status=failed) | excluded_failed | output_truncated (pre-fix bug, since resolved) | 2026-07-26 | Real provider call, but response exceeded the then-16,000-token ceiling |
| CAL-001c | Same document as CAL-001a | contract_analysis | Test Company (org 6) | contract_id=4 | DOC-001 | 0 | pending_review | unknown | unknown | unknown | docx | text-native | unknown | 284,411 | 280,909 | n/a | large | ContractAiAnalysis#7 | true | false | n/a (status=failed) | excluded_failed | provider_rejection | 2026-07-26 | Pre-fix attempt |
| CAL-001d | Same document as CAL-001a — first successful run; **canonical/provider-backed reference for DOC-001** | contract_analysis | Test Company (org 6) | contract_id=4 | DOC-001 | 0 | pending_review | unknown | unknown | unknown | docx | text-native | unknown | 284,411 | 280,909 | n/a | large | ContractAiAnalysis#8 | true | false | full (candidate_a=1.00 flat, candidate_b=9.00/"large") | valid_provider_backed | n/a | 2026-07-26 | Real Claude call: 58,897 input / 30,287 output tokens, $0.420664. `telemetry_schema_version=null` (legacy — predates versioning) |
| CAL-001e | Same document as CAL-001a — cache-hit reuse of CAL-001d | contract_analysis | Test Company (org 6) | contract_id=4 | DOC-001 | 0 | pending_review | unknown | unknown | unknown | docx | text-native | unknown | 284,411 | 280,909 | n/a | large | ContractAiAnalysis#9 | false | true | full (candidate_a=1.00 flat, candidate_b=9.00/"large") | valid_cache_hit | n/a | 2026-07-26 | `provider_called=false`, `estimated_cost=0`. Excluded from DOC-001's size/cost sampling by `uniqueCalibrationDocuments()` — this row is never a second data point |
| CAL-002 | Real subcontract package document, filename "Hythe_Station_Road_Contract.docx"; canonical/provider-backed reference for DOC-002 | trade_package_analysis | Test Company (org 6) | trade_package_id=1 | DOC-002 | 0 | pending_review | unknown | unknown — no provenance record exists in the repository | unknown | docx | text-native | unknown | 51,065 | 47,582 | n/a (pre-dates corpus) | small (candidate_b, ≤50,000) | TradePackageAiAnalysis#1 | true | false | full (candidate_a=1.00 flat, candidate_b=2.00/"small") | valid_provider_backed | n/a | 2026-07-27 | Real Claude call: 22,309 input / 4,162 output tokens, $0.086238. The only real Trade Package execution in this environment — **not** a 40+ page / large document, contrary to an earlier assumption; corrected in the G4C.2F Section 1 review |

## Known gaps in this register

- **Legal-use status is `pending_review` for both unique documents.** Neither was uploaded under a deliberate calibration-corpus legal review process. They must not be treated as approved calibration evidence — only as recovered, correctly-classified telemetry — until the user/founder explicitly confirms a legal-use state for each.
- **No small/medium Contract Analysis sample exists.** DOC-001 (large, 280,909 normalized chars) is the only Contract Analysis document.
- **No medium/large/very-large Trade Package Analysis sample exists.** DOC-002 (small) is the only Trade Package Analysis document.
- **Only one organisation (Test Company, org 6) is represented** — this register must never be used to claim organisation diversity; see §55 of the policy document.
- **Batch 1 does not exist yet** — see the AI Credit Policy document's Batch 1 Input Gate for the document/legal/spend checklist required before it can begin.

## Adding new entries

1. Confirm legal-use status and record the confirmation date *before* running any real analysis. No `pending_review` document may be executed.
2. Run the analysis through the existing, unmodified `POST /contracts/{contract}/ai-analysis` or `POST /trade-packages/{tradePackage}/ai-analysis` endpoints — never a database insert, never a factory.
3. Add a row to the Execution Register referencing the resulting analysis ID, using only facts read back from the database (`document_hash`, character counts, `provider_called`, telemetry fields) — never estimated or assumed values. Add or update the corresponding Unique Document Register row (a new `document_hash` gets a new row; a reused hash gets a new cache-hit reference on the existing row).
4. Record the batch number for both the new Execution Register row(s) and the Unique Document Register row.
5. Re-run `php artisan ai:credits:calibration-report` and note the new totals.
