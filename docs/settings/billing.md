# Billing

Tenant workspace → **Settings** → **Billing** (also reachable from the
sidebar footer profile menu).

Billing shows your organisation's current subscription, available plans,
and payment history. Anyone in your organisation can view this page; the
backend always scopes it strictly to your own organisation.

## What you'll see

- **Current subscription** — plan, status, billing interval, recurring
  amount, current billing period, renewal date, trial end, and grace
  period end, where applicable.
- **Access status** — a plain-language explanation of what your current
  subscription state means (for example, active, past due, or trial),
  translated from internal status codes into clear wording.
- **Plans** — Essential, Professional, and Enterprise, with current local
  pricing and your current plan marked. Monthly/annual pricing is shown
  where available. Prices are shown exclusive of VAT ("+ VAT") — any
  applicable VAT is calculated by Stripe at the point of payment, based
  on your billing details, and shown as a separate line before you pay.
- **Pending plan change** — if an upgrade or downgrade has been
  requested, its current plan, target plan, and status are shown here.
  Your current plan remains active until the change is confirmed.
- **Recent invoices** and **payment history** — read-only records scoped
  to your organisation. Each invoice shows SureSign's own reference
  number alongside Stripe's own invoice number (the one shown on the
  hosted invoice page and PDF), where available.

## Starting a subscription

If your organisation doesn't have a subscription yet, selecting Essential
or Professional (monthly or annual) starts secure Checkout. A brief
branded screen confirms you're being securely connected to Stripe before
you're taken to Stripe's hosted payment page; after paying, you're
returned to SureSign with your subscription confirmed once we've
verified payment with Stripe (this is usually immediate, but may take a
few seconds — the Billing page shows a short "confirming with Stripe"
step while it waits). Enterprise is Contact Sales only — selecting it
takes you to book a call with our team instead of Checkout.

## If you close Checkout before paying

If you close Stripe's Checkout page before completing payment, nothing is
lost and you're never charged. Come back to the Billing page any time and
you'll see your attempted plan marked **Awaiting Payment** with a plain
explanation that payment hasn't been completed, your subscription hasn't
been activated, and your access hasn't changed.

From there you can:

- **Continue Payment** — go straight back to the same Checkout attempt
  (or, if it has expired, a fresh one is started for you automatically).
- **Cancel pending Checkout** — abandon the attempt entirely so you can
  choose a plan again immediately. This is never shown as a "Cancelled
  subscription" — no subscription was ever created and nothing was
  charged, so the Billing page simply returns to its normal "choose a
  plan" state.

If you select a different plan while your original Checkout is still
valid, SureSign asks you to confirm first — continue the original
attempt, or cancel it before starting the new one — rather than silently
discarding it.

## Changing your plan

If your organisation already has an active subscription, selecting a
higher plan requests an **upgrade** — this takes effect as soon as
Stripe confirms it (usually within seconds), and may create a prorated
charge for the rest of your current billing period; your billing date
itself doesn't change. Selecting a lower plan schedules a **downgrade**
for your next renewal date — your current plan and access continue
until then, with no early loss of access. A confirmation dialog explains
which applies before you submit.

While a plan change is pending, it's shown on the Billing page with its
target plan and effective date. A pending change that hasn't been sent
to Stripe yet can be cancelled from there; selecting a different plan
replaces it automatically. Only one plan change may be pending at a
time.

Switching billing interval alone (monthly to annual, or back) without
also changing plan isn't supported yet.

## Cancelling your subscription

If your organisation has an active subscription with no pending plan
change, you can request cancellation from the Billing page. Cancellation
always takes effect at the end of your current billing period — never
immediately — and your subscription and access continue fully until
then. No refund is issued for the remainder of the current period.

While cancellation is pending, the Billing page shows the exact date it
takes effect and an "Undo cancellation" option — you can reverse it any
time before that date. You can't request an upgrade or downgrade while a
cancellation is pending; undo it first if you want to change plans
instead.

## Managing payment methods, billing details and invoices

Selecting **Manage payment methods & invoices** shows a brief "Opening
Secure Billing Centre" screen, then takes you to a secure, Stripe-hosted
page where you can update your saved payment method, billing address,
phone number and tax ID, and view your full invoice history (including
hosted invoice pages and PDFs where available). When you return, the
Billing page briefly shows that it's refreshing your information so you
know your changes have come back with you.

Plan changes and cancellation are **not** available on that page — both
stay here on the Billing page, exactly as described above.
