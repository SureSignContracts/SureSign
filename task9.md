You are continuing development on my existing SureSign platform.

Context:
SureSign is a Laravel 11 + Next.js 14 construction operations, contract administration, document management, adjudication, and project workflow platform.

The platform already has:
- Admin side
- Company management
- Project management
- Project workspaces
- Contracts module
- Documents module
- File uploads
- Generated documents
- Project folders
- Project activity logging
- Organization/company scoping
- Project module pages

Current issue:
The Admin Documents page currently shows a flat list of uploaded documents across the platform. This becomes difficult to manage as more companies, projects, modules, and documents are added.

New objective:
Upgrade the Documents system into a structured document explorer similar to a PC file system.

The document hierarchy should be:

Admin Documents
→ Companies
→ Projects
→ Project Module Folders
→ Files

Example:

Documents
├── Eagle Co
│   └── Test Project (PRJ-32322)
│       ├── Contracts
│       ├── Commercial
│       ├── Payment Applications
│       ├── Variations
│       ├── Notices
│       ├── Adjudication
│       ├── RFIs
│       ├── Meetings
│       ├── QA Reports
│       ├── Snagging
│       ├── Closeout
│       ├── Site Reports
│       └── General Documents

Important:
Do NOT remove the existing list/table view.
Add a Folder Explorer View and keep the existing List View as an alternative.

==================================================
PART 1 — ADMIN DOCUMENTS EXPLORER
==================================================

Update the Admin Documents page:

/admin/documents

Add two view modes:

1. Folder View
2. List View

List View:
- Keep the current table of all documents
- Continue supporting search
- Continue showing file name, company, project, size, uploaded date

Folder View:
Show a file explorer-style hierarchy.

Level 1:
Companies / Organizations

Example cards/folders:
- Eagle Co
- SureSign
- ABC Construction

Each company folder should show:
- company name
- number of projects
- total files
- total storage used

When clicking a company:
Show project folders for that company.

Level 2:
Projects

Example:
- Test Project (PRJ-32322)
- Sample Project (SS-002)

Each project folder should show:
- project name
- project code
- total files
- storage size
- last uploaded date

When clicking a project:
Show project module folders.

Level 3:
Module Folders

Required module folders:
- Contracts
- Commercial
- Payment Applications
- Variations
- Notices
- Adjudication
- RFIs
- Meetings
- QA Reports
- Snagging
- Closeout
- Site Reports
- General Documents

Each module folder should show:
- folder/module name
- number of files
- last updated date

When clicking a module folder:
Show all files uploaded/generated under that company + project + module.

Level 4:
Files

Show:
- file name
- document title
- file type
- size
- uploaded by
- uploaded date
- source module
- actions

Actions:
- view/download
- copy link if available
- archive/delete if allowed
- open related project/module record if linked

==================================================
PART 2 — DOCUMENT RECORD STRUCTURE
==================================================

Audit existing Document and FileUpload models.

Add or ensure these fields exist where appropriate:

- organization_id
- project_id
- uploaded_by / created_by
- file_name
- file_path
- file_size
- mime_type
- title
- document_type
- category
- module_key
- folder_key
- source_type nullable
- source_id nullable
- documentable_type nullable
- documentable_id nullable
- status
- uploaded_at / created_at

Add module_key or folder_key if missing.

Suggested module_key values:
- contracts
- commercial
- payment_applications
- variations
- notices
- adjudication
- rfis
- meetings
- qa_reports
- snagging
- closeout
- site_reports
- general

This is important so uploaded files from any module can automatically appear in the correct Admin Documents folder.

==================================================
PART 3 — MODULE-BASED FILE UPLOADS
==================================================

Every project module should eventually support file uploads.

When a file is uploaded inside a module, it should automatically be tagged with:

- organization_id
- project_id
- module_key
- folder_key
- uploaded_by
- related record if applicable

Example:
If a user uploads a file inside Variations module:

