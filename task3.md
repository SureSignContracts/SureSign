You are helping me build my existing SureSign platform.

Context:
I already have a working Laravel 11 backend and Next.js 15 frontend. Branding works, roles work, authentication works, accounts work, admin side works, client/tenant side works, company pages work, project pages work, and project workspace pages already exist.

My platform is a white-label Construction Operations and Contract Administration Platform.

Current hierarchy:
SureSign Admin
→ Companies / Organisations
→ Projects
→ Project modules
→ Documents / workflows / PDFs

Current working areas:
- Admin dashboard
- Admin companies page
- Company detail page
- Project list
- Project overview page
- Tenant/client dashboard
- Project sidebar modules
- Branding per company
- Roles: Super Admin, Admin, Client
- Authentication and protected routes

Current problem:
The pages exist, but the modules are not yet operationally connected.

Goal:
Make the project modules behave like a real connected construction operations system, not isolated CRUD pages.

Important instruction:
Do not redesign the whole system.
Do not rebuild authentication.
Do not rebuild branding.
Do not create unnecessary new architecture.
Work with the existing structure and connect the modules step by step.

Main objective:
Build operational connections between:
- Projects
- Contracts
- Payment Applications
- RFIs
- Variations
- Notices
- Meeting Minutes
- Site Reports
- QA Reports
- Documents
- Project Activity Timeline
- Generated PDFs

Core rule:
Every operational action should:
1. belong to a project
2. belong to an organisation/company
3. optionally link to a contract
4. optionally generate or attach a document
5. create an activity log entry
6. update project stats
7. appear in the correct project module

Step-by-step implementation plan:

STEP 1 — Create project activity timeline

Add a ProjectActivity model/table if it does not exist.

Suggested fields:
- id
- organization_id
- project_id
- user_id
- activity_type
- title
- description
- related_type nullable
- related_id nullable
- metadata json nullable
- created_at
- updated_at

Activity examples:
- Project created
- Contract added
- Payment Application created
- RFI raised
- Variation submitted
- Notice generated
- Document uploaded
- PDF generated
- Meeting minutes issued

Create relationships:
Project hasMany ProjectActivity
Organization hasMany ProjectActivity
User hasMany ProjectActivity

Add API endpoints:
GET /api/projects/{project}/activities

Add activity list to project overview page.

STEP 2 — Make project overview a command center

Update project overview page so it shows:
- Project details
- Client information
- Current stats
- Recent activity
- Latest documents
- Pending commercial actions
- Open RFIs
- Upcoming deadlines

Stats should be calculated from real module data:
- open RFIs count
- pending variations count
- payment applications count
- open snagging count
- documents count
- contract value

Make stat cards clickable:
- Open RFIs → /app/projects/{id}/rfis?status=open
- Pending Variations → /app/projects/{id}/variations?status=pending
- Payment Apps → /app/projects/{id}/commercial or payment applications page
- Documents → /app/projects/{id}/documents

STEP 3 — Connect Contracts to Projects

Contracts must belong to:
- organization_id
- project_id
- created_by

When a contract is created:
- save it under the project
- create project activity
- update project commercial stats
- allow payment applications and variations to link to the contract

Contract fields:
- title
- reference_number
- type
- form_of_contract
- party_name
- contract_sum
- retention_percentage
- retention_cap_percentage
- payment_terms_days
- execution_date
- commencement_date
- completion_date
- status
- notes

Project contracts page should show:
- main contract
- contract sum
- retention
- payment terms
- status
- linked payment applications
- linked variations

STEP 4 — Build Payment Application workflow first

Payment Applications are the first priority workflow.

PaymentApplication belongs to:
- organization_id
- project_id
- contract_id
- created_by

Fields:
- application_number
- application_date
- due_date
- gross_valuation
- less_retention
- less_previous_payments
- amount_due
- certified_amount
- certified_date
- payment_date
- paid_amount
- status
- breakdown json

Statuses:
- draft
- submitted
- certified
- pay_less_notice_issued
- paid
- disputed

When a payment application is created:
- calculate amount_due
- create project activity
- show it in project commercial page
- update payment app count on project overview

When status changes:
- create activity entry
- update stats

STEP 5 — Connect Payment Applications to Documents/PDFs

Create a “Generate PDF” action for Payment Applications.

When clicked:
1. load organization branding
2. load payment application data
3. load project data
4. load contract data
5. render PDF using existing PDF engine/DomPDF
6. save the file to project documents
7. create a document record
8. link document to payment application using documentable_type/documentable_id
9. create project activity: “Payment Application PDF generated”

Document fields:
- organization_id
- project_id
- created_by
- template_id nullable
- documentable_type
- documentable_id
- title
- type
- category
- reference_number
- status
- file_path
- file_name
- mime_type
- file_size
- version
- ai_generated
- template_data json

