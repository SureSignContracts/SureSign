# Trade Package Onboarding (Create, Generate Subcontract, Analyse Subcontract)

## Purpose

Set up a trade package (subcontract work package) and get its subcontract
documentation and terms into SureSign.

## Prerequisites

A project with a contract already set up. Know which standard or custom trade
packages you need.

## Role required

Super Admin or Admin.

## Steps

1. [Create the trade package(s)](../trade-packages/creating-a-trade-package.md)
   using the package generator (standard catalogue or custom name).
2. [Generate the subcontract document package](../trade-packages/subcontract-onboarding.md)
   for the chosen subcontractor (Complete Package or Separate Documents), or
   upload an actual signed/draft subcontract document if one already exists.
3. If you uploaded a real subcontract document, [run AI analysis on it](../ai/subcontract-analysis.md).
4. Review and confirm the analysis, the same way as for a main contract.
5. Optionally, [look up the subcontractor on Companies House](../trade-packages/subcontract-onboarding.md#looking-up-a-subcontractor-on-companies-house)
   to verify their details before entering them manually into the generation
   form.

## Expected result

The trade package has its standard folders, generated or uploaded subcontract
documents, and (if analysed) confirmed terms available to its own commercial
and programme records.

## Linked modules

- [Trade Packages](../trade-packages/overview.md)
- [Commercial](../commercial/overview.md) — trade packages can have their own
  payment applications
- [AI in SureSign](../ai/overview.md)

## Notifications

You are notified when subcontract documents are generated, and when AI
analysis completes or fails.

## Common mistakes

- Expecting Companies House lookup results to auto-fill the generation form —
  they do not; copy details across manually.
- Re-running the trade package generator expecting to recreate an existing
  package — existing packages are skipped, not duplicated.

## What to do next

[Create and submit a payment application](payment-application-process.md)
against the trade package.
