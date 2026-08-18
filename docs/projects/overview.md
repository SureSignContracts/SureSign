# Projects

## What this is

A project is the central workspace for a piece of construction work: a single
site, contract, or development. Every contract, trade package, commercial
record, document, and operational record (RFIs, meetings, site reports, and so
on) belongs to one project.

## Who can use it

Any authenticated user in your organisation — Client, Admin, and Super
Admin accounts alike — can view, create, and edit projects belonging to
their own organisation. Project creation and editing is not restricted to
Administrator accounts — see [Client](../roles/client.md) for the small
number of things that genuinely are Admin/Super Admin only.

## Where to find it

Tenant workspace: **Projects** in the main sidebar, or the **Active Projects**
tile on your dashboard.

## Before you begin

Before creating a project, it helps to know:

- The project name and a short project code (used throughout the platform and
  on generated documents and file paths).
- The client/employer for the project.
- The likely contract type and value, if known.
- The site address.

## How to use it

See [Creating a Project](creating-a-project.md) for the step-by-step process.

Once created, a project has its own workspace (see
[Project Navigation](project-navigation.md)) covering the contract, commercial
records, programme, risk register, communications (RFIs, meetings), delivery
records (QA, snagging, site reports, delivery documents, closeout), documents,
and calendar.

## The portfolio page

The **Projects** page itself is the organisation-wide portfolio, for finding,
comparing, and opening projects rather than doing the actual project work.
It shows:

- A **portfolio summary**: Total Projects, Active Projects, Requires
  Attention, and Completed or Closed, all counted across your accessible
  projects regardless of any filter you have applied.
- **Search and filters**: by name, reference, or location, and by status,
  attention state, or currency. Your search and filters are kept in the page
  URL, so a filtered view can be shared or bookmarked and returns you to the
  same view when you come back.
- A **portfolio list** (a table on desktop, cards on narrow screens) with
  each project's status, attention state, outstanding balance, and retention
  held, so projects can be compared without opening each one individually.
- An **attention indicator**, showing whether a project has at least one
  overdue or due-today item. This uses the same rule as the Dashboard's
  Needs Attention queue, so the two pages always agree on which projects
  need attention.

Detailed commercial figures, deadlines, and record editing all remain inside
the project's own workspace or [Global Commercial](../commercial/global-overview.md);
the portfolio page only shows a compact summary of each.

## Statuses

Projects carry a status (for example Active, On Hold, Completed, Cancelled)
shown as a badge on the project list and Overview page. Status is set from the
project's settings/edit form.

## Related modules

- [Dashboard](../dashboard/overview.md): the organisation-wide action queue this page's attention indicator shares its rule with.
- [Contracts](../contracts/overview.md)
- [Trade Packages](../trade-packages/overview.md)
- [Commercial](../commercial/overview.md)
- [Global Commercial](../commercial/global-overview.md): detailed commercial figures beyond this page's compact summary.
- [Documents](../documents/overview.md)

## Common mistakes to avoid

- Choosing a project code that duplicates or is easily confused with another
  project's code — it appears throughout generated documents and file
  references.
- Leaving the client/employer details blank; several later steps (contract
  upload, commercial records) are easier with this in place.

## What to do next

After creating a project, go to [Contracts](../contracts/overview.md) to upload
the contract and (optionally) run AI analysis.
