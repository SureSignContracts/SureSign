You are continuing development on my existing SureSign platform.

Objective:
Implement a local filesystem-style SureSign Documents folder structure that mirrors the platform document hierarchy and supports Claude/Cowork manual file access.

The system should organize files like a PC Documents folder:

Documents/
└── SureSign/
    └── Company Name/
        └── Project Name/
            ├── 01_Contracts/
            │   ├── Main Contract/
            │   ├── Subcontracts/
            │   ├── Consultant Agreements/
            │   └── Supplier Agreements/
            ├── 02_Commercial/
            ├── 03_Payment Applications/
            ├── 04_Variations/
            ├── 05_Notices/
            ├── 06_RFIs/
            ├── 07_Meetings/
            ├── 08_QA Reports/
            ├── 09_Snagging/
            ├── 10_Closeout/
            ├── 11_Adjudication/
            ├── 12_Site Reports/
            └── 13_AI Generated/

Important:
Laravel storage should remain the source of truth.
Do not rely directly on the user’s Windows Documents folder as the only storage location.

Use:
storage/app/suresign/

Then optionally allow a configurable mirrored/export path such as:
C:/Users/{User}/Documents/SureSign/

or on Mac:
~/Documents/SureSign/

==================================================
PART 1 — FILE STORAGE PATH STRUCTURE
==================================================

When a company is created, prepare folder path:

suresign/{company_slug}/

When a project is created, auto-create:

suresign/{company_slug}/{project_slug}/01_Contracts/Main Contract
suresign/{company_slug}/{project_slug}/01_Contracts/Subcontracts
suresign/{company_slug}/{project_slug}/01_Contracts/Consultant Agreements
suresign/{company_slug}/{project_slug}/01_Contracts/Supplier Agreements
suresign/{company_slug}/{project_slug}/02_Commercial
suresign/{company_slug}/{project_slug}/03_Payment Applications
suresign/{company_slug}/{project_slug}/04_Variations
suresign/{company_slug}/{project_slug}/05_Notices
suresign/{company_slug}/{project_slug}/06_RFIs
suresign/{company_slug}/{project_slug}/07_Meetings
suresign/{company_slug}/{project_slug}/08_QA Reports
suresign/{company_slug}/{project_slug}/09_Snagging
suresign/{company_slug}/{project_slug}/10_Closeout
suresign/{company_slug}/{project_slug}/11_Adjudication
suresign/{company_slug}/{project_slug}/12_Site Reports
suresign/{company_slug}/{project_slug}/13_AI Generated

==================================================
PART 2 — MAIN CONTRACT AND SUBCONTRACT UPLOAD
==================================================

Update Contracts module to support contract file types:

Contract upload type:
- Main Contract
- Subcontract
- Consultant Agreement
- Supplier Agreement
- Other Contract Document

When creating or editing a contract, allow file upload.

Fields:
- contract title
- reference number
- contract type
- contract document type
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
- upload file

If contract document type = Main Contract:
Save file to:
01_Contracts/Main Contract/

If contract document type = Subcontract:
Save file to:
01_Contracts/Subcontracts/

If Consultant Agreement:
Save to:
01_Contracts/Consultant Agreements/

If Supplier Agreement:
Save to:
01_Contracts/Supplier Agreements/

==================================================
PART 3 — DOCUMENT METADATA
==================================================

Ensure uploaded files store metadata:

- organization_id
- project_id
- contract_id nullable
- uploaded_by
- title
- file_name
- original_name
- file_path
- file_size
- mime_type
- module_key
- folder_key
- document_type
- contract_document_type
- source_type
- source_id
- status

Suggested values:

module_key = contracts
folder_key = contracts/main_contract
folder_key = contracts/subcontracts
folder_key = contracts/consultant_agreements
folder_key = contracts/supplier_agreements

contract_document_type values:
- main_contract
- subcontract
- consultant_agreement
- supplier_agreement
- other

==================================================
PART 4 — ADMIN DOCUMENTS EXPLORER
==================================================

