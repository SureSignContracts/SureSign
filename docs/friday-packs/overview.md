# Friday Packs

## What this is

A Friday Pack is a weekly site progress, workforce, and health & safety
report for a project — the same kind of document a site works to,
familiar to anyone who has prepared or received a weekly site report on
a real construction project. It is a frozen snapshot: a single record
of what the project's site data looked like for a given reporting week,
week commencing Monday and week ending on the Friday. You can generate
one manually at any time, or opt a project into automatic weekly Draft
generation.

Once generated, a Friday Pack does not change even if the underlying
project data changes later. A Draft can be refreshed with current data
by regenerating it; once approved, a Friday Pack's content is locked
permanently.

Friday Pack content is being built out in stages — see "What a Friday
Pack includes" below for which sections currently collect real data
versus which are still awaiting their own dedicated implementation.

## Who can use it

Any authenticated user with access to the project — including the Client
role, not just Admin/Super Admin — can generate, review, and approve
Friday Packs.

## Where to find it

Project → **Friday Packs**.

## Generating a Friday Pack

1. Open Friday Packs and select **Generate Friday Pack**.
2. Choose the week ending date — it must be a Friday. The reporting
   period (Monday through the selected Friday) is shown before you
   confirm.
3. Generate. A draft is created immediately, using the project's current
   data at that moment.

Generating again for the same week while it's still a draft refreshes
the snapshot to current data — it never creates a second record for the
same week.

## What a Friday Pack includes

Which sections are included is configured per project in **Friday Pack
Settings** (Enabled + Included Sections). The available sections mirror
a real weekly site report:

- Report Information
- Weekly Summary
- Workforce on Site
- Site Photographs
- RAMS in Use
- Toolbox Talks / Briefings
- Site Inductions
- Accidents / Incidents / Near Misses
- H&S Inspections
- Permits to Work & Statutory Inspections
- Plant & Equipment on Site
- Materials Delivered
- Site Issues, Delays & Risks
- Look Ahead: Next Week's Programme
- Sign Off

Every section listed above now currently collects real data — Toolbox
Talks / Briefings, Site Photographs, Weekly Summary, Workforce on Site,
Report Information, Materials Delivered, Site Issues/Delays/Risks, Look
Ahead, Sign Off, RAMS, Permits, Site Inductions, Accidents / Incidents /
Near Misses, H&S Inspections, Plant & Equipment, and Statutory
Inspections (see below) — nothing here is invented or guessed on your
behalf.

## H&S Inspections

