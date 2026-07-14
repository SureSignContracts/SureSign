# Contract Onboarding (Upload, Analyse, Review, Confirm)

This workflow combines several related tasks: creating a contract record,
uploading the document, running AI analysis, and confirming the results.

## Purpose

Get a project's contract into SureSign with its key terms captured, either
manually or via AI analysis, so the rest of SureSign (payment dates, programme
milestones) can use accurate information.

## Prerequisites

A project must already exist. Have the executed contract document ready to
upload.

## Role required

Super Admin or Admin.

## Steps

1. [Create the contract record](../contracts/creating-a-contract.md) — title,
   type, and whatever terms you already know.
2. [Upload the contract document](../contracts/uploading-a-contract.md).
3. If your organisation has AI enabled, [start AI analysis](../contracts/ai-analysis.md).
4. [Review the extracted results](../contracts/reviewing-ai-results.md) —
   correct anything that looks wrong.
5. [Confirm the analysis](../contracts/confirming-analysis.md).

## Expected result

The contract has its terms captured (manually, via confirmed AI analysis, or
both), and confirmed data is available for programme milestones and
commercial calculations elsewhere in the project.

## Linked modules

- [Contracts](../contracts/overview.md)
- [Programme](../programme/overview.md) (seeding milestones from the
  confirmed analysis)
- [Commercial](../commercial/overview.md) (statutory dates and retention use
  contract terms)

## Notifications

You are notified in-app when an AI analysis completes or fails.

## Common mistakes

- Confirming an analysis without checking the overwrite warning when the
  contract already has data in those fields.
- Uploading a poor-quality scan and expecting good AI extraction results.

## What to do next

[Create a trade package](trade-package-onboarding.md) if the project involves
subcontract packages, or move straight into [Commercial](../commercial/overview.md).
