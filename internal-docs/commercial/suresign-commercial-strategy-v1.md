# SureSign Commercial Strategy v1

**Status: approved in principle by the founder (2026-07-23). This document
is the business and commercial foundation the Subscription & Billing
architecture must be built against. Figures marked "recommendation" are
not approved prices or limits — they require explicit founder sign-off
before use in any live plan, contract, or customer-facing material (see
Section 21, Open Founder Decisions).**

This document is business- and product-facing. It is written for a
founder, a sales operator, a product manager, or a future commercial hire
— not for engineers implementing billing code. The companion document,
[SureSign Entitlement Specification v1](suresign-entitlement-specification-v1.md),
translates the decisions here into a technical model.

---

## 1. Executive Summary

SureSign sells **relief from the personal and organisational risk of
getting UK construction contract administration wrong** — specifically,
statutory payment deadlines, notices, variations, and the commercial
record a business needs if a dispute or adjudication ever happens. It is
not sold as project management software, team collaboration software, or
an AI tool.

The buyer is typically **the one person personally accountable for
contract administration** at a construction business — a contracts
manager, commercial manager, quantity surveyor, or owner-operator —
who buys SureSign to stop relying on Word templates, email threads, and a
personal spreadsheet of dates. They buy it because getting a deadline
wrong has real financial and legal consequences for them personally and
for their employer.

SureSign should be sold **sales-assisted**: a demo, a short qualification
conversation, an organisation created by Super Admin, and a Stripe
Checkout link — never unrestricted public self-service signup at this
stage. This matches both the trust-sensitive nature of a compliance
product and the operational reality of a small company.

The approved plan structure is three plans — **Essential, Professional,
Enterprise** — differentiated by active-project capacity, AI analysis
allowance, storage, reporting, support, and (for Enterprise) fully
negotiated terms. There is deliberately **no Starter plan** and **no named
"Unlimited" plan**.

**User count is explicitly not a pricing dimension today.** The typical
customer has one primary operator; the platform's multi-user and role
architecture exists for other reasons and must not shape current
commercial design. A reserved, dormant `max_users` entitlement key exists
in the technical vocabulary for possible future use, but it is not sold,
not enforced, and not shown to customers today.

Main commercial principles: organisation is the billable entity; Stripe
processes payment but SureSign is the sole source of truth for access,
plan, and entitlements; existing customers are grandfathered on their
agreed terms; Enterprise is a negotiated, sales-assisted release valve, not
a self-service tier; and self-service checkout is a deliberate future
phase, not a current gap.

---

## 2. Product Positioning

### What SureSign actually sells

SureSign sells:

- **Contractual correctness** — statutory payment dates (due date, final
  date, pay-less notice deadline) calculated correctly from the contract's
  own rules, not worked out by hand.
- **Commercial control** — a single place where payment applications,
  variations, and notices move through a defined lifecycle instead of
  living across scattered documents and inboxes.
- **Deadline and notice management** — nothing statutory is missed
  because nobody remembered to check a calendar.
- **Defensible records** — a traceable, timestamped, branded document trail
  a business can stand behind if a payment is disputed.
- **Document automation** — properly branded DOCX/PDF/Excel output without
  manually rebuilding templates every time.
- **Contract intelligence** — AI-assisted extraction of key contract terms,
  as a supporting aid to a human, not a replacement for reading the
  contract.
- **Adjudication readiness** — if a dispute reaches adjudication, the
  paper trail already exists in one place, indexed and complete.
- **Reduced personal and organisational risk** — the actual emotional and
  commercial driver behind every one of the above: the buyer's own
  professional exposure goes down.

### What SureSign is deliberately not

SureSign must not be positioned as:

- **Generic project management software** — SureSign does not compete on
  Gantt charts, task boards, or team scheduling.
- **Document storage** — storage is a supporting capability, not the
  product; a customer choosing SureSign for cheap storage is the wrong
  customer.
- **Team chat or collaboration software** — SureSign is not a Slack/Teams
  alternative and should never be pitched alongside one.
- **An AI wrapper** — AI analysis is one supporting feature inside a
  broader compliance workflow (see `CLAUDE.md`'s AI Workflow Context), not
  the headline product.
- **A generic construction SaaS platform** — SureSign's value is specific
  to contract administration under UK construction payment law, not
  construction management broadly (it is not estimating software,
  scheduling software, or site-safety software).

### Internal positioning statement

> SureSign is the contract administration system of record for UK
> construction businesses — it gets statutory payment dates right,
> generates the notices and documents a business needs on time, and keeps
> a defensible record of every commercial decision, so the person
> responsible for getting it right never has to guess.

### Recommended customer-facing positioning statement (for future
marketing/sales use — requires founder and marketing sign-off before
publication)

> Stop tracking payment deadlines in your head and in spreadsheets.
> SureSign calculates your statutory payment dates, generates your
> notices and payment applications on your own branding, and keeps a
> complete, defensible record — so you're never caught out, and never
> caught unprepared.

### Important scope limitation — legal advice

