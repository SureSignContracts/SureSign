You are continuing development on my existing SureSign platform.

Project Context:
SureSign is a Laravel 11 + Next.js 15 multi-tenant Construction Operations & Contract Administration Platform.

The system already has:
- authentication
- role-based access
- admin workspace
- tenant/client workspace
- company management
- project workspaces
- contracts module
- commercial/payment applications module
- variations module
- notices module
- documents module
- templates module
- company branding
- project activity direction
- backend/frontend structure already in place

Important:
Do NOT rebuild the system.
Do NOT redesign the whole UI.
Do NOT change authentication, roles, branding, or existing working modules.
Work within the existing architecture.

New Objective:
Add an Adjudication module to the Project Workspace.

This module should support construction dispute/adjudication workflows and should sit inside each project, not the main/global sidebar.

Adjudication should connect to:
- Projects
- Contracts
- Payment Applications
- Variations
- Notices
- Documents
- Deadlines
- Project Activity

Correct placement:
Add “Adjudication” to the Project Workspace sidebar only.

Suggested sidebar order:
Overview
Contracts
Commercial / Payment Applications
Variations
Notices
Adjudication
RFIs
Meetings
QA Reports
Snagging
Closeout
Documents

==================================================
CORE CONCEPT
==================================================

Adjudication is not a generic page.

It is a guided dispute workflow.

Each project may have multiple adjudication/dispute cases.

Each adjudication case should contain:
- status tracking
- related contract
- related payment application
- related variation
- related notices
- uploaded documents
- generated documents
- deadlines
- action buttons
- project activity logs
- AI assistant hooks for future drafting/summarising

The workflow should follow these 8 key steps:

1. Notice of Dispute
2. Notice of Adjudication
3. Adjudicator Appointment
4. Referral Submission
5. Response Analysis
6. Further Submissions
7. Decision Analysis
8. Enforcement

==================================================
DATABASE STRUCTURE
==================================================

Create these tables if they do not already exist:

1. adjudication_cases

Suggested fields:
- id
- organization_id
- project_id
- contract_id nullable
- payment_application_id nullable
- variation_id nullable
- created_by
- case_number
- title
- dispute_type
- claimant_name
- respondent_name
- claim_amount nullable
- currency default GBP
- summary text nullable
- status
- current_step
- notice_of_dispute_date nullable
- notice_of_adjudication_date nullable
- referral_due_date nullable
- response_due_date nullable
- decision_due_date nullable
- decision_received_date nullable
- enforcement_deadline nullable
- metadata json nullable
- created_at
- updated_at
- deleted_at nullable

Suggested dispute_type values:
- payment_dispute
- variation_dispute
- delay_dispute
- defect_dispute
- contract_interpretation
- non_payment
- other

Suggested status values:
- draft
- notice_of_dispute
- notice_of_adjudication
- adjudicator_appointment
- referral_submission
- response_analysis
- further_submissions
- decision_analysis
- enforcement
- closed

Suggested current_step values:
- notice_of_dispute
- notice_of_adjudication
- adjudicator_appointment
- referral_submission
- response_analysis
- further_submissions
- decision_analysis
- enforcement

2. adjudication_steps

Suggested fields:
- id
- adjudication_case_id
- step_key
- title
- description
- status
- due_date nullable
- completed_at nullable
- completed_by nullable
- notes text nullable
- metadata json nullable
- created_at
- updated_at

Step status values:
- pending
- in_progress
- completed
- skipped

3. adjudication_documents

Suggested fields:
- id
- organization_id
- project_id
- adjudication_case_id
- document_id nullable
- uploaded_by nullable
- title
- document_type
- file_path nullable
- file_name nullable
- mime_type nullable
- file_size nullable
- version default 1
- status
- source_step
- ai_generated boolean default false
- created_at
- updated_at

