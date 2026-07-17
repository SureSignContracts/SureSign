# Variations

## What this is

A variation is a change to the contracted works: an addition, omission,
substitution, or other instructed change, tracked from instruction through to
agreed value.

## Who can use it

Super Admin and Admin create and progress variations. Client users can view
them.

## Where to find it

Project → **Variations**, or the Variations tab within **Commercial**.

## Before you begin

Have ready: which contract the variation relates to, a description of the
change, and, if known, an initial quoted amount and any programme impact.

## How to create a variation

1. Select **Create Variation**.
2. Choose the **Contract**.
3. Enter a **Title** and **Description**.
4. Choose a **Type** (for example addition, omission, substitution,
   provisional sum, daywork).
5. Enter the **Variation Date**.
6. Enter the **Instruction Method** and, if known, a **Quoted Amount**.
7. Choose a **Valuation Method**.
8. Enter any **Programme Impact (days)**.
9. Save.

## Status lifecycle

```mermaid
flowchart LR
    Draft --> Submitted --> Instructed --> Quoted --> Assessed
    Assessed --> Approved
    Assessed --> Rejected
    Rejected --> Submitted
```

| Status | Meaning | Set by |
|---|---|---|
| Draft | Being prepared. | Created this way |
| Submitted | Formally raised. | Submit |
| Instructed | Instructed to proceed. | Instruct |
| Quoted | A price has been submitted. | Quote (requires a Quoted Amount) |
| Assessed | The quote has been reviewed. | Assess |
| Approved | Agreed — **final**. | Approve |
| Rejected | Not agreed. | Reject (requires a rejection reason) |

A rejected variation can be **resubmitted**, returning it to Submitted so the
process can run again.

## Editing after approval

Once approved, a variation's agreed amount cannot be changed through a normal
edit — a formal revision process for approved variations is not currently
available. If a genuine change is needed after approval, discuss with your
Admin how your organisation wants to handle it (for example, raising a further
variation).

## What happens after each step

- **Submit / Instruct / Quote / Assess / Approve / Reject / Resubmit** each
  send a notification to relevant users, and are recorded in the project's
  activity feed.
- An **approved** variation's agreed amount can be included in a payment
  application's valuation and in the [Final Account](../commercial/final-account.md).

## Related modules

- [Payment Applications](../commercial/payment-applications.md)
- [Final Account](../commercial/final-account.md)
- [Glossary](../reference/glossary.md)
- [Programme](../programme/overview.md) — for programme impact
- [Risks](../risks/overview.md)

## Common mistakes to avoid

- Approving a variation before its quoted amount has actually been agreed —
  once approved, the amount is treated as final for that variation.
- Leaving a rejected variation sitting without resubmitting or replacing it if
  the underlying change is still needed.

## What to do next

Generate the **Variation Order** PDF from the variation once instructed or
approved, and check it is included where relevant in a payment application or
the Final Account.