The file should be saved with:
module_key = variations
folder_key = variations
project_id = current project
organization_id = current company
source_type = Variation
source_id = selected variation id if available

Then it should appear in:

Admin Documents
→ Eagle Co
→ Test Project
→ Variations
→ file.pdf

And also inside:
Project Workspace
→ Variations
→ related variation record

==================================================
PART 4 — CONTRACT-FIRST PROJECT WORKFLOW
==================================================

Important business requirement:
The client/administrator should upload the main contract early because the contract contains the important project terms, payment rules, notices, variation procedures, and dispute clauses.

Update the project setup workflow so uploading the main contract is strongly encouraged.

After creating a project, show a setup checklist:

Project Setup Checklist:
1. Project details completed
2. Main contract uploaded
3. Contract value entered
4. Key dates entered
5. Payment terms entered

On Project Overview, show a warning/notice if no main contract file exists:

“No main contract uploaded yet. Upload the main contract to enable accurate payment, variation, notice, and adjudication workflows.”

Add CTA:
Upload Main Contract

When clicked:
Open a modal to create/upload the main contract.

==================================================
PART 5 — CONTRACT MODULE UPLOAD FLOW
==================================================

Update the Contracts module so creating a contract can include file upload.

Current flow:
Create Contract Record

New flow:
Create Contract Record + Upload Contract File

Contract form should support:
- contract title
- reference number
- contract type
- form of contract
- party name
- contract sum
- currency
- retention percentage
- payment terms
- execution date
- commencement date
- completion date
- status
- notes
- upload contract file

File upload should support:
- PDF
- DOC
- DOCX
- XLS
- XLSX
- image if needed

When contract is saved:
1. Create contract record
2. Upload file if provided
3. Link file/document to contract
4. Save under module_key = contracts
5. Save under folder_key = contracts
6. Show file in Contracts module
7. Show file in Project Documents
8. Show file in Admin Documents Explorer
9. Create project activity:
   “Main contract uploaded”
   or
   “Contract file uploaded”

If no file is uploaded:
Allow saving contract record,
but show reminder:
“No contract file uploaded. Uploading the contract is recommended.”

==================================================
PART 6 — PROJECT DOCUMENTS PAGE UPDATE
==================================================

Update Project Documents page:

/app/projects/{project}/documents

It should also support:
- Folder View
- List View

Folder View should show project module folders:
- Contracts
- Commercial
- Payment Applications
- Variations
- Notices
- Adjudication
- RFIs
- Meetings
- QA Reports
- Snagging
- Closeout
- Site Reports
- General Documents

When clicking a folder:
Show files in that module.

List View:
Show all project files/documents regardless of module.

Upload button should allow selecting:
- Module/folder
- Document type
- Related record if applicable
- File

==================================================
PART 7 — RELATED RECORD LINKING
==================================================

When uploading a document, allow optional related record selection.

Example:
If module_key = variations:
Show variation selector.

If module_key = payment_applications:
Show payment application selector.

If module_key = adjudication:
Show adjudication case selector.

If module_key = contracts:
Show contract selector.

If module_key = rfis:
Show RFI selector.

If module_key = meetings:
Show meeting selector.

If module_key = qa_reports:
Show QA report selector.

If module_key = snagging:
Show snag item selector.

This allows documents to be both:
- stored in the right folder
- linked to the right operational record

==================================================
PART 8 — DOCUMENT EXPLORER API
==================================================

Create backend endpoints for document explorer.

Suggested endpoints:

Admin:
GET /api/admin/documents/explorer

Returns hierarchical summary:
companies → projects → module folders → file counts

GET /api/admin/documents/explorer/company/{organization}
GET /api/admin/documents/explorer/project/{project}
GET /api/admin/documents/explorer/project/{project}/module/{moduleKey}

Project:
GET /api/projects/{project}/documents/explorer
GET /api/projects/{project}/documents/module/{moduleKey}

