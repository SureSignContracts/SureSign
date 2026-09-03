# Plant & Equipment

## What this is

Plant & Equipment records the plant and equipment items used on a
project, and each item's site presence — the dated periods during
which it is physically on site. These are deliberately two separate
facts:

- A **Plant Item** is a piece of plant or equipment itself (a tower
  crane, a telehandler, a generator) — its identity, register status,
  and evidence.
- A **Deployment** is one continuous period that item was physically on
  site — its dated presence.

An item's register status (Active/Inactive) never determines whether it
appears as "on site" — only its own dated deployment periods do.

## Who can use it

Any authenticated user with access to the project — including the Client
role, not just Admin/Super Admin — can record Plant & Equipment.

## Where to find it

Project → **Health & Safety → Plant & Equipment**.

## What you can record on a Plant Item

- Name
- Type
- Identifier (optional — a plant number, registration, or asset tag)
- Owner / Supplier (optional)
- Status — Active or Inactive (a register/operational state, not
  presence)
- Evidence — photographs or similar

## What you can record on a Deployment

- On site from (required)
- Off site at (optional — leave blank while the item is currently on
  site)
- Notes (optional)

A Plant Item can have any number of deployment periods over time, and
more than one non-overlapping period in the same reporting week is
fully supported (e.g. on site Monday–Tuesday, off site, back on site
Thursday–Friday) — each period is recorded and shown separately.

## Overlapping periods are not allowed

SureSign will not let you record two overlapping site-presence periods
for the same Plant Item — including an already-open (no "off site"
date yet) period overlapping a newly proposed one. This keeps a single
item's presence history unambiguous.

## Deleting a Plant Item

A Plant Item with a currently open deployment (on site, no "off site"
date recorded) cannot be deleted — close its deployment first. A Plant
Item's historical, already-closed deployment periods remain on record
even after the item itself is deleted.

## What this is not

Plant & Equipment does not itself track statutory plant inspections,
thorough examinations, or certification — that is recorded separately
in [Statutory Inspections](../statutory-inspections/overview.md), which
can optionally link to a Plant & Equipment item. Recording a Plant Item
and its site presence here makes no statement about its inspection or
certification status, and a plant/equipment item's presence in a
reporting week never requires or implies a Statutory Inspection record.

## Friday Packs

Plant & Equipment feeds the Friday Pack's Plant & Equipment section
automatically — every Plant Item with a deployment period touching a
Friday Pack's Monday to Friday reporting week appears there, showing
each distinct presence period for that week. Presence alone is
sufficient to appear — no inspection record is required. See
[Friday Packs](../friday-packs/overview.md).
