# Creating a Project

## Who can do this

Administrator accounts.

## Where to find it

Tenant workspace: **Projects** → **New Project**.

## Before you begin

Have ready:

- Project name and, optionally, a project number/code.
- The likely contract type (JCT, NEC3, NEC4, FIDIC, Bespoke, or Other) and type
  of work (New Build, Refurbishment, Fitout, Infrastructure, Maintenance, or
  Other).
- How your organisation is acting on this project — see **Your organisation's
  role on this project**, below.
- An estimated contract value, if known.
- Expected start and completion dates.

## How to create a project

1. Select **New Project**.
2. Enter the **Project Name** (required).
3. Optionally enter a **Project Number / Code**.
4. Choose a **Contract Type** and **Type of Work** from the dropdowns, if known.
5. Optionally choose **Your organisation's role on this project** — Main /
   General Contractor, Subcontractor / Specialist Contractor, Employer /
   Owner, Consultant, or Other. This describes how your organisation is
   acting on *this* project specifically, and can differ between projects —
   see **Your organisation's role on this project**, below.
6. Enter a **Contract Value**, if known.
7. Choose a **Status** — Active, On Hold, Completed, or Cancelled (defaults to
   Active).
8. Enter **Start Date** and **Completion Date**, if known.
9. Optionally fill in **Project Location** — Address, City, State/Region,
   Postcode/ZIP, Country, and geographic coordinates (Latitude/Longitude).
   Coordinates are only used to position this project on the organisation
   [Dashboard's Project Map](../dashboard/overview.md) — SureSign never looks
   up or guesses coordinates from an address, so enter both, or leave both
   blank.
10. Add a short **Description**.
11. Select **Create Project**.

Only the project name is required — everything else can be added later. Name,
project number/code, your organisation's role on this project, and Project
Location (address and coordinates) can be changed afterwards via **Edit
Project** (see below); other fields set at creation cannot yet be changed
in-app.

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
status badge) to update the project name, project number/code, your
organisation's role on this project, or Project Location — address, city,
state/region, postcode/ZIP, country, and coordinates. Coordinates can be
added, changed, or cleared entirely (clear both fields to remove the project
from the Dashboard's [Project Map](../dashboard/overview.md)). Your
organisation's role on this project can likewise be changed at any time, or
cleared back to "Role not set" — changing it only updates this project's own
record; it never rewrites Contract parties or any other project's role.
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
- Setting a completion date earlier than the start date — check both dates
  before saving.

## What to do next

- [Upload the contract](../contracts/uploading-a-contract.md).
- Review [Project Navigation](project-navigation.md) to see the full workspace.
