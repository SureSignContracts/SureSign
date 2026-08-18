# AI Analysis (Contracts)

## What this is

AI analysis reads an uploaded contract document and extracts a structured
summary: key terms, payment rules, parties, key dates, programme milestones,
the project/site location, and other information, for you to review and
confirm.

Where a project/site location is stated, SureSign keeps it distinct from
every party's own registered office or correspondence address — an
Employer's, Contractor's, or consultant's own address is never used as the
project's location. If the Contract gives only a partial location (for
example a city with no street address), that partial information is kept
as-is rather than a missing part being guessed.

!!! important "AI results are suggestions"
    AI analysis produces extracted information for a person to check. It does
    not automatically change your contract's fields, and it is not a legal or
    contractual determination. Nothing from an analysis is used elsewhere in
    SureSign (such as calculating statutory payment dates) until you
    [confirm it](confirming-analysis.md).

## Who can use it

Anyone with access to the project (including the ordinary Client account
role, not just Admin/Super Admin), and only if your organisation has AI
features enabled (a Super Admin controls this in platform settings).

## Where to find it

On the project's **Contracts** page, use the analyse action on a contract that
has a document attached.

## Before you begin

- The contract must already have a document uploaded (see
  [Uploading a Contract](uploading-a-contract.md)).
- Check whether a previous analysis already exists for this contract — if one
  does, SureSign will offer to show you the existing result rather than
  starting a new one automatically.

## How to start an analysis

1. Open the contract.
2. Select the AI analysis action.
3. If a completed analysis already exists, choose whether to view the existing
   result or start a fresh one.
4. Wait for the analysis to run. A floating progress indicator shows
   "Analysing contract…" and remains visible even if you navigate elsewhere.

## What happens while it runs

- The indicator updates to "Analysis complete" or "Analysis failed" when
  finished. Selecting it takes you back to the contract.
- You can dismiss the indicator without losing the result — the analysis is
  saved and can be reopened from the contract's analysis history.
- If you stay on the analysis screen, a staged progress bar shows what's
  actually happening — preparing the document, extracting its content,
  reviewing it, then structuring the results — along with a percentage, so a
  long analysis doesn't look stuck. A short completion animation plays once
  it reaches 100%.

## Limits you may encounter

- **Only one analysis can run at a time per contract.** Starting a second one
  while the first is still running is blocked with a message that an analysis
  is already in progress.
- **There is an hourly limit** on how many AI analyses you personally can start.
  If you hit it, you will see a message asking you to try again later.
- **Re-analysing the exact same document is efficient**: if you re-run analysis
  on a document that has not changed, SureSign reuses the previous result
  rather than running it again.
- If AI features are turned off for your organisation, the analyse action will
  tell you AI features are disabled — contact your Super Admin.

## What to do next

Once an analysis completes, go to [Reviewing AI Results](reviewing-ai-results.md).

## Related

- [Reviewing AI Results](reviewing-ai-results.md)
- [Confirming Analysis](confirming-analysis.md)
- [Analysis History](analysis-history.md)
- [AI in SureSign: Limitations](../ai/limitations.md)
- [Failed analysis](../ai/failed-analysis.md)
