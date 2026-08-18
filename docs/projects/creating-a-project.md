# Creating a Project

## Who can do this

Any authenticated user in your organisation, including the Client role —
project creation is not restricted to Admin/Super Admin accounts.

## Where to find it

Tenant workspace: **Projects** → **New Project**.

## Before you begin

Creating a project only needs a name — everything else can be added
afterwards from **Edit Project**. Have ready, if you know them already:

- A project number/code.
- The type of work (New Build, Refurbishment, Fitout, Infrastructure,
  Maintenance, or Other).
- How your organisation is acting on this project — see **Your organisation's
  role on this project**, below.

## How to create a project

1. Select **New Project**.
2. Enter the **Project Name** (required).
3. Optionally enter a **Project Number / Code**.
4. Optionally choose a **Project Type** — the type of work (New Build,
   Refurbishment, Fitout, Infrastructure, Maintenance, or Other).
5. Optionally choose **Your organisation's role on this project** — Main /
   General Contractor, Subcontractor / Specialist Contractor, Employer /
   Owner, Consultant, or Other — see **Your organisation's role on this
   project**, below.
6. Select **Create Project**.

That's it — the project is created immediately with these four fields. The
contract type/form, contract value, currency, status, dates, location, and
description are all deliberately left for later: open **Edit Project** on the
new project's **Overview** page whenever you're ready to fill them in (see
**Editing a project**, below). None of them are required to start using the
project, and nothing is guessed or defaulted on your behalf beyond the
project's status, which starts as **Active**.

## Project Setup: setting up from a Contract

Immediately after creating a project, SureSign opens **Project Setup**. From
here you can:

- **Upload an agreement** — if you already have the executed contract
  document, upload it and SureSign will analyse it using the same
  [AI Contract Analysis](../contracts/ai-analysis.md) available elsewhere in
  the platform, so you can review and confirm its extracted details on the
  Contract record.
- **Analyse an existing Contract** — if the project already has one or more
  Contracts, choose one to analyse instead of uploading another.
- **Add another Contract** — upload a further agreement alongside any
  existing ones.
- **Skip for now** — continue straight to the project workspace. Skipping is
  a fully supported choice, not an incomplete state; you can add a Contract
  at any time afterwards from the project's **Contracts** page, or return to
  Project Setup later using **Set up from Contract** on the project's
  **Overview** page.

While an analysis runs in Setup, a staged progress bar shows what's actually
happening (preparing, extracting, reviewing, structuring) with a percentage,
the same as analysing a Contract from the Contracts page directly — see
[AI Analysis](../contracts/ai-analysis.md).

Uploading requires choosing a **Contract Type** (Main Contract, Subcontract,
Consultant Appointment, or Supplier Agreement) — this is never guessed from
the document. If you set **Your organisation's role on this project**, Setup
uses it to pre-select a likely Contract Type (Main Contractor suggests Main
Contract; Subcontractor suggests Subcontract; Consultant suggests Consultant
Appointment) — always just a starting suggestion you can change, never
enforced. A Main Contractor project can still have a Subcontract uploaded
against it, and vice versa; your organisation's role and a Contract's type
are two separate things, and setting one never changes the other.

AI analysis is optional throughout: if your organisation doesn't have AI
features enabled, or an analysis fails, your project and Contract are both
already saved regardless — you can continue to the workspace and complete
everything manually. Reviewing and confirming an analysis in Setup uses
exactly the same [Reviewing AI Results](../contracts/reviewing-ai-results.md)
and [Confirming Analysis](../contracts/confirming-analysis.md) steps as
analysing a Contract from the Contracts page directly — nothing about the
Contract's own data is treated differently because it went through Setup.

## Applying confirmed Contract details to your Project

Once a Contract's analysis has been confirmed, Setup offers **Review Project
Suggestions** — a short list of Project-summary details SureSign can copy
from that confirmed Contract, shown next to your Project's current value for
each one:

- **Contract Value & Currency** (shown and applied together, never
  separately — see below).
- **Commencement Date** → your Project's Start Date.
- **Completion Date** → your Project's Completion Date.
- **Contract Form** (e.g. "JCT Design and Build 2016") → your Project's
  Contract Type.
- **Retention %**.
- **Your Role on this Project** — only offered when it isn't already set,
  and only when SureSign can match your organisation's name to a party
  named in the confirmed Contract (see below).
- **Project Location** — shown as one combined suggestion (address, city,
  region, postcode, country together), not five separate ones. See below
  for how this is extracted and what happens once you apply it.

Nothing here is applied automatically. Each row shows **Current Project**
alongside **From confirmed Contract**; you tick only the ones you want, then
select **Apply Selected**. A blank Project field is ticked by default; a
field that already has a different value is left unticked so nothing you've
already entered is silently replaced. A row that already matches your
Project is shown as already matching, with nothing to select. If a Contract
has nothing suitable to offer, Setup says so plainly rather than showing an
empty list as if something had gone wrong.

**Contract Value and Currency are never applied separately** — SureSign
never assumes a Contract amount is in your Project's existing currency, and
never converts between currencies. If the confirmed Contract's currency
couldn't be determined, no Contract Value suggestion is offered at all
rather than guessing.

**Your organisation's role suggestion** is based only on your
organisation's name matching a specific named party in the confirmed
Contract (for example, the Main Contractor or the Employer) — never on the
Contract's type alone. A specialist contractor's organisation can be the
Main Contractor on one project and a Subcontractor on another; uploading a
Subcontract never changes an already-set Project Role, and a role
suggestion is never shown once you've already set one manually.

