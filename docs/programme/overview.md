# Programme

## What this is

The Programme section tracks a project's key dates and milestones — planned,
forecast, and actual — for each contract.

## Who can use it

Any authenticated user with access to the project — including the Client
role, not just Admin/Super Admin — can manage milestones.

## Where to find it

Project → **Programme**.

## What you will see

- A health summary: an overall status (On Track, Due soon, At risk, Delayed, or
  No Programme Data) and counts of Total, Complete, Overdue, Due soon, At risk,
  and AI-generated milestones.
- The next critical milestone, with a days-remaining indicator.
- A **Table** view and a **Timeline** view (a Gantt-style chart), switchable at
  the top of the page.
- Status filter chips: All, Not started, In progress, Complete, Delayed, At
  risk.

## Table view

Each milestone shows: name (with an AI icon and expandable source text if it
was extracted by AI), type, responsible party (Contractor, Employer, or Both),
planned date range, forecast date, actual date, variance in days, and status
(with a progress percentage where applicable).

## Timeline view

A chart with a "Today" marker, showing planned dates (hollow circles, red-ringed
if overdue), forecast dates (amber diamonds), and actual dates (filled green
circles), with a dashed line connecting planned and forecast dates where they
differ ("date slip").

## Adding milestones

- **Manually**: use Add/Edit Milestone — choose the contract, milestone name,
  type (Commencement, Sectional Completion, Completion, Handover, Obligation,
  Milestone, Other), responsible party, status, planned/forecast/actual dates,
  and notes.
- **From AI analysis**: use **Seed from AI** to create milestones from a
  contract's confirmed AI analysis. For projects with more than one contract,
  choose which contract's analysis to seed from. This only works once an
  analysis has been [confirmed](../contracts/confirming-analysis.md) — if none
  exists, SureSign tells you so.

## What other modules this affects

- Milestones and their dates appear on the project [Calendar](../calendar/overview.md).
- Overdue and at-risk milestones can relate to [Delay Events](../delay-and-eot/delay-events.md).

## Common mistakes to avoid

- Only reading the Table view and missing the visual "slip" between planned and
  forecast dates that the Timeline view shows clearly.
- Re-seeding from AI analysis without checking whether it will duplicate
  milestones already added manually — review the result after seeding.

## Related

- [Contracts: Confirming Analysis](../contracts/confirming-analysis.md)
- [Calendar](../calendar/overview.md)