SureSign **assists** customers with contract administration and compliance
processes. It calculates dates and generates documents based on
contract data the customer provides and confirms. **SureSign must never be
described, marketed, or sold as providing legal advice, guaranteeing legal
outcomes, or replacing a solicitor or contract professional.** Any
marketing or sales language implying a legal guarantee (e.g. "SureSign
ensures you win every adjudication," "SureSign guarantees compliance")
must be rejected before publication. AI-assisted contract analysis in
particular must always be presented as requiring human confirmation before
use — which matches the existing product design (an admin must confirm AI
analysis before it's used for payment date calculations).

---

## 3. Ideal Customer Profile

### Likely initial customer profiles

- Small or medium UK construction companies without a dedicated commercial/
  contracts department.
- Principal contractors managing multiple trade packages and subcontractor
  payment cycles.
- Subcontractors who need to issue and respond to payment applications and
  notices correctly, often with less commercial/legal resource than the
  main contractor they work under.
- Owner-operated construction businesses where the owner personally
  handles commercial administration alongside running the business.

### Likely primary operator

Typically **one person**: a contracts manager, commercial manager,
quantity surveyor, or the owner themselves. This person is personally
accountable for the dates, notices, and records SureSign manages — they
are the buyer, the primary user, and usually the sole configured user for
some time after purchase, even though the platform supports more.

### Pain points that make them likely to purchase

- Currently tracking statutory deadlines by memory, a spreadsheet, or a
  paper diary, with no systematic check.
- Currently generating payment applications, variations, and notices from
  ad hoc Word templates, inconsistently branded, hard to reconstruct after
  the fact.
- Have experienced, or are afraid of, a missed pay-less notice deadline or
  a payment dispute where their own records were incomplete or scattered.
- Growing past the point where "I remember what I agreed" is a safe way to
  run commercial administration.
- Preparing for, or currently in, an adjudication or dispute and needing
  their paper trail assembled quickly.

### Customers who may not be a good fit at launch

- Businesses wanting a generic project management or scheduling tool —
  SureSign does not compete there and shouldn't be sold as if it does.
- Businesses whose construction work is entirely outside UK jurisdiction
  (the statutory payment date calculations are UK-specific).
- Very large main contractors expecting a full ERP-level procurement/
  finance system — these are Enterprise-track conversations at best, not
  standard-plan customers, and may exceed what SureSign is built to do at
  launch.
- Businesses primarily seeking an AI-drafting tool rather than a
  contract-administration system — the wrong motivation for buying,
  likely to churn when the AI features don't meet inflated expectations.

---

## 4. Commercial Philosophy

**Approved direction:**

- **Sales-assisted initially.** Every standard customer goes through a
  demo and a short qualification conversation before an organisation is
  created — no public "sign up now" flow.
- **Lightweight sales process for standard (Essential/Professional)
  customers** — a demo call and a checkout link, not a formal procurement
  cycle.
- **A more formal, negotiated process for Enterprise** — commercial terms,
  possibly a signed agreement, a longer sales cycle, matching how larger
  construction businesses actually procure software.
- **No unrestricted public self-service registration.** An organisation is
  always created deliberately by Super Admin, following a real sales
  conversation.
- **Self-service checkout may be introduced later**, once plans, trust
  signals (case studies, track record), onboarding, and support processes
  are proven — this is a deliberate future phase, not a gap in the current
  plan.
- **Both annual and monthly billing may be offered**, with annual pricing
  as a structural discount (see Section 9), not a special negotiated deal.
- **Organisation-based subscription, not per-user pricing** — the billable
  unit is the business, not the number of people using the system.
- **Bundled plan pricing rather than fragmented metered billing** — a
  customer should receive one predictable invoice, not a bill itemising
  every project, notice, or document generated.

### Why this fits the construction contract administration market

Construction businesses buying a compliance-adjacent tool want confidence
that a real company stands behind it — that's inconsistent with an
anonymous self-service signup for a product touching statutory legal
deadlines. The buyer is risk-averse by the nature of their job; a
lightweight but human sales motion builds the trust a card-only checkout
cannot. At the same time, most target customers are small businesses, not
enterprises with procurement departments — so the standard-plan sales
motion must stay lightweight, reserving a heavier, more formal process
only for the minority of larger, negotiated Enterprise accounts.

---

## 5. Plan Structure

**All figures in this section are indicative recommendations only,
requiring founder approval before use in any live pricing page, contract,
or entitlement configuration — see Section 21.**

### Essential

**Intended customer**: a single contracts manager, commercial manager, or
owner-operator running a modest number of live jobs, who needs the core
SureSign workflow (contracts, payment applications, notices, document
generation) without needing heavy AI usage or advanced reporting.

- **Active project allowance** (recommendation, not approved): a modest
  cap sized for a business running a handful of concurrent jobs.
- **AI analysis allowance** (recommendation): a small monthly allowance —
  enough to experience the feature meaningfully, not enough to be a
  primary workflow.
- **Storage allowance**: generous — storage should never be the reason
  Essential feels restrictive, since document retention underpins the
  product's defensibility promise.
- **Branding**: included, not gated. A contracts manager issuing a payment
  notice on their own company's letterhead is core credibility with their
  own subcontractors and employer — not a premium feature.
- **Reporting**: standard, per-project views only.
- **Support**: standard/email support, reasonable response expectations,
  no dedicated named contact.
- **Onboarding**: self-guided with standard sales-assisted setup (Super
  Admin creates the org, customer configures their own branding/first
  project).
- **Integrations**: none currently exist; not a differentiator today.
- **Commercial flexibility**: none beyond the standard plan — Enterprise
  is the path for anything bespoke.

### Professional

**Intended customer**: a business running more concurrent jobs, a busier
commercial operation, or one that wants to lean on AI analysis and
cross-project reporting more heavily. The primary "volume" plan most
customers are expected to land on as they grow.

- **Active project allowance** (recommendation): materially higher than
  Essential, sized generously enough that most Professional customers
  rarely approach it.
- **AI analysis allowance** (recommendation): meaningfully larger — this
  is the tier where AI-assisted contract analysis becomes a routine part
  of onboarding every new contract, not an occasional extra.
- **Storage allowance**: higher again, same rationale as Essential.
- **Branding**: included (as Essential).
- **Reporting**: cross-project reporting — e.g. a consolidated view of
  upcoming statutory deadlines across every live job, not just per-project
  — the natural feature a busier commercial operation needs that a
  single-job Essential customer does not.
- **Support**: priority support — faster response expectations, possibly a
  named point of contact.
- **Onboarding**: same sales-assisted foundation as Essential, with a
  slightly more thorough setup conversation given the larger portfolio.
- **Integrations**: none currently exist; the natural home for future
  accounting-export capability once built (see Section 20).
- **Commercial flexibility**: still standard-plan; anything requiring
  negotiation moves to Enterprise.

### Enterprise

**Intended customer**: a business with a larger project portfolio,
multiple legal entities or subsidiaries, formal procurement requirements,
bespoke support expectations, or any commercial need that doesn't fit a
standard plan.

- **Active project allowance**: negotiated — uncapped or individually
  agreed, never a fixed published number.
- **AI analysis allowance**: negotiated.
- **Storage allowance**: negotiated, generally uncapped in practice.
- **Branding**: included, as standard plans, plus potential bespoke
  requirements (e.g. multiple brand profiles for subsidiaries — a future
  capability, see Section 20).
- **Reporting**: full cross-project/cross-entity reporting where relevant.
- **Support**: negotiated SLA, dedicated account contact.
- **Onboarding**: bespoke, sales-assisted, potentially including a formal
  onboarding plan.
- **Integrations**: the natural home for any bespoke integration request
  (a specific accounting system, a specific procurement requirement) that
  wouldn't be built as a general-availability feature.
- **Commercial flexibility**: this is the entire point of the plan — every
  Enterprise subscription is expected to have some negotiated element,
  tracked via the entitlement override mechanism (see the Entitlement
  Specification).

**Deliberately excluded**: no "Starter" plan below Essential (undersells a
compliance product — implying a cheaper, lesser-assured tier for
statutory deadline management sends the wrong signal) and no named
"Unlimited" plan (uncapped is an Enterprise negotiation outcome, not a
menu item — naming it invites demands for "unlimited" pricing below
Enterprise negotiation).

---

## 6. Commercial Dimensions

The dimensions that currently differentiate plans:

- **Maximum active projects** — the clearest proxy for how much live
  commercial administration work a customer is running; also the natural
  expansion signal (a customer approaching their project cap is winning
  more work, a good moment for an upgrade conversation, not a punishment).
- **AI analyses per billing period** — controls SureSign's own variable
  Anthropic API cost exposure while giving customers a real, usable
  allowance.
- **Storage allowance** — supports the document-retention/defensibility
  value proposition; must stay generous (see below).
- **Reporting capabilities** — cross-project reporting differentiates
  Professional from Essential without touching the product's core
  workflow.
- **Support level** — one of the best places to differentiate without
  touching product functionality at all; response time and dedicated
  contact scale naturally with plan.
- **Onboarding level** — lightweight self-guided for Essential/
  Professional, bespoke for Enterprise.
- **Enterprise services** — negotiated SLAs, dedicated contacts, bespoke
  integration requests.
- **Future accounting or integration capabilities** — reserved as a future
  Professional/Enterprise differentiator once actually built (see Section
  20); not a current dimension since nothing exists yet.

**User count is deliberately not a current commercial dimension** — see
the User Licensing Decision below.

### Why branding is included from Essential, not a high-tier upsell

A contracts manager or subcontractor issuing a payment application or
notice under their own company's branding is a matter of professional
credibility with their own subcontractors, employer, or client — not a
luxury feature. Gating it behind a higher tier would undermine the exact
trust and professionalism SureSign is meant to project on a customer's
behalf, working against the product's own positioning.

### Why storage should remain generous

Document retention is core to SureSign's defensibility promise — "keep a
complete record in case of dispute." A tight storage cap that pressures a
customer to delete or avoid uploading records directly undermines that
promise. Storage is comparatively cheap to provide; the commercial risk of
appearing to force customers to choose between paying more and keeping
their compliance records complete is not worth the marginal revenue.

### Why AI is a usage allowance, not simply enabled/disabled

AI-assisted contract analysis has a real, variable cost (tracked already
via token/cost fields on `contract_ai_analyses`) — a simple on/off flag
gives SureSign no lever against a customer whose usage genuinely exceeds
what their plan price supports. A usage allowance lets AI be a meaningful,
usable feature at every paid tier while keeping SureSign's own cost
exposure bounded and predictable, and creates a natural, non-punitive
signal ("you're using AI heavily — Professional would suit you better")
rather than an abrupt binary feature wall.

---

## 7. Pricing Philosophy

**Recommended philosophy**: organisation-based, bundled plan pricing — not
per-seat, not fragmented usage-metered billing.

- **Organisation-based**: the business is billed, not the number of people
  using the system — consistent with the User Licensing Decision below.
- **Bundled plan pricing**: one predictable price per plan per billing
  interval, not a base fee plus itemised charges per project, notice, or
  document generated.
- **No per-seat pricing today** — see User Licensing Decision.
- **Active projects as the primary value and expansion signal** — the
  dimension most naturally tied to how much value a customer is deriving
  and how much they're growing.
- **AI as a cost-controlled allowance**, not the primary pricing axis — AI
  is a supporting feature of the product, not the core value driver (see
  Section 2); pricing around it as if it were the product would misprice
  the actual value being sold.
- **Storage as a generous allowance**, essentially never the pricing
  lever.
- **Enterprise as the negotiated release valve** for anything that doesn't
  fit — not a fourth standard tier.
- **Annual pricing as a structural pricing option** (a standing discount
  for paying upfront), not a negotiated exception.
- **No fragmented invoice** — a customer should receive one clear
  recurring charge per billing period, not a bill itemising every notice
  generated or every AI analysis run.

### Advantages

- Matches how the target customer actually thinks about cost — a
  predictable monthly/annual line item, not a variable bill they have to
  audit.
- Keeps the sales conversation simple ("which plan fits your workload"
  rather than "let's model your expected usage across five metered
  dimensions").
- Avoids pricing the product as if AI were the core value, which would
  misrepresent what SureSign actually is (see Section 2).

### Risks

- Bundled allowances mean some customers will be "under-utilising" their
  plan (paying for capacity they don't use) while others approach limits —
  this is normal and acceptable for a B2B bundled model, but Super Admin
  should be able to see utilisation to have informed renewal/expansion
  conversations (see Section 16, Customer Success and Health).
- If AI costs grow faster than anticipated relative to what Professional/
  Essential allowances assume, the allowances may need revisiting —
  this is a reason to treat the specific allowance *numbers* as
  provisional (Section 21), not a reason to change the bundled-pricing
  philosophy itself.

**No final prices are proposed in this document.** Pricing itself (the
actual £ figures per plan per interval) is a founder decision requiring
market and cost-modelling input beyond the scope of this commercial
architecture document — see Section 21.

---

## 8. Trials

**Approved initial direction:**

- **Sales-assisted only** — a trial is granted by Super Admin after a demo
  conversation, exactly mirroring how a paid subscription is created.
- **No public free-trial signup.**
- **Recommended duration: 14 days** (recommendation, not final) — long
  enough for a contracts manager to run one real payment-application or
  notice cycle through the system (the moment the value becomes concrete),
  short enough to preserve urgency and avoid becoming a de facto free
  tier.
- **Trial subscriptions use the `trialing` subscription status** — no new
  status concept is needed.
- **No card charge until the agreed trial conversion point** — a trial
  should never silently convert to a paid charge without an explicit
  conversion action.
- **Trial expiration and follow-up require clear operational handling** —
  an expiring or expired trial should surface clearly to Super Admin/sales
  (not silently lapse unnoticed), with a defined follow-up conversation
  before or at expiry.

### What success during a trial should look like

Trial success should be measured by **reaching a real value milestone**,
not simply "logged in" or "days elapsed." Recommended milestones (see also
Section 15, Onboarding Strategy):

- First project created.
- First contract added/analysed.
- First AI analysis completed (if relevant to the customer's interest).
- First payment application created.
- First notice or generated document produced.

A trial customer who reaches at least one of these milestones has
experienced the product's actual value and is a meaningfully different
conversion prospect from one who has only logged in and looked around.
This distinction should inform which trials get a proactive follow-up call
before expiry.

---

## 9. Discounts

### Categories

- **Founding customer discount** — a deliberate, permanent exception for
  an early cohort of customers, granted as a goodwill/marketing decision,
  not a pricing-mechanism default.
- **Temporary promotional discount** — e.g. a launch-period offer, always
  with an explicit start and end date.
- **Annual billing reduction** — a structural part of plan pricing (the
  annual price is simply lower than 12× the monthly price), not an
  override or exception at all.
- **Enterprise negotiated pricing** — whatever is agreed in the negotiated
  contract, tied to that contract's term.
- **Partner or referral discount** — a plausible future need (e.g. an
  accountant or consultant referring customers) — not built today, but
  worth recognising as a distinct discount *reason*, likely recurring
  automatically per customer from a given partner rather than a one-off
  decision each time.
- **Goodwill or retention adjustment** — a one-off credit or discount
  offered to retain or make right with a specific customer (e.g. a service
  issue), tracked with a clear reason.

### Policy

- **Discounts are temporary by default.** Any discount without an
  explicit, deliberate reason to be permanent should have a defined end
  date.
- **Temporary discounts require an explicit start and end date** — no
  open-ended "for now" discounts.
- **Permanent discounts require an explicit reason and approval** —
  founding-customer status being the clearest example; any other
  permanent discount should be treated as unusual and require the same
  scrutiny.
- **Founding customer lifetime discounts are a deliberate, named
  exception** — not a precedent that invites every future customer to
  request the same.
- **Annual pricing is part of plan pricing, not an override** — it should
  never be recorded as a "discount" in the override/exception sense; it's
  simply the annual price.
- **Enterprise discounts should align with the negotiated contract
  period** — a discount tied to a 2-year agreement shouldn't silently
  persist past that agreement's term without a renewal conversation.
- **Every exception must have a human-readable reason and an approval
  trail** — "why does this customer pay less than list price" must always
  have a legible answer, not just a number.

### Source of truth

**Discounts and their reasons should not rely on Stripe coupons/promotion
codes alone as the source of truth.** Stripe coupons are a mechanism for
*applying* a discount to a Stripe invoice, but SureSign needs its own
durable, queryable record of *why* a given subscription has the commercial
terms it has — this is a SureSign-side commercial record first, with
Stripe's coupon mechanism (if used at all) as an implementation detail
underneath it, not the other way around.

---

## 10. Grandfathering

**Approved policy:**

- **Existing standard-plan customers retain their agreed pricing and
  entitlements** until they voluntarily change plan or renegotiate — this
  is unconditional, not case-by-case.
- **New customers receive current pricing** at the time they sign up.
- **Enterprise terms remain valid for their contractual period** — a
  renewal is a fresh negotiation conversation, not an automatic reversion
  to list price or an automatic continuation of old terms either.
- **Customer terms must never change silently.** Any change to a live
  customer's pricing or entitlements — including a *beneficial* one —
  must be a deliberate, communicated action, never a silent side effect of
  a plan-catalogue update.
- **Beneficial changes should still be communicated and recorded.** Even
  "good news" changes (e.g. a plan's allowance increases and an existing
  customer is deliberately given the new, better allowance) should be a
  conscious decision with a record, not an automatic cascade.
- **Deprecated prices must remain identifiable for operator reporting** —
  Super Admin should be able to see which live subscriptions are on an
  old/deprecated price point, particularly ahead of any future price
  increase.

### Why this supports trust and historical snapshots

SureSign's value proposition is trust in getting commitments right — a
customer whose price silently changed without notice would directly
contradict that promise. This policy is also what the technical
architecture's historical-pricing-snapshot design (frozen commercial terms
per subscription, independent of the live pricing catalogue) exists to
support — grandfathering is the commercial policy the snapshot mechanism
was built to serve, not an afterthought bolted onto it.

---

## 11. Upgrades and Downgrades

### Standard upgrades

- **Immediate where practical** — a customer needing more project capacity
  right now (typically because they've just won more work — the best
  possible reason) should get it immediately, not wait for a billing cycle
  boundary.
- **Prorated where appropriate** — the customer pays only the difference
  for the remaining period, following standard proration practice.
- **New entitlements become available immediately after confirmed billing
  state** — not before payment/webhook confirmation, consistent with the
  existing "webhooks are authoritative" billing principle.

### Standard downgrades

- **Scheduled for the next renewal**, not immediate — a downgrade
  mid-cycle must not strand active projects or interrupt live contract
  administration work above the new plan's allowance.
- **Must not strand active projects or interrupt live work** — an active
  project or an in-progress payment application must never become
  inaccessible because of a downgrade taking effect mid-cycle.
- **Require warning where current usage exceeds the future plan's
  allowance** — e.g. a customer with 12 active projects downgrading to a
  plan whose allowance is 8 should see a clear warning before confirming,
  not discover it after the fact.

### Annual customers

- **Upgrades may be immediate and prorated**, same as monthly.
- **Downgrades should involve a retention or account conversation** rather
  than a self-service action — a customer wanting to downgrade an annual
  commitment mid-term is a meaningful signal worth a human conversation,
  consistent with the sales-assisted philosophy.

### Enterprise customers

- **Changes are contractual amendments**, not ordinary plan changes — an
  Enterprise subscription's "upgrade" or "downgrade" is really a
  renegotiation of the underlying agreement, handled entirely through the
  sales/account-management relationship.
- **Must not be treated as ordinary self-service plan changes** — the
  standard upgrade/downgrade policy above does not apply to Enterprise
  accounts.

### Customer self-service

**Customer self-service plan changes are not required initially.** Given
the sales-assisted model, a customer wanting to change plan should contact
SureSign (or be identified proactively via Customer Success signals — see
Section 16), with the actual change made by Super Admin. Building
self-service plan-change UI for the customer is a future capability, not a
current requirement.

---

## 12. VAT and Tax Decisions

**This document does not provide legal or accounting advice. Every item
below marked "requires professional confirmation" must be confirmed with
a qualified accountant before the first live invoice — none of the
following should be treated as settled without that confirmation.**

### Decisions requiring external professional confirmation

- **VAT registration status** — whether the company is (or needs to be)
  VAT-registered at launch, given its expected turnover.
- **UK VAT treatment** — if VAT-registered, confirming the correct
  treatment for SaaS sold to UK VAT-registered business customers
  (generally standard-rated, but must be confirmed for SureSign's specific
  structure).
- **International customer treatment** — SureSign's statutory date
  calculations are UK-specific, so international sales are not expected
  near-term; if they occur, cross-border B2B SaaS VAT/tax treatment needs
  separate confirmation at that time, not assumed now.
- **Whether Stripe Tax should be enabled** — a likely "yes" once VAT
  registration status is settled, but the decision itself depends on that
  prior confirmation.
- **Invoice-required business details** — what legal/company details must
  appear on invoices to be valid (company registration number, VAT
  number if applicable, registered address).
- **Tax registration identifiers** — confirming which identifiers (VAT
  number, company number) must be collected/displayed.
- **Credit-note handling** — how refunds/adjustments should be represented
  for accounting purposes (a credit note versus a simple refund record).
- **Refund treatment** — the accounting and tax treatment of a refund,
  distinct from the billing-system mechanics of processing one.

### Recommendation (pending confirmation)

Enable Stripe Tax once VAT registration status is confirmed, and treat UK
sales as standard-rated pending accountant confirmation. Treat
international sales as out of scope until they actually arise.

### What is already decided (not requiring further confirmation)

- SureSign's billing architecture stores `tax_amount` fields and supports
  Stripe Tax integration structurally — this is a technical readiness fact,
  not a tax-policy decision.
- No live tax charge will occur until the business decisions above are
  confirmed and Stripe is out of Test Mode.

---

## 13. Invoice Numbering

**Recommended direction:**

- **SureSign generates its own human-readable invoice reference**
  (following the `INV-000001`-style sequential format already built as
  part of the billing foundation), rather than relying on Stripe's own
  invoice ID as the customer-facing reference.
- **Stripe invoice IDs remain provider references** — recorded and used
  internally for reconciliation, never presented to a customer as "their"
  invoice number.
- **Internal references should remain stable and traceable** — once
  issued, a reference should never be reused or reassigned.
- **Invoice numbering must be reviewed with accounting requirements before
  live use** — UK accounting practice generally expects invoices to be
  numbered sequentially without gaps; this needs explicit accountant
  confirmation of what "sequential" must mean in practice for SureSign
  (e.g. whether a voided/cancelled invoice's number may be skipped or must
  be reused, which the current mechanism does not yet address).
- **Voiding, refunds, credit notes, and failed payments must not corrupt
  reference history** — a failed payment attempt must not consume or skip
  an invoice number that was never actually issued to a customer.

**No claim is made that the current technical reference-generation
mechanism guarantees legally gapless numbering.** The mechanism (an
atomically-incremented sequence, following the same pattern already used
for document numbers) prevents *duplicate* references and *concurrent*
collisions — it has not yet been reviewed against the specific legal/
accounting definition of "gapless" that UK invoicing practice may require,
particularly around voided or cancelled invoices. **This review must
happen before live invoicing begins**, not assumed complete because the
underlying sequence mechanism exists.

---

## 14. Customer Lifecycle

```
Marketing site
  → Book a Demo
  → Sales qualification (understand their projects/contracts, confirm fit)
  → Product demonstration (the actual payment-application/notice workflow,
     not a generic feature tour)
  → Proposal (plan recommendation — usually Essential or Professional,
     occasionally Enterprise)
  → Commercial agreement (verbal/email agreement for standard plans;
     a more formal process for Enterprise)
  → Internal customer approval or procurement (Enterprise only, where
     applicable)
  → Organisation creation (Super Admin, in SureSign)
  → Optional trial (14 days, trialing status, Super Admin-granted)
  → Plan assignment
  → Checkout session created (Super Admin-initiated)
  → Payment (Stripe Checkout)
  → Verified webhook
  → Subscription activation
  → Onboarding
  → First real workflow  ← deliberate success checkpoint, see below
  → Active usage
  → Renewal
  → Expansion (approaching an allowance → upgrade conversation)
  → Enterprise review (Enterprise accounts only, periodic contract review)
  → Cancellation, expiry, or reactivation (where applicable)
```

### The "first real workflow" checkpoint

This is a deliberate, named checkpoint distinct from "onboarding
completed" — it marks the moment a customer has actually experienced
SureSign's core value, not merely configured the product. Examples:

- First contract analysed (AI or manual).
- First payment application created.
- First notice generated.
- First project deadline actively managed through to completion.

**Why this matters commercially**: a customer who has completed account
setup but never reached a first real workflow is at meaningfully higher
churn risk than one who has — "logged in and configured branding" is not
the same signal as "generated my first real payment application through
the system." This checkpoint should inform proactive customer success
outreach (see Section 16) and should be tracked distinctly from generic
"onboarding completion," which can be satisfied by configuration alone.

---

## 15. Onboarding Strategy

**This section is a business and product concept only — no
implementation, milestone tracking, or status field should be built during
this checkpoint.**

### Recommended onboarding milestone model

- `not_started`
- `organisation_created`
- `branding_configured`
- `first_project_created`
- `first_contract_created`
- `first_ai_analysis_completed`
- `first_payment_application_created`
- `first_notice_generated`
- `onboarding_completed`

### Which milestones are essential versus optional

- **Essential to every customer**: `organisation_created`,
  `first_project_created`, `first_contract_created`. Every customer,
  regardless of plan or use case, needs at least one project and contract
  to derive any value at all.
- **Optional, workflow-dependent**: `first_ai_analysis_completed` (only
  relevant to customers who use AI analysis — not every customer will, and
  its absence should not read as a red flag on its own),
  `first_payment_application_created` and `first_notice_generated` (highly
  relevant, but timing depends on where a customer's projects are in their
  own commercial cycle — a customer might not need to issue a notice in
  their first month simply because none was contractually due yet).
- **`branding_configured`**: recommended as effectively essential in
  practice (given branding's importance to positioning, Section 6), but
  technically optional — a customer could operate without configuring
  custom branding, just with reduced value realised.

### What completion should mean

`onboarding_completed` should represent "this customer has configured the
basics and experienced at least one real commercial workflow," not simply
"every possible milestone ticked." A customer who has created a project,
a contract, and generated their first notice has meaningfully onboarded
even if they've never touched AI analysis — completion should not require
every milestone, only the essential subset plus at least one real
workflow milestone relevant to their actual use case.

### Milestones that apply only to relevant workflows

Not every customer's contracts will require every document type in their
first weeks — `first_notice_generated` may simply not yet be applicable
for a customer whose current contracts aren't at a stage requiring one.
The onboarding model must not treat the absence of a not-yet-relevant
milestone as incomplete onboarding or a health risk.

### Identifying at-risk customers before renewal

A customer who reached `organisation_created` and `branding_configured`
but never reached `first_project_created`, or who reached
`first_project_created` but never any workflow milestone beyond it, is a
clear at-risk signal worth proactive outreach well before their renewal
date — this is the primary commercial purpose of tracking onboarding
milestones at all, not a vanity metric.

### Deliberately excluded

**"Users added" is not an onboarding milestone.** Given the current
one-primary-operator reality, requiring or expecting additional users to
be invited as a sign of healthy onboarding would misread a normal,
successful single-operator account as incomplete.

---

## 16. Customer Success and Health

**This section is a business and product concept only — no scoring
algorithm or implementation should be built during this checkpoint.**

### Possible inputs

- Last login.
- Active usage (recency and frequency of meaningful actions, not just
  logins).
- Projects created.
- Contracts created.
- AI analyses completed.
- Payment applications created.
- Notices generated.
- Documents generated.
- Approaching usage allowances (a *positive* expansion signal, not
  inherently a risk signal — see below).
- Unresolved support issues.
- Billing state (see Section 17 — kept separate, not merged in).
- Onboarding completion (Section 15).
- Renewal proximity.

### Recommended categories

- `healthy` — active use of core workflows, no unresolved concerns.
- `needs_attention` — some early warning signal (e.g. declining usage,
  an unresolved support issue) not yet serious.
- `adoption_risk` — never reached, or has stalled well short of, the
  "first real workflow" checkpoint (Section 14).
- `renewal_risk` — approaching renewal with low usage or unresolved
  concerns.
- `billing_risk` — a distinct category, deliberately overlapping with but
  not identical to Billing Health (Section 17) — flagged here only insofar
  as a billing issue should also surface in the customer-success view, not
  as a duplicate scoring mechanism.

### Which signals are commercially meaningful — and which could mislead

- **Meaningful**: reaching the first-real-workflow checkpoint, sustained
  use of core workflows (payment applications, notices, contracts) over
  time, approaching a usage allowance (an expansion signal, treated
  positively, not as a risk).
- **Potentially misleading**: **login frequency alone.** A contracts
  manager who logs in twice a month to process a payment application cycle
  that only occurs monthly is not less healthy than one logging in daily —
  construction commercial cycles are naturally periodic (monthly payment
  application cycles are standard), not continuous. **Login frequency must
  never be treated as a standalone success or health signal** — it must
  always be considered alongside what the customer's actual contractual
  and payment cycle requires.
- **Also potentially misleading**: raw document/action *count* without
  context — a customer with fewer live contracts will naturally generate
  fewer documents than one with many, without that meaning lower
  "health."

### Explicit warning against a single collapsed score

Customer health should remain a small set of clear categories with
supporting evidence (per above), not a single opaque numeric score — a
number invites false precision and hides which specific signal is driving
a "risk" classification, which is what actually matters for a commercial
follow-up conversation.

---

## 17. Billing Health

**This section is a business and product concept only — no scoring or
implementation should be built during this checkpoint.**

### Recommended states

- `healthy`
- `payment_pending`
- `past_due`
- `grace_period`
- `unpaid`
- `suspension_pending`
- `suspended`
- `cancelled`
- `expired`

These map closely to (but are a customer-success/reporting-facing
simplification of) the technical `SubscriptionStatus` vocabulary already
defined in the billing foundation.

### Billing Health is distinct from Customer/Product Health

**These are deliberately separate concepts and must not be collapsed into
one score.** Examples that illustrate why:

- A customer can be **commercially healthy but have a temporary payment
  issue** — e.g. an expired card on file, resolved within days, with no
  actual dissatisfaction or reduced product usage.
- A customer can be **fully paid but have poor product adoption** — billing
  is entirely healthy, but they've never reached the first-real-workflow
  checkpoint and are a churn risk at renewal regardless of billing state.
- A customer can be **actively using the platform heavily but approaching
  renewal risk** for reasons unrelated to billing (e.g. a champion within
  the customer's business has left, a competing internal priority has
  emerged) — again, orthogonal to billing state.

Collapsing these into a single score would hide exactly the distinction
that matters for deciding *what kind of intervention* is needed — a
billing issue needs an invoice/payment conversation; a product-adoption
issue needs a customer-success conversation; conflating them risks the
wrong team, or no team, acting on the right signal.

---

## 18. Enterprise Commercial Profile

**This section is a business and product concept only — no
implementation should be built during this checkpoint.**

Future information an Enterprise account may require, classified by
likely necessity:

| Field | Classification |
|---|---|
| Account manager | Likely future requirement |
| Support SLA | Likely future requirement |
| Commercial contact | Likely future requirement |
| Billing contact | Likely future requirement |
| Invoice email | Likely future requirement |
| Legal contact | Optional — only required when a customer requests it |
| Procurement contact | Optional — only required when a customer requests it |
| Purchase order requirement (flag) | Likely future requirement |
| Purchase order number (per invoice) | Only required when a customer requests it |
| Contract start date | Likely future requirement |
| Contract expiry date | Likely future requirement |
| Renewal review date | Likely future requirement |
| Negotiated billing terms (e.g. payment terms in days) | Likely future requirement |
| Payment terms | Likely future requirement |
| Legal agreement reference | Likely future requirement |
| Signed agreement location (a link/reference, not the document itself) | Optional |
| Organisation group or subsidiary requirements | Plausible, demand-driven (see Section 20) |
| Direct Debit or alternative payment arrangement | Plausible, demand-driven (see Section 20) |

None of these fields exist in the schema today and none should be built
during this checkpoint — this table exists so that when the first real
Enterprise negotiation happens, the likely information needs are already
anticipated rather than discovered ad hoc.

---

## 19. Renewal and Retention

- **Automated renewal for standard monthly and annual subscriptions** —
  Stripe handles the recurring charge; no human involvement needed for a
  healthy, paying standard-plan customer.
- **Renewal reminders**: recommended to eventually be automated
  (email-based) for standard plans approaching renewal, consistent with
  the existing queued email/notification architecture.
- **Enterprise renewal reviews**: always a human conversation — never
  automated, given the negotiated nature of the account.
- **Expiring negotiated terms**: should surface as a clear operator-facing
  signal well before expiry (not silently lapse into an undefined state).
- **Approaching limits as expansion signals**: should trigger a
  human-initiated upgrade conversation, not an automated upsell email
  alone — consistent with the sales-assisted philosophy, though an
  automated *notification to Super Admin/sales* that a customer is
  approaching a limit is reasonable to automate even if the actual
  customer conversation is not.
- **Low adoption as a customer-success signal**: should trigger human
  outreach ahead of renewal, not be left to surface only as a cancellation
  after the fact.
- **Failed payments as a billing issue, not automatic customer failure**:
  a failed card payment should trigger a billing-recovery flow (retry,
  update payment method) before any assumption of customer dissatisfaction
  — see Billing Health, Section 17.
- **Cancellation feedback**: any cancellation should capture a reason
  where practical — valuable both for product decisions and for spotting
  patterns (e.g. a specific plan or onboarding gap causing churn).
- **Reactivation**: a cancelled or expired customer returning should be a
  straightforward, low-friction path (a new checkout against the same
  organisation), not treated as if they were a brand new customer requiring
  a full sales cycle again, where practical.

### Recommended automation split

**Reasonable to automate**: renewal reminder emails, Super Admin/sales
notification of an approaching usage allowance, failed-payment retry
sequences (standard Stripe dunning behaviour). **Requires a human
conversation**: Enterprise renewal review, any actual upgrade/downgrade
decision, cancellation retention conversations, reactivation for a
long-lapsed Enterprise account.

---

## 20. Future Commercial Roadmap

### High confidence

- **Organisation groups or subsidiaries** — a growing customer with
  multiple trading entities is a near-certain occurrence given the target
  market (principal contractors and larger subcontractors often operate
  through several legal entities); worth architectural awareness before
  urgently needed, though not building it prematurely.
- **Accounting exports or integrations (Xero/QuickBooks/Sage)** — UK
  construction businesses run on UK accounting software; this becomes
  table stakes within a few years, not a differentiator.
- **Direct Debit or GoCardless** — many UK B2B customers prefer Direct
  Debit over card for recurring spend; a genuinely likely request once the
  customer base grows.
- **Purchase order fields for Enterprise invoices** — a low-effort,
  high-value addition once the first Enterprise customer's procurement
  process requires it.
- **Stronger commercial reporting** — MRR/ARR by plan, usage-approaching-
  limit reports, negotiated-override visibility — these become
  operationally necessary as soon as there is a real customer base to
  report on, not a nice-to-have.
- **Renewal and expansion workflows** — the human-conversation triggers
  described in Section 19 becoming systematised as the customer base
  grows beyond what founders can track from memory.

### Plausible but demand-driven

- **Metered AI billing** — only relevant if AI features grow into a
  primary value driver rather than a supporting one; revisit the pricing
  philosophy itself (Section 7) if that shift happens, rather than
  bolting metering onto the bundled model prematurely.
- **International pricing** — only relevant if international sales
  actually materialise, given the UK-specific statutory-date focus.
- **Regional tax support** — follows directly from international pricing;
  not relevant until that happens.
- **Self-service checkout** — a deliberate, planned future phase (Section
  4), contingent on proven plans, trust signals, and support processes —
  not speculative, but correctly sequenced after the sales-assisted model
  is proven.
- **Self-service plan upgrades** — follows self-service checkout; same
  sequencing logic.
- **Public API commercial access** — reserved as a future entitlement key
  (`api_access`) precisely because no public API exists yet; revisit once
  one does.

### Do not design prematurely

- **Complex CRM integrations (Salesforce/HubSpot/Dynamics)** — this is
  upstream of billing entirely (a sales-team tooling concern), not a
  billing architecture concern; revisit only if the sales team's own
  tooling needs actually change.
- **Sophisticated multi-jurisdiction tax engines (e.g. Avalara)** — Stripe
  Tax likely suffices for a UK-focused business for the foreseeable
  future; multi-jurisdiction complexity is for sellers operating across
  many tax jurisdictions, which SureSign is not.
- **Highly granular consumption billing** — inconsistent with the bundled
  pricing philosophy (Section 7); would misprice the product as if usage
  volume were the core value rather than the correctness/compliance
  outcome.
- **Arbitrary per-feature micro-pricing** — the same risk as above, at
  finer grain; a fragmented invoice contradicts the "one predictable
  price" principle this strategy deliberately commits to.
- **Seat-based licensing without validated demand** — see the User
  Licensing Decision; the architecture stays capable of this, but building
  it ahead of an actual, confirmed business need would be solving a
  problem SureSign does not currently have.

---

## 21. Open Founder Decisions

| Decision | Current recommendation | Status | Approval needed from | Blocks implementation, or only live launch? |
|---|---|---|---|---|
| Final prices (Essential/Professional/Enterprise, monthly & annual) | Not proposed in this document | Open | Founder | Blocks live launch only — entitlement/plan structure design can proceed without final £ figures |
| Exact active-project allowances per plan | Indicative ranges suggested in Section 5, not final | Open | Founder | Blocks live launch only |
| Exact AI analysis allowances per plan | Indicative ranges suggested in Section 5, not final | Open | Founder | Blocks live launch only |
| Exact storage allowances per plan | "Generous" direction agreed; exact GB not proposed | Open | Founder | Blocks live launch only |
| Exact support response-time targets per plan | Not proposed | Open | Founder | Blocks live launch only |
| Annual pricing reduction (%) | Recommended as a standing structural discount; % not proposed | Open | Founder | Blocks live launch only |
| Founding customer policy (who qualifies, what discount, how long) | Recommended as a deliberate, permanent, named exception | Open | Founder | Blocks live launch only |
| VAT registration status | Requires accountant confirmation | Open | Founder + accountant | Blocks live launch only |
| Stripe Tax enablement | Recommended once VAT status confirmed | Open, pending above | Founder + accountant | Blocks live launch only |
| Invoice numbering — legal gaplessness requirement | Requires accountant review of the existing sequence mechanism | Open | Founder + accountant | Blocks live launch only |
| Trial conversion process (who follows up, when, how) | Sales-assisted, milestone-triggered (Section 8/14) | Open | Founder/sales | Blocks live launch only |
| Grace-period duration for past-due subscriptions | Not proposed in this document (see Entitlement Specification, Section 24) | Open | Founder | Blocks live launch only |
| Final Enterprise support model (SLA specifics) | Not proposed | Open | Founder | Blocks live launch only |

**None of the above block continuing with the entitlement architecture,
`BillingCustomerService`, or `PlanPriceMappingService`** — those require
the *shape* of the commercial model (three plans, dimensions, entitlement
categories), which is settled in this document, not the specific approved
numbers, which can be configured later without a redesign. They **do**
block live launch and the first real customer contract.
