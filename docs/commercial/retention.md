# Retention

## What this is

Retention is the percentage of certified value withheld from payment as
security, and later released in stages. SureSign tracks retention per contract
(and per trade package, where applicable) and lets you release it in the
standard two halves used in UK construction: Practical Completion (Half 1) and
Making Good Defects (Half 2).

## Who can use it

Any authenticated user with access to the project — including the Client
role, not just Admin/Super Admin — can release retention.

## Where to find it

Project → **Commercial** → **Retention** tab.

## What you will see

For each contract with a retention percentage set:

- **Rate %** and **Cap %** for that contract.
- Four summary figures: **Retention Held**, **Retention Released**, **Current
  Balance**, and **Max Retention** (contract sum × cap %).
- Two moiety cards, **Half 1 / Practical Completion** and **Half 2 / Making
  Good Defects**, each showing Target, Released, and Outstanding, with a
  **Release** button when there is an outstanding balance.

If no retention has been held yet, SureSign explains that retention is
calculated from certified payment applications, so nothing will show until at
least one application has been certified.

## How to release retention

1. Select **Release** on the relevant moiety (or choose "Other / Manual
   Adjustment" for a release that does not map cleanly to either half).
2. Confirm or adjust the **Release Amount** — SureSign suggests an amount based
   on the outstanding balance for that moiety, and shows the maximum you can
   release.
3. Enter the **Release Date** (required).
4. Choose a **Release Reason** from the list provided, and add notes if useful.
5. Confirm.

!!! note
    If you choose a moiety that is already fully released, SureSign warns you
    but still allows a manual adjustment if you have a specific reason to
    release more.

## Where retention also appears

A payment application's own detail page shows retention as read-only figures
("Less: Retention," "Previous Retention Held") for context — releasing
retention itself happens from the Retention tab, not from the application.

## Related

- [Payment Applications](payment-applications.md)
- [Final Account](final-account.md)