Document types:
- notice_of_dispute
- notice_of_adjudication
- adjudicator_application
- referral_submission
- response
- further_submission
- decision
- enforcement_letter
- evidence
- supporting_document
- other

4. adjudication_deadlines

Suggested fields:
- id
- organization_id
- project_id
- adjudication_case_id
- title
- description nullable
- deadline_type
- due_date
- status
- reminder_sent boolean default false
- completed_at nullable
- created_at
- updated_at

Deadline types:
- notice_deadline
- referral_deadline
- response_deadline
- decision_deadline
- enforcement_deadline
- custom

Deadline status:
- upcoming
- due_soon
- overdue
- completed

==================================================
MODEL RELATIONSHIPS
==================================================

AdjudicationCase belongs to:
- Organization
- Project
- Contract nullable
- PaymentApplication nullable
- Variation nullable
- User as created_by

AdjudicationCase has many:
- adjudication_steps
- adjudication_documents
- adjudication_deadlines

Project has many:
- adjudication_cases

Documents should be linkable to adjudication cases.

If existing documents table supports polymorphic relations, use:
- documentable_type = AdjudicationCase
- documentable_id = adjudication_case_id

==================================================
BACKEND REQUIREMENTS
==================================================

Create or update controllers/services/routes for:

AdjudicationCaseController
- index by project
- store
- show
- update
- destroy/archive
- update status
- advance step

AdjudicationDocumentController
- upload document
- link existing document
- generate document placeholder
- list documents by case

AdjudicationDeadlineController
- list deadlines
- create deadline
- update deadline
- mark complete

Recommended routes:

GET    /api/projects/{project}/adjudication-cases
POST   /api/projects/{project}/adjudication-cases
GET    /api/projects/{project}/adjudication-cases/{case}
PUT    /api/projects/{project}/adjudication-cases/{case}
DELETE /api/projects/{project}/adjudication-cases/{case}

POST   /api/projects/{project}/adjudication-cases/{case}/advance-step
POST   /api/projects/{project}/adjudication-cases/{case}/update-status

GET    /api/projects/{project}/adjudication-cases/{case}/documents
POST   /api/projects/{project}/adjudication-cases/{case}/documents

GET    /api/projects/{project}/adjudication-cases/{case}/deadlines
POST   /api/projects/{project}/adjudication-cases/{case}/deadlines
PUT    /api/projects/{project}/adjudication-cases/{case}/deadlines/{deadline}

All routes must:
- be protected by auth
- respect organization/project scoping
- prevent users from accessing cases outside their organization/project
- support Admin/Super Admin access properly

==================================================
FRONTEND REQUIREMENTS
==================================================

Create a project-level Adjudication page:

/app/projects/[id]/adjudication

Main page should show:
- list of adjudication cases
- case number
- title
- dispute type
- claimant
- respondent
- claim amount
- current step
- status
- next deadline
- created date
- action buttons

Add buttons:
- New Adjudication Case
- View Case
- Edit Case
- Upload Document
- Advance Step

Add filters:
- status
- dispute type
- current step
- overdue deadlines

==================================================
CREATE CASE FORM
==================================================

The Create Adjudication Case form should include:

Required:
- title
- dispute_type
- claimant_name
- respondent_name

Optional:
- contract_id
- payment_application_id
- variation_id
- claim_amount
- summary
- notice_of_dispute_date
- notice_of_adjudication_date
- referral_due_date
- response_due_date
- decision_due_date

When a case is created:
- save organization_id
- save project_id
- save created_by
- generate case_number automatically
- default status to draft
- default current_step to notice_of_dispute
- auto-create the 8 adjudication steps
- create project activity log
- refresh adjudication case list

==================================================
CASE DETAIL PAGE
==================================================

Create detail page:

/app/projects/[id]/adjudication/[caseId]

The case detail page should show:

1. Case Header
- case number
- title
- status badge
- current step
- related project
- related company
- related contract
- claim amount