H&S Inspections are managed in their own dedicated **Health & Safety →
H&S Inspections** page — a separate record from QA Reports and from the
Contract Risk Register, even though all three involve "inspecting"
something. Each record has its own inspection date, inspection type
(free text, e.g. "General H&S Inspection", "Working at Height
Inspection"), who carried it out, findings, and any follow-up actions.

**Outcome** (*Satisfactory* or *Issues Found*) and **Status** (*Open* or
*Closed*) are always shown and tracked separately — an inspection can
find issues and still be marked closed once the follow-up is dealt with,
or be satisfactory and remain open pending sign-off. SureSign never
derives one from the other, and a *Satisfactory* outcome is never
presented as a statement about the whole site's overall compliance —
it describes only that specific inspection.

The person who carried out the inspection is recorded as free text —
they don't need a SureSign account, since a real inspector may be an
external H&S consultant, principal contractor personnel, or another
competent person.

Each inspection can optionally carry evidence, the same way you already
attach evidence elsewhere in SureSign.

The Friday Pack lists every inspection whose date falls within the
reporting week (Monday through Friday). If no inspections were recorded
for the week, the section shows that plainly — it never states or
implies that no inspections were needed or that the site is compliant.

## Accidents / Incidents / Near Misses

Incidents are managed in their own dedicated **Health & Safety →
Incidents** page. Each record represents one independently recorded
safety event — an accident, incident, or near miss — with its own date/
time, a short title, a fuller description, an optional location, and
your own manual assessment of injury and regulatory reportability.

**Injury** has three states: *Not confirmed*, *No injury*, and *Injury
occurred* — SureSign never assumes "not confirmed" means no injury
happened.

**Regulatory reportability** (*Unknown*, *Not reportable*, *Reportable*)
is always your own manual classification. SureSign does not determine
legal reportability, and selecting "Reportable" does not mean a
regulator has actually been notified — those remain two separate facts.

Incidents are a privacy-minimal record — SureSign does not capture
injured-person identities, worker profiles, or medical details, and
there is currently no evidence/attachment capability for incidents.

The Friday Pack lists every incident whose date/time falls within the
reporting week (Monday through Friday, in your organisation's own
timezone) — a short summary only, never the fuller description you
recorded. If no incidents were recorded for the week, the section shows
that plainly — it never states or implies that no incidents occurred.

## Site Inductions

Site Inductions are managed in their own dedicated **Health & Safety →
Site Inductions** page. Each record represents **one induction session**
— a date, an optional session title (e.g. "Morning Site Induction"), an
optional company/trade, and the number of people inducted in that
session. If two separate sessions happen on the same day, record two
entries; SureSign does not record the identity of individual attendees,
only session-level headcounts.

Each session can optionally carry evidence — a sign-in sheet photo or
similar — the same way you already attach evidence elsewhere in
SureSign.

The Friday Pack lists every induction session recorded for the reporting
week (Monday through Friday) and shows a **total inducted** figure — this
is the sum of that week's session headcounts, not a count of unique
individuals (the same person attending more than one session in a week
would be counted each time). If no induction sessions were recorded for
the week, the section shows that plainly — it never states or implies
that no inductions occurred.

## Plant & Equipment

Plant & Equipment is managed in its own dedicated **Health & Safety →
Plant & Equipment** page. A **Plant Item** (a tower crane, telehandler,
generator, etc.) is a separate record from its **site presence** — each
item can have any number of dated deployment periods recording when it
was actually on site, and a period can still be open (no "off site"
date yet recorded).

The Friday Pack lists every Plant Item with at least one deployment
period that touches the reporting week (Monday through Friday), and
shows each distinct presence period for that week separately — two
non-overlapping periods for the same item in the same week (e.g. on
site Monday–Tuesday, then again Thursday–Friday) are never merged into
one continuous claim. Presence alone is enough for an item to appear
here; no Statutory Inspection or certification record is required —
Statutory Inspections (see below) is a genuinely separate module, and
recording plant presence never requires or implies an inspection.
An item's register status (Active/Inactive) never affects whether it
appears — only its own dated deployment periods do. If no plant or
equipment was recorded as on site for the week, the section shows that
plainly — it never states or implies the site was plant-free.

## RAMS

RAMS entries are sourced from your existing **Delivery Documents** register
— specifically, any record you've categorised as RAMS there. There is no
separate place to manage RAMS for Friday Packs; add, review, and approve
them the same way you already do in Delivery Documents, and they appear
here automatically for the reporting week they're relevant to.

Each entry shows its recorded title, revision, status, whether it was
submitted or approved during the reporting week, and its expiry date where
one is recorded. A RAMS entry is only ever shown as **current** when its
own recorded status is Approved and it hasn't passed its recorded expiry
date — SureSign never makes a compliance judgement beyond what you've
actually recorded. If no RAMS records exist for your project, this section
shows that plainly rather than implying everything is in order.

## Permits / Statutory Inspections

Permit entries work exactly like RAMS above, sourced from your existing
Delivery Documents register (records categorised as Permit). Status,
activity this week, and expiry are shown factually, using only what's
actually recorded — never a "valid permit" claim SureSign can't stand
behind.

Statutory Inspections are managed in their own dedicated **Health &
Safety → Statutory Inspections** page. Each record is an inspection or
check EVENT — deliberately separate from Delivery Documents (compliance
document metadata), H&S Inspections (general inspection activity), and
Plant & Equipment deployments (physical site presence). A Statutory
Inspection may optionally link to a Plant & Equipment item, or stand
alone for a non-plant subject (e.g. a scaffold or temporary works
inspection) — the recorded **Subject Description** always identifies
what was inspected, whether or not a Plant & Equipment link is present.

The Friday Pack lists every Statutory Inspection whose date falls
within the reporting week (Monday through Friday), showing its
inspection type, subject, linked plant (where applicable, frozen as it
was at generation time), reference, outcome, status, and next due date
exactly as recorded. **Outcome** and **Status** are always shown
separately, and neither is ever collapsed into a legal-compliance
conclusion — a Satisfactory outcome never means certified, legally
compliant, or safe for all purposes; Issues Found never means legally
prohibited from use. **Next Due Date** is always a plain recorded
date — SureSign never calculates statutory inspection frequency or an
"overdue" state from it. If no Statutory Inspection records exist for
the week, the section shows that plainly — it never implies none were
required.

## Report Information

Report Information is frozen at the moment a Friday Pack is generated —
later edits to the Project, Organisation, or Contract never change an
already-generated pack's Report Information.

- **Project, Site Address, Week Commencing/Ending, Prepared By** are
  filled in automatically from your Project and this pack's own reporting
  period.
- **Report No.** is a stable, sequential number allocated once when a
  Friday Pack is first generated for a project — it stays the same
  through every later regeneration, review, approval, and send.
- **Principal Contractor** and **Sub-Contract Order No.** are only shown
  when SureSign can identify them without any doubt — Principal
  Contractor from a confirmed AI Contract Analysis result, Sub-Contract
  Order No. from a single, unambiguous subcontract Contract's reference
  number. If more than one contract could apply, or none does, the field
  shows **Not recorded** rather than guessing.
- **Scope of Works** currently always shows **Not recorded** — no single
  existing field reliably represents this yet; a future update may
  address it.
- **Reporting Organisation** is labelled **Sub-Contractor** only when your
  Project is explicitly set to that role; otherwise it's labelled
  **Reporting Organisation**.
- **Distributed To** shows your currently configured Friday Pack Settings
  recipients labelled "Intended — not yet sent" until the pack is
  actually sent, after which it shows the real historical delivery record
  and never reverts to the settings view.

## Materials Delivered

Materials Delivered lists each Site Report's own **Materials Delivered**
text for the reporting week, by day — exactly as recorded, with nothing
added or interpreted from it.

## Site Issues, Delays & Risks

Source material is drawn from your Site Reports' **Issues / Delays**
entries for the week, plus any Delay Events that occurred that same week
(shown for reference only). This section is never your full Contract Risk
Register or your full Delay Event history. You write and confirm the
final text yourself.

## Look Ahead — Next Week

Source material is a short list of programme milestones due in the
calendar week immediately following this pack's own reporting week — not
your whole programme. You write and confirm the final text yourself.

## Sign Off

Sign Off shows who generated, reviewed, approved, and sent the pack, and
when — this reflects SureSign's workflow approval process. SureSign does
not offer, and this is never presented as, an electronic/digital
signature.

## Weekly Summary

Weekly Summary is built from your Site Reports' **Works Carried Out**
entries for the reporting week — SureSign never writes this text for
you. When you open a Draft Friday Pack, you'll see each day's recorded
works listed as reference material (only days with something actually
recorded are shown). You then write and confirm your own Final Weekly
Summary text in a separate box below — the reference material is never
copied in automatically, and your confirmed text is preserved even if
you regenerate the Draft afterwards.

## Workforce on Site

Workforce on Site is built from your Site Reports' existing **Workers on
site** figures for the reporting week (Monday through Friday) — this
remains the authoritative daily headcount, exactly as it always has
been. Optionally, you can also record an approximate breakdown by trade
or role directly on a Site Report (e.g. "Labourer — 4", "Electrician —
2") — this breakdown is supplementary detail, shown alongside the
recorded total, never a replacement for it. If a day has more than one
Site Report with a different recorded total, or more than one
conflicting breakdown, the Friday Pack shows this honestly rather than
guessing which figure is correct.

## Site Photographs

Site Reports (and Toolbox Talks) can now carry photo attachments — open
a Site Report and use **Photos & evidence** to upload them, the same way
you already attach evidence elsewhere in SureSign.

When you open a Draft Friday Pack, SureSign automatically finds every
photograph from Site Reports and Toolbox Talks whose date falls within
the reporting week — you never have to search for them or upload them a
second time. Finding a photo never adds it to the pack automatically:
you choose exactly which ones to include by selecting **Include** on
each one you want.

For every photo you include, you can:

- Write a **Caption** and **Location** specific to this Friday Pack —
  editing these never changes the original Site Report or Toolbox Talk
  photo.
- Reorder the selected photos.
- Remove a photo from the pack at any time while it's still a Draft.

Once selected, a photo is protected — its underlying attachment can't be
deleted from the Site Report or Toolbox Talk it came from until you
remove it from the Friday Pack first. Photo selection, captions,
locations, and order can only be changed while the pack is a Draft;
once it moves to Ready for Review, they're locked in place exactly like
every other report-visible content, and become permanent once the pack
is approved.

If no eligible photographs are found for the reporting week, the Friday
Pack shows a message explaining that, along with a pointer to add
photographs via the relevant Site Report.

Executive Summary, Progress Commentary, Key Concerns, and Next Week
Priorities (the manual commentary fields you write yourself on the pack
itself) are unaffected by this section list and remain fully available
— you can edit them while the pack is a draft.

Progress, Programme, Risks, RFIs, Variations, Commercial, Delays / EOT,
Meetings / Actions, Delivery Documents, Drawings, QA / Snagging, and
Upcoming Actions are no longer part of the Friday Pack — that broader,
cross-module project reporting is being considered separately as a
possible future Weekly Project Report, distinct from Friday Packs.

## Automatic Draft generation

You can optionally have SureSign generate a Friday Pack Draft
automatically each week, so there's always a fresh starting point
waiting for you to review — without anyone having to remember to click
Generate.

- **Off by default.** A project only generates automatically once you
  explicitly turn it on in Friday Pack Settings.
- **Always Friday.** Automatic generation always happens on a Friday, in
  your organisation's own timezone, at a time you choose (to the nearest
  hour).
- **Always a plain Draft.** An automatically generated pack is never
  submitted for review, reviewed, approved, or sent on your behalf, and
  no PDF or email is generated for it. It's exactly the same as if you'd
  clicked Generate yourself — a human still reviews and approves it.
- **Never overwrites an existing pack.** If a Friday Pack already exists
  for that week — whichever way it was created, and regardless of its
  status — automatic generation leaves it completely alone. It will
  never regenerate, refresh, or touch an existing pack's snapshot,
  commentary, or PDF.
- **No catch-up.** If SureSign wasn't available at your configured time
  on the Friday itself, automatic generation for that week is simply
  skipped — it won't generate a late one on Saturday, since that would
  no longer reflect the actual Friday position. You can always generate
  it manually instead.

To turn this on, open Friday Pack Settings and enable **Automatic Draft
Generation**, then choose a generation time.

## Content readiness before review

Before a Draft can be submitted for review, SureSign checks that it is
genuinely complete — every section either has real recorded content, or
an explicit decision from you about why it doesn't. This is a
**completeness check, not a legal or safety compliance assessment** —
SureSign never scores, grades, or certifies a Friday Pack; it only
confirms that nothing has been silently left blank.

A pack that still needs attention shows **Needs attention** with a
clear list of what's outstanding. A pack that has everything it needs
shows **Ready for review**.

**No section is ever assumed empty just because SureSign has no
records.** No incident records doesn't mean no incidents happened; no
plant deployment records doesn't mean no plant was used. Where a
section genuinely has nothing to report, you make that explicit
yourself, choosing the statement that matches reality:

- **Confirm none to report** — "this applied to the reporting period,
  and I'm confirming there was nothing to report" (e.g. "No incidents to
  report for this reporting period.").
- **Mark not applicable** — "this doesn't apply to this reporting
  period or project" (e.g. "No permit requirement applied during this
  reporting period.").

Each declaration is attributed — who confirmed it, and when. You always
see the exact statement you're confirming before you confirm it; there
is no generic "None"/"N/A" button.

**Real records always win over a declaration.** If you confirm "no
incidents to report" and later add a real incident record, regenerating
the Draft automatically supersedes that declaration — SureSign will
never show a real incident record next to a "no incidents to report"
statement. The declaration itself isn't deleted (it stays on record as
history), but it no longer applies, and the section will need your
attention again.

