# SureSign — Business Context

## What SureSign Does

SureSign is a UK construction contract administration platform. It helps construction companies (acting as main contractors) to:

- Administer main contracts with employers/clients
- Manage subcontract packages with subcontractors
- Track payment applications, variations, and extensions of time
- Prepare adjudication bundles
- Manage project correspondence and documents
- Sync project documents to a local Windows folder structure

The platform is designed for practical day-to-day contract administration, not generic project management.

---

## Key Entities

### Company (Organization)

The top-level entity. Represents a construction company using SureSign. All projects, users, and documents belong to a company.

Each company has:
- A name and slug
- One or more users
- One or more projects

### Project

A construction project administered by the company. Each project:
- Has a name and a project code (e.g. `SP-001`)
- Has a client (employer)
- Has a main contract
- Contains all modules: contracts, subcontracts, commercial, etc.
- Has its own storage folder structure created on initialization

### Trade Package

A distinct work package let to a subcontractor. Examples:
- Groundworks
- Brickwork
- M&E (Mechanical & Electrical)
- Roofing

Each trade package within a project:
- Has a unique package code (e.g. `GW`, `ME`)
- Has a full package reference
- Has 9 standard sub-folders for document management
- Can have a subcontract agreement document generated from a template

### Document / File Upload

Every file in SureSign is tracked as a `FileUpload` record (the raw file) and optionally a `Document` record (a structured document with metadata, version, type). Documents can be:
- Uploaded by users
- Generated from templates (PHPWord DOCX generation)

### Contract

The main contract between the company and the client. Stored under the `01_Contracts` module. Sub-types: main contract, subcontract, consultant agreement, supplier agreement.

---

## Modules

Each project contains these modules:

| Number | Module                 | Purpose                                              |
|--------|------------------------|------------------------------------------------------|
| 01     | Contracts              | Main and sub contracts, agreements                   |
| —      | Subcontracts           | Trade package management and subcontract documents   |
| 02     | Commercial             | Commercial correspondence and documents              |
| 03     | Payment Applications   | Interim and final payment applications               |
| 04     | Variations             | Variation orders and assessments                     |
| 05     | Notices                | Contractual notices (default, determination, etc.)   |
| 06     | RFIs                   | Requests for Information                             |
| 07     | Meetings               | Meeting minutes and agendas                          |
| 08     | QA Reports             | Quality assurance inspection reports                 |
| 09     | Snagging               | Snagging lists and defects                           |
| 10     | Closeout               | Project closeout documents                           |
| 11     | Adjudication           | Adjudication case bundles and correspondence         |
| 12     | Site Reports           | Site diary and site inspection reports               |
| 13     | AI Generated           | Documents generated via AI/prompt workflows          |
| 99     | General Documents      | Miscellaneous project documents                      |

---

## Subcontracts Module

The Subcontracts module is the primary area for trade package management.

### Workflow

1. Project is created and a list of trade packages is identified.
2. Trade packages are generated in bulk using the **Generate Trade Package Folder** feature.
3. For each package, documents are uploaded into the relevant sub-folder (tender enquiry, drawings, pricing, etc.).
4. When a subcontractor is selected, the subcontract agreement is generated from a template using the **Generate Package** feature.
5. The generated DOCX is stored in the package folder and mirrored to the local Windows folder.

### Sub-Folder Workflow per Package

| Stage | Folder                   | Typical Contents                            |
|-------|--------------------------|---------------------------------------------|
| 1     | 01 Tender Enquiry        | Invitation to tender, enquiry letter        |
| 2     | 02 Schedule of Documents | List of documents issued with enquiry       |
| 3     | 03 Drawings              | Relevant drawings issued to tenderer        |
| 4     | 04 Specifications        | Technical specifications                    |
| 5     | 05 Pricing Documents     | Bills of quantities, schedule of rates      |
| 6     | 06 Contract Draft        | Draft subcontract agreement                 |
| 7     | 07 Correspondence        | Pre-contract correspondence                 |
| 8     | 08 Returned Tender       | Tenderer's submission                       |
| 9     | 09 Executed Contract     | Signed, executed subcontract agreement      |

