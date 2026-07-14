# Subcontract AI Analysis

## What this is

The trade package equivalent of contract AI analysis: it reads an uploaded
subcontract document for a trade package and extracts the same kind of
structured information (terms, dates, obligations) for review.

## Who can use it

Super Admin and Admin, and only if AI features are enabled for your
organisation.

## Where to find it

Open a project, open a trade package, and select the **AI Analysis** tab in the
trade package workspace.

## What you will see

- If no analysis has been run yet, an empty state: "No analysis has been run
  yet," with an option to start one.
- Once started, a status badge (Pending, Processing, Completed, Confirmed,
  Failed, or Cancelled — the same lifecycle as contract analysis).
- A history of past analysis runs for this trade package.

## Re-parsing

If an analysis result needs to be reprocessed (for example because it was
saved in a slightly malformed state), a **re-parse** action is available. This
is explicitly free — SureSign confirms with a message that re-parsing does not
use any AI credits, because it works from the already-stored response rather
than calling the AI again.

## Limits

The same safeguards as contract analysis apply: only one analysis can run at a
time for a given trade package, there is an hourly limit on how many analyses
you can start, and re-analysing an unchanged document reuses the previous
result rather than running again.

## What to do next

Review and confirm the result the same way as for a
[contract analysis](../contracts/reviewing-ai-results.md) — confirmed data
feeds into the trade package's commercial and programme information.

## Related

- [Trade Packages: Workspace](../trade-packages/workspace.md)
- [AI in SureSign](overview.md)
- [Limitations](limitations.md)
