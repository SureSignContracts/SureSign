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
- An estimated contract value, if known.
- Expected start and completion dates.

## How to create a project

1. Select **New Project**.
2. Enter the **Project Name** (required).
3. Optionally enter a **Project Number / Code**.
4. Choose a **Contract Type** and **Type of Work** from the dropdowns, if known.
5. Enter a **Contract Value**, if known.
6. Choose a **Status** — Active, On Hold, Completed, or Cancelled (defaults to
   Active).
7. Enter **Start Date** and **Completion Date**, if known.
8. Optionally fill in **Project Location** — Address, City, State/Region,
   Postcode/ZIP, Country, and geographic coordinates (Latitude/Longitude).
   Coordinates are only used to position this project on the organisation
   [Dashboard's Project Map](../dashboard/overview.md) — SureSign never looks
   up or guesses coordinates from an address, so enter both, or leave both
   blank.
9. Add a short **Description**.
10. Select **Create Project**.

Only the project name is required — everything else can be added later. Name,
project number/code, and Project Location (address and coordinates) can be
changed afterwards via **Edit Project** (see below); other fields set at
creation cannot yet be changed in-app.

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
status badge) to update the project name, project number/code, or Project
Location — address, city, state/region, postcode/ZIP, country, and
coordinates. Coordinates can be added, changed, or cleared entirely (clear
both fields to remove the project from the Dashboard's
[Project Map](../dashboard/overview.md)). Changes are visible on the
Dashboard immediately, without needing to refresh the page.

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