The generated PDF should appear in:
- Payment Application detail page
- Project documents page
- Recent activity
- Latest documents on overview

STEP 6 — Build Documents module as central hub

Documents page should show both:
- uploaded files
- generated PDFs

Add folder structure per project:
- 01_Contract_Documents
- 02_Contract_Summary
- 03_Tender_Breakdown
- 04_RAMS
- 05_Payment_Applications
- 06_Valuations
- 07_QA_Reports
- 08_Letters
- 09_Notices
- 10_Snagging
- 11_Operation_Maintenance
- 12_Warranties

When a project is created, auto-create these folder records.

Documents page layout:
Left side:
- folder tree

Right side:
- documents/files table

Table columns:
- name
- type
- category
- version
- status
- uploaded/generated by
- date
- actions

Actions:
- view
- download
- regenerate if generated
- archive

STEP 7 — Connect RFIs to Documents and Activity

RFI belongs to:
- organization_id
- project_id
- created_by
- assigned_to nullable

Fields:
- rfi_number
- subject
- query
- response
- status
- priority
- date_raised
- response_required_by
- responded_at
- programme_impact
- programme_impact_days
- cost_impact_amount

Statuses:
- open
- pending_response
- responded
- closed

When RFI is created:
- create activity
- update open RFI count

When RFI is responded:
- update status
- create activity
- optionally generate RFI response PDF
- save generated PDF to documents

STEP 8 — Connect Variations to Contracts, Documents, and Activity

Variation belongs to:
- organization_id
- project_id
- contract_id
- created_by

Fields:
- variation_number
- title
- description
- instruction_type
- quoted_amount
- agreed_amount
- programme_impact_days
- status

Statuses:
- pending
- submitted
- approved
- rejected
- on_hold

When variation is created:
- create activity
- update pending variations count
- show in commercial page
- allow document/PDF generation

When approved/rejected:
- create activity
- update stats
- optionally affect contract/project commercial summary

STEP 9 — Connect Notices to source records

Notices should not be isolated.

A Notice can be linked to:
- payment application
- variation
- RFI
- contract
- project

Use polymorphic fields if already available:
- noticeable_type
- noticeable_id

Notice fields:
- organization_id
- project_id
- created_by
- notice_number
- title
- notice_type
- recipient
- subject
- body
- issue_date
- status
- source_type
- source_id

When notice is generated:
- create document PDF
- save to project documents
- create activity
- link back to the source record

Example:
Payment Application → Pay Less Notice → PDF → Documents → Activity Timeline

STEP 10 — Add shared service layer

Create Laravel services to avoid duplicated logic:

ProjectActivityService
- record(project, user, type, title, description, relatedModel = null, metadata = [])

DocumentGenerationService
- generateFromTemplate(project, template, data, relatedModel)

ProjectStatsService
- getStats(project)
- refreshStats(project)

ProjectFolderService
- createDefaultFolders(project)

PaymentApplicationService
- createPaymentApplication()
- calculateAmounts()
- submit()
- certify()
- markPaid()
- generatePdf()

Use services from controllers instead of putting all logic inside controllers.

STEP 11 — Frontend connection behavior

On each project module page:
- fetch project-specific records only
- display empty states properly
- add create button
- create/update records through API
- after successful creation/update:
  - invalidate React Query cache
  - refresh project stats
  - refresh activity timeline
  - show success toast
  - link user to detail page or list

Use TanStack React Query invalidation keys like:
- ['project', projectId]
- ['project-stats', projectId]
- ['project-activities', projectId]
- ['project-documents', projectId]
- ['project-rfis', projectId]
- ['project-payment-applications', projectId]
- ['project-variations', projectId]

STEP 12 — Permission rules

Super Admin:
- can view and manage all organizations, projects, documents, and settings

Admin:
- can manage projects, contracts, workflows, and documents for assigned organization or admin scope

Client:
- read-only access to assigned projects
- can view documents, reports, project status
- cannot create/edit/delete operational records unless explicitly allowed

Apply role checks both:
- backend middleware/policies
- frontend conditional UI

STEP 13 — Keep AI as later layer

Do not build AI first.

Only prepare clean extension points:
- ai_summary on meeting minutes
- ai_generated boolean on documents
- ai_outputs for draft review
- draft document action later

For now, focus on deterministic operations:
Project → Record → Activity → Document → PDF → Stats

Final expected outcome:
After implementation, the system should behave like this:

Admin opens company
→ clicks project
→ sees real overview stats
→ creates contract
→ creates payment application
→ generates branded PDF
→ PDF appears in documents
→ activity timeline updates
→ dashboard/project stats update

This is the first complete operational loop.

Do not implement everything at once.
Start with:
1. Project Activity Timeline
2. Project Stats Service
3. Contracts connection
4. Payment Application workflow
5. Payment Application PDF generation
6. Documents hub integration

Ask me before moving to RFIs, Variations, Notices, AI, or advanced workflows.