Update Admin Documents page to support Folder Explorer view:

Admin Documents
→ Companies
→ Projects
→ Module Folders
→ Files

Example:

Eagle Co
→ Test Project
→ 01_Contracts
   → Main Contract
   → Subcontracts
   → Consultant Agreements
   → Supplier Agreements

Files uploaded in Contracts module should automatically appear here.

Also keep existing List View.

==================================================
PART 5 — PROJECT DOCUMENTS EXPLORER
==================================================

Update Project Documents page to show folder-style view:

Project Documents
├── 01_Contracts
│   ├── Main Contract
│   ├── Subcontracts
│   ├── Consultant Agreements
│   └── Supplier Agreements
├── 02_Commercial
├── 03_Payment Applications
├── 04_Variations
├── 05_Notices
├── 06_RFIs
├── 07_Meetings
├── 08_QA Reports
├── 09_Snagging
├── 10_Closeout
├── 11_Adjudication
├── 12_Site Reports
└── 13_AI Generated

When clicking a folder:
Show only files in that folder_key.

==================================================
PART 6 — CLAUDE/COWORK COMPATIBILITY
==================================================

The purpose of this folder structure is to make the SureSign document library compatible with manual Claude/Cowork workflows.

Users should be able to connect Claude/Cowork to:

Documents/SureSign/

Then Claude can access:

Company
→ Project
→ Contracts
→ Subcontracts
→ Adjudication
→ AI Generated

Add an AI Generated folder per project for files produced manually by Claude/Cowork.

Example:
13_AI Generated/
    contract-summary.docx
    variation-claim-draft.docx
    adjudication-response-analysis.docx
    notice-of-dispute-draft.docx

==================================================
PART 7 — OPTIONAL MIRROR/EXPORT PATH
==================================================

Add a setting for local export/mirror path:

System Settings:
SureSign Local Documents Path

Example:
C:/Users/Admin/Documents/SureSign

or:
~/Documents/SureSign

Important:
This should be optional.
Primary storage must still be Laravel storage.

If enabled:
When files are uploaded to SureSign storage, also copy/mirror them into the local export path using the same folder structure.

If disabled:
Only store files inside Laravel storage.

==================================================
PART 8 — ACTIVITY LOG
==================================================

Every upload should create project activity.

Examples:
- Main contract uploaded
- Subcontract uploaded
- Consultant agreement uploaded
- Supplier agreement uploaded
- File uploaded to Adjudication
- Claude-generated document added

Activity should include:
- project_id
- organization_id
- user_id
- activity_type
- title
- description
- module_key
- related file/document id

==================================================
PART 9 — EMPTY STATES
==================================================

Contracts module:
If no main contract uploaded, show:

“No main contract uploaded yet. Uploading the main contract is recommended because payment, variation, notice, and adjudication workflows depend on contract terms.”

Subcontracts folder:
“No subcontracts uploaded yet.”

AI Generated folder:
“No AI-generated documents added yet. Files created from Claude/Cowork can be saved here.”

==================================================
PART 10 — SECURITY
==================================================

Do not expose files across organizations.

Rules:
- Super Admin can view all companies/projects/files
- Admin can view allowed organization/project files
- Client can only view permitted project files
- download routes must verify access
- file paths must not be user-controlled raw input
- sanitize folder/file names
- prevent path traversal

==================================================
FINAL EXPECTED RESULT
==================================================

After implementation:

1. Project creation auto-creates SureSign folder structure.
2. Contracts module supports Main Contract and Subcontract uploads.
3. Uploaded files are stored in organized company/project/module folders.
4. Admin Documents page shows PC-style explorer:
   Company → Project → Module Folder → Files.
5. Project Documents page also shows module folders.
6. Claude/Cowork can be pointed to the SureSign folder to read and save generated files.
7. AI Generated folder exists for manual Claude outputs.
8. Laravel storage remains the source of truth.
9. Optional local Documents/SureSign mirror can be configured later.