2. Step Tracker
Show the 8 steps as a vertical or horizontal workflow:
- Notice of Dispute
- Notice of Adjudication
- Adjudicator Appointment
- Referral Submission
- Response Analysis
- Further Submissions
- Decision Analysis
- Enforcement

Each step should show:
- status
- due date
- completed date
- action button

3. Documents Section
Show:
- generated documents
- uploaded evidence
- supporting documents
- document versions

4. Deadlines Section
Show:
- upcoming deadlines
- overdue deadlines
- completed deadlines

5. Action Panel
Based on current step, show relevant actions.

==================================================
8-STEP WORKFLOW DETAILS
==================================================

STEP 1 — Notice of Dispute

Purpose:
Capture dispute details and generate an initial Notice of Dispute document.

Actions:
- input dispute details
- generate Notice of Dispute draft
- save as adjudication document
- optionally link to Notices module
- mark step complete

Output:
- Notice of Dispute document
- activity log entry

Button:
Generate Notice of Dispute

==================================================

STEP 2 — Notice of Adjudication

Purpose:
Generate a formal Notice of Adjudication using previous dispute data.

Actions:
- pre-fill from case details
- allow editing
- generate formal notice document
- save document
- mark step complete

Output:
- Notice of Adjudication document
- activity log entry

Button:
Generate Notice of Adjudication

==================================================

STEP 3 — Adjudicator Appointment

Purpose:
Prepare adjudicator appointment/application details.

Actions:
- auto-fill application form from case
- capture nominating body details
- capture proposed adjudicator if any
- prepare email/application package
- save document
- mark step complete

Suggested fields:
- nominating_body
- adjudicator_name nullable
- application_reference nullable
- appointment_date nullable
- appointment_status

Output:
- adjudicator appointment application
- optional email draft
- activity log entry

Button:
Prepare Appointment Application

==================================================

STEP 4 — Referral Submission

Purpose:
Prepare and submit referral pack.

Actions:
- upload supporting documents
- select evidence documents
- AI hook for future summarising/drafting
- generate referral pack placeholder
- mark step complete

Output:
- referral submission record
- referral document pack
- evidence bundle
- activity log entry

Button:
Prepare Referral Pack

==================================================

STEP 5 — Response Analysis

Purpose:
Upload and review opposing party response.

Actions:
- upload opposing party response
- AI hook for future key argument extraction
- record response summary
- record risks/weaknesses
- mark step complete

Output:
- response analysis notes
- uploaded response document
- activity log entry

Button:
Upload / Analyse Response

==================================================

STEP 6 — Further Submissions

Purpose:
Prepare further reply submissions.

Actions:
- record counterarguments
- AI hook for future drafting
- generate further submission draft
- save document
- mark step complete

Output:
- further submission draft
- activity log entry

Button:
Draft Further Submission

==================================================

STEP 7 — Decision Analysis

Purpose:
Record adjudicator decision and summarise outcome.

Actions:
- upload adjudicator decision
- record awarded amount
- record key decision points
- record outcome
- mark step complete

Suggested fields:
- decision_outcome
- amount_awarded
- decision_summary
- payment_due_date

Output:
- decision record
- decision analysis summary
- activity log entry

Button:
Upload Decision

==================================================

STEP 8 — Enforcement

Purpose:
Generate enforcement/payment demand documents.

Actions:
- generate payment demand letter
- generate letter of claim
- record enforcement deadline
- mark case closed when completed

Output:
- demand letter
- letter of claim
- enforcement record
- activity log entry

Button:
Generate Enforcement Documents

==================================================
PROJECT ACTIVITY INTEGRATION
==================================================

Every major adjudication action should create a project activity.

Examples:
- “Adjudication case ADJ-001 created”
- “Notice of Dispute generated”
- “Notice of Adjudication completed”
- “Referral pack prepared”
- “Response uploaded for analysis”
- “Decision uploaded”
- “Enforcement document generated”
- “Adjudication case closed”