**Project Location** is where the contracted works/site are physically
located — SureSign is careful to distinguish this from the Employer's,
Contractor's, or any consultant's own registered office or correspondence
address, and never uses one of those in its place. If a Contract only gives
a partial location (for example just a city and country, with no street
address), SureSign shows and applies exactly that partial information
rather than guessing the missing parts. If your Project's existing location
already matches the confirmed Contract, it's shown as already matching; if
it differs, applying it replaces the whole location with what's shown
(never a partial mix of old and new), so what you see before applying is
exactly what you get afterwards.

Applying Project Location updates your Project's address fields (Address,
City, State/Region, Postcode, Country), and — where the address is specific
enough and SureSign can confidently determine a real-world position —
automatically sets the Project's map position too, so it appears correctly
on the [Project Map](../dashboard/overview.md) without you doing anything
else. SureSign never invents a map position: a vague location (just a city,
region, or country, with no street-level address) is deliberately never
turned into a precise pin, and if SureSign can't confidently determine the
exact position it tells you so rather than guess. In both cases you'll see
a clear result after applying:

- **A specific address with a confident match** — "Project Location applied
  and map position updated."
- **A location that's too vague, or one SureSign can't confidently place**
  — "Project Location applied. SureSign could not confidently determine the
  new map position, so no map pin has been set."

**If your Project's address actually changes** and it already had a map
pin, that old pin is never left in place representing the wrong site — it's
either replaced with a fresh, confident position, or cleared if none could
be confidently determined. (If the applied location already matched your
Project, nothing changes and any existing pin is left exactly as it was.)

**If your Project's address already matches the confirmed Contract but has
no map position yet**, Project Suggestions offers a dedicated "Set map
position" action for just the map pin — your address text is never touched
by this action.

You can always set or correct a Project's map position yourself afterwards
via [Project Settings](project-settings.md#what-you-can-change), regardless
of whether it was set automatically.

This step only ever changes your Project's own summary fields. It never
changes the Contract itself, never re-confirms or re-analyses it, and never
creates or links an Employer/Client record. You can select **Continue
Without Applying** at any point and complete these fields manually via
**Edit Project** instead.

## Your organisation's role on this project

Tell SureSign how your organisation is acting on this project. This can
differ between projects — the same organisation might be the Main Contractor
on one project and a Subcontractor on another, and setting this on one
project never changes how any other project is set up.

This is separate from, and does not affect:

- **Your SureSign account permissions** — your Client/Admin/Super Admin role
  in SureSign is unrelated to your organisation's role on any individual
  project.
- **Contract parties** — the Employer, Main Contractor, and other named
  parties on your actual Contracts remain exactly as entered on each Contract;
  this field never rewrites them.
- **Billing, AI analysis, or commercial calculations** — none of these are
  affected by this field.

It's optional, and left as "Role not set" until you choose one. A future
release may use it to suggest details when setting up a project from an
uploaded contract, but any such suggestion will always require your
confirmation — it will never be set automatically.

## What happens after you save

- The project appears in your project list with the status you chose.
- A project workspace is created with all the standard sections (Overview,
  Contracts, Commercial, Variations, Notices, Programme, Delay & EOT, Risk
  Register, RFIs, Meetings, QA Reports, Snagging, Site Reports, Delivery
  Documents, Closeout, Documents, Calendar, and Adjudication if enabled).
- The project appears in relevant dashboard tiles (Active Projects) for users
  in your organisation.

## Editing a project

Select **Edit Project** on the project's **Overview** page (next to the
status badge) to complete or change everything not collected at creation:

- Project Name and Project Number / Code.
- Your organisation's role on this project — changeable at any time, or
  clearable back to "Role not set"; changing it only updates this project's
  own record, and never rewrites Contract parties or any other project's role.
- A short **Description**.
- **Contract Type** (JCT, NEC3, NEC4, FIDIC, Bespoke, or Other), **Contract
  Value**, and **Currency** (defaults to your organisation's currency unless
  you choose an explicit override).
- **Status** — Active, On Hold, Completed, or Cancelled.
- **Start Date** and **Completion Date**.
- **Project Location** — Address, City, State/Region, Postcode/ZIP, Country,
  and geographic coordinates (Latitude/Longitude). Coordinates can be added,
  changed, or cleared entirely (clear both fields to remove the project from
  the Dashboard's [Project Map](../dashboard/overview.md)).

Changes are visible on the Dashboard immediately, without needing to refresh
the page.

## Site Location

When a project has coordinates, its **Overview** page shows a **Site
Location** section — a small embedded map centred on the project, alongside
its recorded address and coordinates. This is separate from the Dashboard's
organisation-wide Project Map: Site Location is about understanding one
project's own surroundings (access roads, neighbouring buildings, general
site context) as you start or deliver it.

From Site Location you can:

- **Open in Google Maps** — opens the exact project coordinates in Google
  Maps in a new tab, so you can inspect satellite imagery, Street View, or
  directions using Google's own tools. This only sends the project's
  coordinates to Google when you click it — nothing happens automatically
  when you view the page, and no Google account or API key is required.
- **Copy coordinates** — copies the latitude/longitude to your clipboard.

If no coordinates have been added yet, Site Location shows an empty state
with a shortcut into **Edit Project** (if you have permission) rather than
an empty map.

Coordinates and the recorded address are independent — SureSign does not
look up or correct one from the other, so double-check both are accurate for
a new project.

## Common mistakes to avoid

- Skipping the project code if your organisation relies on codes to tell similar
  project names apart in lists and documents.
- Entering a completion date earlier than the start date in Edit Project —
  double-check both dates before saving.

## What to do next

- If you skipped Project Setup, [upload the contract](../contracts/uploading-a-contract.md)
  whenever you're ready.
- Review [Project Navigation](project-navigation.md) to see the full workspace.