**Weekly Summary and Look Ahead always require your own written text**
— there is no "none to report" shortcut for these, since they are your
own confirmed narrative for the week, not a record count.

**Permits and Statutory Inspections are completed independently** —
confirming one never satisfies the other, even though they share one
section on the page.

Declarations can only be made while a pack is still a **Draft** —
exactly like editing the Weekly Summary or any other manual text. Once
submitted for review, the pack's content (including its declarations)
is locked, matching every other Friday Pack content rule.

Scheduled (automatic) Draft generation never makes a declaration on
your behalf — a scheduled Draft may be incomplete, and stays that way
until you review and complete it yourself.

## Review and approval

A Friday Pack moves through a simple lifecycle:

**Draft** → **Submit for Review** → **Ready for Review** → **Mark
Reviewed** → **Approve** → **Approved**.

- **Submit for Review** — once a draft is complete (see **Content
  readiness before review** above), submit it for review. The report
  content is now under review and can no longer be edited or
  regenerated; if corrections are needed, use **Return to Draft**.
- **Mark Reviewed** — records who reviewed the pack and when.
- **Approve** — available only once a pack has been reviewed. Approving
  permanently locks the pack's snapshot and commentary — nothing about
  the reported content can change afterwards.
- **Return to Draft** — available any time before approval, to make
  corrections. This clears any review record and returns the pack to an
  editable Draft.