Use existing ProjectActivity system if available.
If not available, create or integrate with the planned activity service.

==================================================
DOCUMENTS INTEGRATION
==================================================

Adjudication documents should appear in:
- adjudication case detail page
- project documents module
- latest documents on project overview if available

Generated documents should support:
- title
- type
- version
- status
- file path
- source step
- linked adjudication case

Do not create final legal PDFs automatically without review.
Generated documents should be saved as drafts first.

Suggested document statuses:
- draft
- pending_review
- approved
- issued
- archived

==================================================
DEADLINE TRACKING
==================================================

Each case should track important deadlines:
- referral due date
- response due date
- decision due date
- enforcement deadline

Frontend should display:
- next upcoming deadline
- overdue deadlines
- deadline status badges

Deadline status rules:
- completed if completed_at exists
- overdue if due_date is past and not completed
- due_soon if due within 3 days
- upcoming otherwise

==================================================
AI INTEGRATION HOOKS
==================================================

Do not fully implement AI yet unless existing Claude API integration already exists.

Prepare hooks/buttons for future AI:
- Summarise dispute
- Draft Notice of Dispute
- Draft Notice of Adjudication
- Summarise referral documents
- Analyse response
- Suggest counterarguments
- Summarise decision
- Draft enforcement letter

AI must only generate drafts.
Human review is required before issuing documents.

==================================================
ADMIN CONTEXT REQUIREMENTS
==================================================

When Admin or Super Admin views a project:
- show the company/organization name clearly in the project header
- show adjudication cases under that project only
- make sure back button returns to /admin/projects when accessed from admin project view

Do not route admin users back to company list unless they came from company detail page and the system explicitly tracks that context.

==================================================
REACT QUERY REQUIREMENTS
==================================================

Use proper query invalidation after create/update actions.

Suggested query keys:
- ['project', projectId]
- ['project-adjudication-cases', projectId]
- ['adjudication-case', caseId]
- ['adjudication-documents', caseId]
- ['adjudication-deadlines', caseId]
- ['project-activities', projectId]
- ['project-documents', projectId]
- ['project-stats', projectId]

After successful mutations:
- close modal
- show success toast
- refresh relevant table/detail
- refresh project activity
- refresh stats if applicable

==================================================
PERMISSIONS
==================================================

Super Admin:
- full access to all adjudication cases

Admin:
- access to adjudication cases for allowed projects/organizations

Client:
- read-only by default
- can view adjudication status/documents only if permitted
- cannot create, edit, delete, or issue adjudication records unless explicitly allowed

Apply permissions both:
- backend policies/middleware
- frontend button visibility

==================================================
VALIDATION REQUIREMENTS
==================================================

Validate all submitted data.

Minimum validation:
- title required
- dispute_type required
- claimant_name required
- respondent_name required
- claim_amount numeric nullable
- dates must be valid dates
- related contract/payment application/variation must belong to the same project
- user must have access to the project

==================================================
FINAL EXPECTED RESULT
==================================================

After implementation:

Admin can:
1. open a project
2. click Adjudication in project sidebar
3. view adjudication cases
4. create a new adjudication case
5. link it to a contract/payment application/variation
6. track the 8-step adjudication workflow
7. upload documents
8. track deadlines
9. generate draft documents/placeholders
10. see activities in the project activity timeline
11. view adjudication documents in project documents

The module should feel like a real construction adjudication workflow tool, not a static page or simple CRUD table.

Do not overbuild.
Do not implement full legal automation.
Do not let AI issue legal documents automatically.
Focus on:
- correct database structure
- project-level integration
- working forms
- workflow steps
- deadline tracking
- document integration
- activity logging

Start with:
1. database tables and models
2. backend routes/controllers
3. project sidebar route
4. adjudication cases list
5. create case form
6. case detail page with 8-step tracker
7. documents/deadlines sections
8. activity integration