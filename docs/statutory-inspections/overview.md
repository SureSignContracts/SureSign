# Statutory Inspections

## What this is

Statutory Inspections records statutory inspection and check EVENTS
carried out on a project — a record that is deliberately separate from
Delivery Documents (compliance document metadata, such as a permit or
RAMS record), H&S Inspections (general health & safety inspection
activity), and Plant & Equipment deployments (physical site presence),
even though all four can look similar at a glance. Each Statutory
Inspection record is the fact that an inspection or check actually took
place, on a given date, with a recorded outcome.

## Who can use it

Any authenticated user with access to the project — including the Client
role, not just Admin/Super Admin — can record Statutory Inspections.

## Where to find it

Project → **Health & Safety → Statutory Inspections**.

## Plant-linked and non-plant subjects

A Statutory Inspection can optionally link to a **Plant & Equipment**
item (e.g. a Tower Crane's lifting equipment inspection), or stand alone
for a non-plant subject (e.g. a scaffold inspection, a temporary works
inspection, or another site statutory check). SureSign never creates a
fake Plant & Equipment record for a non-plant subject — the **Subject
Description** field always identifies what was inspected, whether or
not a Plant & Equipment link is also present.

Even when a Plant & Equipment link is supplied, the Subject Description
remains its own explicit, independently recorded description — useful
because the linked item may later be renamed or removed from the
register; the historical record of what was inspected is never lost.

## What you can record

- Inspection Date (required — the date the inspection/check actually
  occurred)
- Inspection Type (required free text — e.g. Lifting Equipment
  Inspection, Scaffold Inspection, Temporary Works Inspection)
- Subject Description (required — identifies what was inspected)
- Plant / Equipment (optional link to an existing Plant & Equipment
  item)
- Reference (optional — a certificate or internal reference number)
- Outcome — Satisfactory or Issues Found
- Status — Open or Closed
- Notes (optional)
- Next Due Date (optional)
- Evidence — a certificate, check sheet, or photograph

## Outcome and status are independent

**Outcome** describes what the inspection found. **Status** describes
whether the record's own follow-up is still open. These are two
separate facts, always shown and tracked separately — an inspection can
find issues and still be marked **Closed** once the follow-up has been
dealt with, and an inspection can be **Satisfactory** and remain
**Open** pending sign-off. SureSign never derives one from the other.

## No automatic compliance conclusion

A recorded outcome of **Satisfactory** does not automatically mean the
equipment or site is legally compliant, certified, safe for all
purposes, or compliant with every statutory obligation. Likewise,
**Issues Found** does not automatically mean the equipment or site is
legally prohibited from use. SureSign presents the recorded facts only.

## Next Due Date is a recorded fact, not a calculation

**Next Due Date** is an explicitly recorded date only — SureSign never
calculates statutory inspection frequency (for example under LOLER,
PUWER, or any scaffold or jurisdiction-specific rule) automatically, and
never derives an "overdue" or "non-compliant" state from it. Where
shown, it is always presented as a plain recorded date.

## What this is not

Statutory Inspections is not a Delivery Document, an H&S Inspection, or
a Plant & Equipment deployment — those remain separate modules with
their own separate records. A Delivery Document (including a permit,
RAMS, or temporary works record) never creates a Statutory Inspection
record, and a Plant & Equipment deployment or evidence attachment never
creates one either.

## Friday Packs

Statutory Inspections feeds the Friday Pack's Permits / Statutory
Inspections section automatically — every inspection recorded within a
Friday Pack's Monday to Friday reporting week appears there, with
outcome, status, and (where linked) the plant item's name and
identifier shown exactly as they were at the time the pack was
generated. See [Friday Packs](../friday-packs/overview.md).
