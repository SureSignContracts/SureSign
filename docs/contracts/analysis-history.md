# Analysis History

## What this is

Every AI analysis run for a contract is kept, not just the most recent one, so
you can see what was tried, what was confirmed, and what failed.

## Where to find it

On the project's Contracts page, open the contract and look at its list of past
analyses.

## What you can do with a past analysis

- **View Result** — reopen the review screen for that run.
- **Re-run** — start a fresh analysis (subject to the same rate limits described
  in [AI Analysis](ai-analysis.md)).
- **Generate Brief** — only available once an analysis has been confirmed;
  produces a "Contract Intelligence Brief" PDF summarising the confirmed
  information.

## Statuses you will see

| Status | Meaning |
|---|---|
| Pending | Analysis has been requested and is queued. |
| Processing | Analysis is running. |
| Completed | Analysis finished and is ready for review. |
| Confirmed | You have reviewed and confirmed this analysis; its data is now used elsewhere in SureSign. |
| Failed | The analysis could not be completed — see [Failed analysis](../ai/failed-analysis.md). |
| Cancelled | The analysis was cancelled before finishing. |

## Related

- [AI Analysis](ai-analysis.md)
- [Reviewing AI Results](reviewing-ai-results.md)
- [Confirming Analysis](confirming-analysis.md)