---

## Standard Trade Packages

The platform ships with a pre-defined list of standard trade packages common in UK construction:

| Package Name            | Code | Notes                              |
|-------------------------|------|------------------------------------|
| Concrete Frame          | CF   | In-situ or precast concrete frames |
| Brickwork               | BW   | Facing and common brickwork        |
| Windows & Doors         | WD   | Aluminium, timber, or UPVC         |
| Roofing                 | RF   | Flat and pitched roofing           |
| M&E                     | ME   | Mechanical & Electrical            |
| Groundworks             | GW   | Excavation, foundations, drainage  |
| Drylining & Plastering  | DP   | Internal partitions and finishes   |
| Steelwork               | ST   | Structural steel                   |
| Landscaping             | LS   | External hard and soft landscaping |
| Demolition              | DM   | Demolition and strip-out           |
| Fire Stopping           | FS   | Passive fire protection            |
| External Works          | EW   | External hard standings, kerbs     |
| Access Control          | AC   | Door access control systems        |
| CCTV                    | CC   | CCTV and security systems          |
| Solar PV                | SP   | Solar photovoltaic installations   |

Custom packages can also be created. Their codes are auto-generated from initials (e.g. "Curtain Walling" → `CW`).

---

## Package Reference Format

When a trade package is created, a package reference is assigned. The intended format is:

```
{COMPANY_CODE}-{PROJECT_CODE}-{NUMBER}-{PACKAGE_CODE}
```

Example: `SP-001-GW` for a Groundworks package on project SP-001.

The `package_reference` field on the `trade_packages` table stores this reference. It appears on all generated documents (subcontract agreements, correspondence, etc.) to uniquely identify the package.

---

## Companies House Integration

When preparing subcontract documents, users can look up subcontractors on the UK Companies House register to pre-fill:
- Legal company name
- Company registration number
- Registered office address
- Company officers (for signing authority)

This uses the Companies House Public Data API (free API key required). The integration is a server-side proxy to avoid CORS and keep the API key secure.

---

## Prompt Library / AI Workflows

SureSign includes an admin-managed prompt library for AI-assisted drafting. This is a manual copy/paste workflow: prompts are crafted and stored by admins, and users can access them to draft documents using external AI tools. There is no direct AI API integration in the current version.

---

## Local Document Mirror

For construction teams that prefer working with files directly on Windows, SureSign can mirror all project documents to a local folder (e.g. `C:\Users\Admin\Documents\SureSign`). This is configured by a Super Admin and can be toggled at runtime without a deployment. The folder structure in the mirror matches the in-app module structure.

---

## Adjudication Support

SureSign includes an Adjudication module for managing adjudication cases. This covers:
- Case management (reference, appointing body, adjudicator details)
- Document bundles (referrals, responses, replies, supporting documents)
- Deadlines tracking
- Mirror sync to the `11_Adjudication` folder for easy access

Adjudication is a statutory right under the UK Housing Grants, Construction and Regeneration Act 1996. The module is designed to support quick, structured preparation of adjudication submissions.

---

## Notification Center

SureSign includes a user-facing Notification Center for tracking platform activity.

### Architecture
- Notifications are stored in the `suresign_notifications` table
- Each notification is user-specific: users only see their own notifications
- `NotificationService::send()` is the single entry point for creating notifications
- No real-time push in Phase 1 — the frontend polls the unread count every 60 seconds

### Notification Types
| Type | Trigger |
|------|---------|
| `file_uploaded` | File uploaded to a project folder |
| `file_deleted` | File soft-deleted from a project |
| `template_uploaded` | Document template uploaded via admin |
| `trade_package_generated` | Subcontract documents generated from template |
| `project_created` | New project created |
| `user_invited` | User invited to organization |
| `system` | System-level notifications |

### UI
- Bell icon in admin header with red badge for unread count
- Dropdown panel (up to 10 recent, scrollable)
- Mark single / mark all as read
- `/admin/notifications` full page with filter tabs