The same person may review and approve their own Friday Pack — SureSign
does not currently enforce separate reviewer/approver roles.

Every step is recorded — the pack's detail page shows who generated,
reviewed, and approved it, and when.

## Generating a PDF

You can generate a branded PDF at any stage — open the pack and select
**Generate PDF**. The PDF is built entirely from the pack's own frozen
snapshot, saved commentary, and current review/approval status, using
your organisation's existing SureSign branding, and is stored in the
project's document system like any other generated document.

If a PDF already exists for the pack, you'll see **Download PDF** and
**Regenerate PDF** instead.

The PDF is a properly laid out construction-style report — organisation
letterhead, a Report Information panel, one Workforce table, your
selected site photographs, a grouped Health & Safety section, and a
Sign Off page — following the same section numbering and content shown
on the pack's own detail page, including the full site/H&S structure
described above. A Draft PDF is clearly marked as not yet approved. Any
section with no real data recorded for the week, but with an explicit
declaration made against it (see **Weekly declarations** above), shows
that declaration and who made it, rather than an empty gap; a section
with neither real data nor a declaration shows an honest "not yet
completed" state instead.

**Any change to the pack's report-visible content or lifecycle status
makes an existing PDF out of date** — regenerating a Draft, editing its
commentary, submitting for review, marking reviewed, returning to draft,
or approving all clear the current PDF. You'll need to select Generate
PDF again to produce an up-to-date one — the previous PDF is kept as a
historical record, not deleted, but is no longer presented as current.
Changing Friday Pack Settings alone does not affect an already-generated
pack's PDF.

