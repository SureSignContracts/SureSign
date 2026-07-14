# Subcontract Onboarding

## What this is

Once a trade package exists, subcontract onboarding covers generating the
subcontract document package for the chosen subcontractor, and optionally
running AI analysis on an uploaded subcontract.

## Who can use it

Super Admin and Admin users.

## Generating subcontract documents

1. Open the trade package and select the package generation action.
2. Choose a **Generation Type**:
      - **Complete Package** — one combined document generated from a single
        "Master Package" template.
      - **Separate Documents** — choose which individual documents to
        generate: Procurement Summary, Tender Enquiry Letter, Schedule of
        Documents, Subcontract Draft.
3. For Complete Package, choose the template to use.
4. Review and edit the pre-filled fields, grouped as:
      - **Project Details** — company, project name/reference, site address,
        employer, architect, quantity surveyor, principal designer.
      - **Trade Package Details** — name, reference, code, scope.
      - **Contractor Details** — contractor name, legal name, company number,
        registered address, contact name, email.
      - **Commercial Details** — contract sum (figures and words), start/
        completion dates, retention percentage, liquidated damages rate,
        rectification period, valuation day, document date.
      - **Optional References** — drawing schedule, specification, pricing
        document, and prelims references.
5. Select **Generate**.

!!! note
    Contractor details (including company number and registered address) are
    entered manually in this form. There is no automatic lookup or autofill
    from Companies House inside this form today — if you need to verify a
    company, use the separate **Find Company** tool described below, then type
    the details in here yourself.

## What happens after you generate

- SureSign produces the chosen document(s) as Word files, with a PDF preview
  available for each.
- If any placeholders in the template could not be filled in automatically,
  the result screen tells you how many are unresolved so you can check the
  generated file.
- Each generated file has a **Download** button.

## Looking up a subcontractor on Companies House

Administrator accounts can use a separate **Find Company** tool to search the
UK Companies House register by name or number. Results show company status,
registered address, and officers. This is a standalone lookup tool — details
found here are not automatically copied into the subcontract generation form;
copy them across manually if needed.

## Analysing an uploaded subcontract with AI

If you have an actual signed or draft subcontract document (rather than one
generated from a template), you can upload it to the trade package and run
[Subcontract AI Analysis](../ai/subcontract-analysis.md) to extract its terms
for review.

## What to do next

- Review and confirm any AI analysis results.
- Continue to the trade package's [Workspace](workspace.md) to manage its
  commercial records, programme, and documents.