Optional:
GET /api/projects/{project}/documents/summary

Response example:

{
  "companies": [
    {
      "id": 1,
      "name": "Eagle Co",
      "projects_count": 3,
      "files_count": 42,
      "storage_size": 10485760
    }
  ]
}

Project module folder response:

{
  "project": {
    "id": 3,
    "name": "Test Project",
    "code": "PRJ-32322"
  },
  "folders": [
    {
      "key": "contracts",
      "name": "Contracts",
      "files_count": 3,
      "last_updated": "2026-05-27"
    },
    {
      "key": "variations",
      "name": "Variations",
      "files_count": 5,
      "last_updated": "2026-05-27"
    }
  ]
}

Files response:

{
  "files": [
    {
      "id": 1,
      "title": "Main Contract",
      "file_name": "main-contract.pdf",
      "module_key": "contracts",
      "folder_key": "contracts",
      "size": 204800,
      "uploaded_by": "Graham Cashin",
      "uploaded_at": "2026-05-27",
      "download_url": "/api/documents/1/download"
    }
  ]
}

==================================================
PART 9 — UI DESIGN FOR EXPLORER
==================================================

Use file explorer style.

Folder card:
- folder icon
- title
- file count
- storage size
- last updated

Breadcrumbs:
Documents
→ Eagle Co
→ Test Project
→ Contracts

Add breadcrumb navigation so user can move back easily.

Add search:
- search file name
- search project
- search company
- search module

Add filters:
- file type
- module
- uploaded by
- date range

Keep UI simple and clean.

Do not overbuild into full Google Drive clone.

==================================================
PART 10 — ACTIVITY LOG INTEGRATION
==================================================

Every upload should create project activity.

Examples:
- “Main contract uploaded”
- “Document uploaded to Variations”
- “Adjudication evidence uploaded”
- “Payment Application attachment uploaded”

Activity should include:
- project_id
- organization_id
- user_id
- activity_type
- title
- description
- related document/file id
- module_key if available

==================================================
PART 11 — IMPORTANT RELATIONSHIP RULES
==================================================

All uploaded files must belong to:
- organization_id
- project_id

Files uploaded inside modules should also include:
- module_key
- folder_key

Files linked to records should include:
- source_type/source_id
or:
- documentable_type/documentable_id

Do not allow cross-organization document access.

Admin/Super Admin can view across organizations.
Client users can only view documents for their assigned project/organization.

==================================================
PART 12 — CONTRACT UPLOAD IMPORTANCE
==================================================

The system should treat the main contract as a critical project document.

Use the contract file later for:
- contract summary prompts
- payment application checks
- variation procedure review
- notice requirements
- adjudication evidence
- document generation
- future AI contract analysis

For now:
Do not run AI.
Do not analyze contract automatically.
Just ensure the contract file is uploaded, stored, linked, and easy to find.

==================================================
PART 13 — EMPTY STATES
==================================================

Admin Documents empty state:
“No documents uploaded yet. Documents uploaded from project modules will appear here automatically.”

Company folder empty state:
“No project documents found for this company.”

Project folder empty state:
“No documents uploaded for this project yet. Upload the main contract to get started.”

Contracts folder empty state:
“No contract uploaded yet. The main contract should be uploaded before payment, variation, notice, and adjudication workflows.”

==================================================
FINAL EXPECTED RESULT
==================================================

After implementation:

Admin can open:
/admin/documents

And see:

Companies
→ Projects
→ Module folders
→ Files

Users can upload files from project modules.

Example:
Upload contract in Contracts module.

It appears automatically in:
- Contracts module
- Project Documents page
- Admin Documents Explorer
- Company folder
- Project folder
- Contracts folder

The system becomes organized, searchable, and easy to navigate like a PC document folder structure.

Do not remove current flat list view.
Add folder/explorer view alongside it.

Do not integrate AI yet.
This task is about document organization, contract upload workflow, file linking, and discoverability.