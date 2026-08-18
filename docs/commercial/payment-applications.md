# Payment Applications

## What this is

A payment application is a claim for payment for work carried out in a given
valuation period, raised against either a project's main contract or a trade
package.

## Who can use it

Any authenticated user with access to the project — including the Client
role, not just Admin/Super Admin — can create and progress payment
applications.

## Where to find it

Project → **Commercial** → **Payment Applications** tab.

## Before you begin

Have ready:

- Which contract or trade package the application is against.
- The valuation period covered.
- The measured works value for the period, plus any variations or materials on
  site to include.

## How to create a payment application

1. Select **New Payment Application**.
2. Choose the source: the main contract, or a specific trade package.
3. Enter the **Application Date** (required) and, optionally, a **Reference**.
4. Enter the **Valuation Period Start** and **End**.
5. Review or adjust the calculated **Payment Due Date**, **Final Date for
   Payment**, **Payment Notice Deadline**, and **Pay Less Notice Deadline** —
   these are pre-filled from the contract's payment terms where available.
6. Enter the **Current Gross Valuation** and **Less: Retention**.
7. Save as draft, or continue to add a full valuation breakdown — see
   [Application Valuations](application-valuations.md).

## Statuses

| Status | Meaning | Set by |
|---|---|---|
| Draft | Still being prepared; fully editable. | Created this way, or after Withdraw |
| Submitted | Formally submitted for assessment. | Submit |
| PN Issued | A payment notice has been issued against it. | Issuing a payment notice |
| PLN Issued | A pay less notice has been issued against it. | Issuing a pay less notice |
| Certified | The certifying party has certified an amount. | Certify |
| Paid | Payment has been recorded. | Mark Paid (terminal) |
| Cancelled | Withdrawn from the process entirely. | Cancel (terminal, only from Submitted/PN Issued/PLN Issued) |

## Key actions

- **Submit** — moves a draft application to Submitted.
- **Withdraw** — moves a submitted application back to Draft for correction.
  This keeps the same application number; SureSign records how many times an
  application has been withdrawn.
- **Certify** — records the certified amount and generates a Payment
  Certificate PDF automatically.
- **Mark Paid** — records that payment has been made. This is a final step; a
  paid application cannot be changed further.
- **Cancel** — available only from Submitted, PN Issued, or PLN Issued. Not
  available on a draft or a certified/paid application.
- **Delete** — only available on Draft or Cancelled applications (this removes
  it from your active list without destroying the underlying record).

!!! warning "Marking as paid is final"
    Once an application is marked Paid, it cannot be edited further. Double
    check the paid amount, date, and reference before confirming.

## What happens after you save, submit, or certify

- **Save as draft** — nothing else changes; you can keep editing.
- **Submit** — the application becomes visible for assessment; certain fields
  may become locked from casual editing.
- **Certify** — a Payment Certificate PDF is generated automatically, and the
  certified amount feeds into later applications' "previous certified" figures
  and into the [Final Account](final-account.md).

## What other modules this affects

- [Payment Notices](payment-notices.md) and [Pay Less Notices](pay-less-notices.md)
  are issued against a specific payment application.
- [Retention](retention.md) accumulates from certified/paid applications.
- The project [Calendar](../calendar/overview.md) shows the application's due
  date, final date for payment, and notice deadlines.
- Certified and paid values feed the [Final Account](final-account.md).

## Common mistakes to avoid

- Forgetting to check the pre-filled statutory dates against your actual
  contract terms before submitting.
- Cancelling an application when what you actually meant to do was withdraw it
  for correction — cancelling is intended to be final; withdrawing keeps the
  same application number and lets you resubmit.

## What to do next

- [Generate the payment certificate or Excel workbook](generated-documents.md).
- If the certified amount differs from the applied-for amount, consider whether
  a [Pay Less Notice](pay-less-notices.md) is required under your contract.