The PDF clearly shows the pack's current status (Draft, Ready for
Review, or Approved) and, once available, who reviewed and approved it.

## Sending an approved pack

Once a Friday Pack is **Approved** and has a current PDF, you can
explicitly send it to your configured delivery recipients. Because of
the temporary PDF limitation noted above, this is not currently
reachable for a newly generated pack — it remains fully available for
any pack whose PDF was generated before that limitation began.

- **Recipients** are configured per project in Friday Pack Settings —
  each recipient has a required email address and an optional name.
  Recipients are a delivery setting, not part of the report itself.
- **Send is explicit and one-time.** Nothing is ever sent automatically —
  not scheduled generation, not submitting for review, not marking
  reviewed, and not approving. You must open the pack and select
  **Send**.
- **Each recipient gets their own secure link**, delivered by email, to
  download the PDF directly — no SureSign account is required, and the
  PDF is never attached to the email itself. The link expires after a
  period of time (7 days by default).
- **A recipient who has already received the pack is never sent it
  again.** If a recipient's delivery fails (e.g. an invalid address),
  you can use **Retry Failed** to re-attempt only that recipient —
  successful recipients are left untouched.
- **Once Send has been used, the PDF is locked** — it can no longer be
  regenerated, so the document every recipient's link points to never
  changes underneath them.
- The pack moves to **Sent** only once every recipient has been
  successfully delivered. If any recipient is still pending or failed,
  the pack stays **Approved** so it's clear delivery isn't complete yet.

## Statuses

Draft, Ready for Review, Approved, Sent.

## What this is not

Every PDF, review step, approval, and send today is still an explicit,
manual action — automation only ever covers the initial Draft, and even
delivery requires you to select Send yourself.
