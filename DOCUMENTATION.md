# SureSign — Complete Platform Documentation

> **White-label Construction Contract Administration & AI Automation Platform**

---

## Table of Contents

1. [Project Overview](#1-project-overview)
2. [Technology Stack](#2-technology-stack)
3. [Architecture](#3-architecture)
4. [Project Structure](#4-project-structure)
5. [Database Schema](#5-database-schema)
6. [Backend — Laravel API](#6-backend--laravel-api)
7. [Frontend — Next.js App](#7-frontend--nextjs-app)
8. [Authentication & Roles](#8-authentication--roles)
9. [Modules & Workflows](#9-modules--workflows)
10. [AI Integration](#10-ai-integration)
11. [Docker & Setup](#11-docker--setup)
12. [Environment & Configuration](#12-environment--configuration)

---

## 1. Project Overview

SureSign is a **white-label, multi-tenant Construction Operations and Contract Administration Platform**. It is designed to feel and function like enterprise construction software (Procore, Aconex, Autodesk Construction Cloud) while remaining a locally-deployable, modular SaaS platform.

### What it does

- Allows construction companies (tenants) to manage their **projects**, **contracts**, **commercial workflows**, **site administration**, and **documents** in one place.
- Automates repetitive construction paperwork (RFIs, Payment Applications, Variations, EOTs, Site Diaries, Meeting Minutes).
- Provides an **AI automation layer** for document drafting, meeting summarisation, and variation analysis.
- Supports **white-label branding** — each tenant organisation can customise colours, logos, and letterheads.
- Offers **document generation** with templated PDF output per organisation.
- Has a **Super Admin** control panel for platform-wide management of all tenant companies.

### Core Design Philosophy

- **Multi-tenant**: Every piece of data is scoped to an `organization_id`.
- **Project-centric**: All operational work lives inside a project hierarchy.
- **AI-invisible**: AI assists in the background; users never feel like they're using an AI tool.
- **Deployment-ready**: Local-first but architected for future AWS/DigitalOcean/Docker cloud migration.

---

## 2. Technology Stack

| Layer | Technology |
|---|---|
| **Backend** | Laravel 11 (PHP 8.2+) |
| **Authentication** | Laravel Sanctum (token-based) |
| **Authorization** | Spatie Laravel Permission (RBAC) |
| **Database** | MySQL 8.0 |
| **Cache / Queues** | Redis 7 |
| **Frontend** | Next.js 14 + TypeScript |
| **Styling** | TailwindCSS + CSS Variables (theming) |
| **State Management** | Zustand (with localStorage persistence) |
| **Data Fetching** | TanStack React Query v5 |
| **HTTP Client** | Axios |
| **Containerisation** | Docker + Docker Compose |
| **Reverse Proxy** | Nginx (Alpine) |
| **PDF Generation** | DomPDF (via Laravel) — branding header/footer canvas injection for most documents; Payment Notices use inline self-contained layout (`skipCanvas = true`) |
| **Excel Generation** | PhpSpreadsheet — branded `.xlsx` workbooks for payment applications |
| **DOCX Generation** | PHPWord (`phpoffice/phpword`) |
| **DOCX → PDF Preview** | LibreOffice headless (`DocxToPdfService`) |
| **AI Provider** | Anthropic Claude API (`ClaudeAiProvider`) — opt-in, configured in admin settings |
| **Transactional Email** | Brevo (Sendinblue) API (`EmailNotificationService`) |
| **File Storage** | Local filesystem (cloud-ready via Laravel disks) |

---

## 3. Architecture

### System Levels

```
SUPER ADMIN (SureSign Internal)
        ↓
  ORGANISATION / TENANT (Construction Company)
        ↓
     PROJECT (Individual Construction Project)
        ↓
   PROJECT MODULES (Contracts, RFIs, Commercial, Documents…)
```

### User Flow

```
Browser → Nginx (port 80)
             ├── /           → Next.js Frontend (port 3000)
             └── /api/*      → Laravel Backend (port 8000)
                                    ↓
                             MySQL (port 3307)
                             Redis  (port 6379)
```

### Multi-Tenancy Model

- Every `User` belongs to one `Organization`.
- Every `Project` belongs to one `Organization`.
- All data (contracts, documents, RFIs, etc.) is scoped to `organization_id`.
- Super Admins can see all organisations; Admins/Clients see only their own.

---

## 4. Project Structure

```
SureSign/
├── docker-compose.yml          # Full Docker orchestration
├── README.md
├── task.md / task2.md / task3.md  # Architecture planning notes
├── docker/
│   └── nginx/
│       └── default.conf        # Nginx reverse proxy config
├── backend/                    # Laravel 11 API
│   ├── app/
│   │   ├── Http/Controllers/Api/
│   │   ├── Models/
│   │   └── Providers/
│   ├── config/
│   ├── database/
│   │   ├── migrations/
│   │   ├── seeders/
│   │   └── factories/
│   ├── routes/
│   │   ├── api.php             # All API route definitions
│   │   └── web.php
│   ├── storage/
│   ├── tests/
│   ├── Dockerfile
│   └── composer.json
└── frontend/                   # Next.js 15 App
    ├── src/
    │   ├── app/
    │   │   ├── login/          # Login page
    │   │   ├── app/            # Tenant user workspace
    │   │   │   ├── page.tsx         # Dashboard
    │   │   │   ├── projects/        # Project list + detail
    │   │   │   ├── commercial/      # Commercial overview
    │   │   │   ├── site/            # Site admin overview
    │   │   │   ├── documents/       # Document library
    │   │   │   ├── ai/              # AI assistant
    │   │   │   ├── company/         # Company profile
    │   │   │   ├── team/            # Team management
    │   │   │   ├── reports/         # Reports
    │   │   │   ├── settings/        # User settings
    │   │   │   └── onboarding/      # First-time setup wizard
    │   │   └── admin/          # Super Admin workspace
    │   │       ├── page.tsx         # Admin dashboard
    │   │       ├── companies/       # Tenant management
    │   │       ├── projects/        # All projects
    │   │       ├── users/           # User management
    │   │       ├── templates/       # Document templates
    │   │       ├── ai-configurations/
    │   │       ├── storage/
    │   │       ├── billing/
    │   │       ├── support/
    │   │       ├── system-logs/
    │   │       ├── documents/
    │   │       └── suresign/        # Platform branding & settings
    │   ├── components/
    │   │   ├── layout/
    │   │   │   ├── AppSidebar.tsx   # Tenant sidebar
    │   │   │   ├── AdminSidebar.tsx # Admin sidebar
    │   │   │   └── Sidebar.tsx      # Legacy sidebar
    │   │   ├── auth/
    │   │   ├── dashboard/
    │   │   ├── projects/
    │   │   └── ui/
    │   ├── store/
    │   │   └── authStore.ts    # Zustand auth state (persisted)
    │   ├── lib/
    │   │   ├── api.ts          # Axios instance + interceptors
    │   │   └── utils.ts
    │   ├── hooks/
    │   │   └── useTheme.ts     # Dark/light theme toggle
    │   └── types/
    ├── next.config.ts
    ├── tailwind.config.ts
    └── Dockerfile
```

---

## 5. Database Schema

### Entity Relationship Overview

```
organizations
    ├── branding_settings (1:1)
    ├── users (1:many)
    ├── clients (1:many)
    └── projects (1:many)
            ├── project_users  (pivot: user ↔ project)
            ├── project_contacts
            ├── project_folders
            ├── project_activities
            ├── trade_packages (1:many)
            │       └── trade_package_folders
            ├── contracts (1:many)
            │       ├── contract_ai_analyses
            │       ├── contract_programme_milestones
            │       ├── payment_applications
            │       │       ├── pay_less_notices
            │       │       ├── payment_notices
            │       │       └── retention_releases
            │       ├── variations
            │       └── eot_requests
            ├── rfis
            ├── site_instructions
            ├── site_diaries
            ├── meeting_minutes
            ├── documents
            │       ├── document_versions
            │       └── document_approvals
            ├── document_registers
            ├── document_number_sequences
            ├── file_uploads
            ├── ai_conversations
            │       ├── ai_messages
            │       └── ai_outputs
            ├── workflow_instances
            │       └── workflow_step_instances
            └── reports

suresign_settings (singleton — platform-wide)
suresign_notifications (per-user in-app notifications)
activity_logs (platform-wide audit trail)
workflows / workflow_steps (definitions/templates)
document_templates
prompt_templates / prompt_categories
audit_logs
```

---

### Table Definitions

#### `organizations`
Represents a tenant construction company.

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| name | string | Company name |
| slug | string unique | URL-safe identifier |
| email / phone / website | string | Contact details |
| address / city / state / postcode / country | string | Registered address |
| abn / acn | string | Australian Business/Company Number |
| contact_name | string | Primary contact |
| is_active | boolean | Active status |
| is_onboarded | boolean | Completed onboarding wizard |
| deleted_at | timestamp | Soft-delete |

---

#### `users`

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| organization_id | FK → organizations | Nullable (Super Admin has no org) |
| name | string | Full name |
| first_name / last_name | string | Split name fields |
| email | string unique | Login credential |
| phone / job_title / avatar | string | Profile fields |
| address / city / province / postal_code / country | string | |
| password | string | Bcrypt hashed |
| is_active | boolean | |
| last_login_at | timestamp | |
| deleted_at | timestamp | Soft-delete |

Roles are managed by **Spatie Permission** tables (`roles`, `permissions`, `model_has_roles`, `model_has_permissions`).

---

#### `branding_settings`
Per-organisation white-label configuration.

| Column | Type | Notes |
|---|---|---|
| organization_id | FK | |
| logo_path / logo_dark_path / favicon_path | string | Asset paths |
| cover_image_path | string | Cover image |
| letterhead_path / header_template_path / footer_template_path | string | Document branding |
| primary_color | string | Default `#B99566` (gold) |
| secondary_color / accent_color | string | |
| font_family | string | Default `Inter` |
| company_display_name / tagline / description | string/text | |
| email_footer | text | |
| signature_path | string | |

---

#### `clients`
External client companies linked to an organisation.

| Column | Type | Notes |
|---|---|---|
| organization_id | FK | |
| name | string | Client company name |
| abn | string | |
| contact_name / contact_email / contact_phone | string | |
| address | string | |
| status | string | `active`, `inactive` |

---

#### `projects`

| Column | Type | Notes |
|---|---|---|
| organization_id | FK | |
| client_id | FK → clients | Associated client |
| created_by | FK → users | |
| name / code / description | string | |
| status | string | `active`, `on_hold`, `completed`, `cancelled` |
| type | string | `new_build`, `refurbishment`, `fitout`, `infrastructure`, `other` |
| contract_type | string | `JCT`, `NEC3`, `NEC4`, `FIDIC`, `bespoke`, `other` |
| contract_value | decimal(15,2) | |
| currency | string | Default `AUD` |
| retention_percentage / retention_cap_percentage | decimal | |
| payment_terms_days | integer | Default 30 |
| start_date / end_date / practical_completion_date | date | |
| address / city / state / postcode / country | string | Site address |
| metadata | json | |

---

#### `project_users` (pivot)
| Column | Notes |
|---|---|
| project_id / user_id | Composite unique |
| role | `project_manager`, `contract_admin`, `site_manager`, `member`, `observer` |

#### `project_contacts`
External party contacts per project (clients, contractors, consultants, suppliers).

#### `project_folders`
Virtual folder hierarchy auto-created per project for the file library.

---

#### `contracts`

| Column | Type | Notes |
|---|---|---|
| project_id / organization_id / created_by | FK | |
| type | string | `main_contract`, `subcontract`, `consultant_appointment`, `supplier_agreement` |
| title / reference_number | string | |
| form_of_contract | string | `JCT SBC`, `NEC4`, `FIDIC`, `bespoke`, etc. |
| party_name | string | The other contracting party |
| contract_sum | decimal(15,2) | |
| retention_percentage / retention_cap_percentage | decimal | |
| payment_terms_days | integer | |
| due_date_offset_days | integer | Days from application date → due date |
| final_date_offset_days | integer | Days from due date → final date for payment |
| payment_notice_offset_days | integer | Days from due date → payment notice deadline |
| pay_less_notice_offset_days | integer | Days before final date → pay-less notice deadline |
| execution_date / commencement_date / completion_date | date | |
| status | string | `draft`, `active`, `on_hold`, `completed`, `terminated`, `disputed` |
| notes | text | |
| key_dates / key_obligations | json | |

---

#### `contract_ai_analyses`
Results of AI contract document analysis.

| Column | Type | Notes |
|---|---|---|
| contract_id / organization_id / project_id / file_upload_id / created_by | FK | |
| status | string | `pending`, `processing`, `completed`, `confirmed`, `failed` |
| provider | string | e.g. `anthropic` |
| model | string | e.g. `claude-3-5-sonnet-latest` |
| document_hash | string | SHA of the analysed file |
| summary | text | Human-readable summary |
| raw_response_json | json | Full structured AI response |
| raw_response_text | text | Raw AI text output |
| stop_reason | string | `end_turn` or `max_tokens` |
| confirmed_data_json | json | Admin-confirmed subset used for payment date calculations |
| error_message | text | |
| tokens_input / tokens_output | integer | |
| estimated_cost | float | |
| started_at / completed_at | timestamp | |

---

#### `contract_programme_milestones`
Programme timeline entries for a contract.

| Column | Type | Notes |
|---|---|---|
| contract_id / project_id | FK | |
| title | string | Milestone name |
| milestone_date | date | |
| type | string | e.g. `commencement`, `completion`, `sectional_completion`, `key_milestone` |
| notes | text | |
| seeded_from_ai | boolean | True if auto-seeded from confirmed AI analysis |

---

#### `trade_packages`
Subcontract work packages within a project.

| Column | Type | Notes |
|---|---|---|
| project_id / organization_id / created_by | FK | |
| name | string | Package name (e.g. "Groundworks") |
| code | string | Short code (e.g. `GW`) |
| package_reference | string | Full reference for document numbering |
| status | string | |

#### `trade_package_folders`
Standard sub-folders auto-created for each trade package.

---

#### `payment_applications`
Interim payment certificates / applications. Can be raised against a main contract **or** a trade package.

| Column | Notes |
|---|---|
| contract_id / trade_package_id / project_id / created_by | FK — one of contract_id or trade_package_id is set |
| application_number | integer |
| application_date | date |
| valuation_period_start / valuation_period_end | date |
| due_date / final_date_for_payment | date — statutory dates from PaymentDateService |
| payment_notice_deadline / pay_less_notice_deadline | date — statutory deadlines |
| gross_valuation / less_retention / less_previous_payments / amount_due | decimal |
| previous_certified_value / previous_paid_value / previous_retention_held | decimal — carried forward |
| less_previous_payments | decimal |
| certified_amount / certified_date / payment_date / paid_amount | decimal / date |
| status | `draft`, `submitted`, `payment_notice_issued`, `pay_less_notice_issued`, `certified`, `paid`, `cancelled` |
| submitted_at / submitted_by | timestamp / FK — set on submit, cleared on withdraw |
| withdrawal_count | integer (default 0) — incremented each time a submitted application is withdrawn |
| withdrawn_at / withdrawn_by / withdrawal_reason | timestamp / FK / string — most recent withdrawal metadata |
| breakdown | json (line items) |
| deleted_at | timestamp — soft-delete (draft/cancelled only) |

#### `pay_less_notices`
Issued against a payment application.

| Column | Notes |
|---|---|
| payment_application_id / project_id / created_by | FK |
| notice_date | date |
| notified_sum | decimal |
| basis_of_difference | text |
| status | `draft`, `issued`, `disputed` |

#### `payment_notices`
Standalone payment notices (separate from pay-less notices).

| Column | Notes |
|---|---|
| payment_application_id / project_id / created_by | FK |
| notice_date | date |
| notified_sum | decimal |
| notes | text |

#### `retention_releases`
Tracks partial or full releases of retention held.

| Column | Notes |
|---|---|
| payment_application_id / contract_id / project_id / created_by | FK |
| release_date | date |
| amount_released | decimal |
| type | `practical_completion`, `making_good_defects`, `partial`, `other` |
| notes | text |

#### `variations`
Contract variation orders.

| Column | Notes |
|---|---|
| contract_id / project_id / created_by | FK |
| variation_number | integer |
| title / description | string / text |
| instruction_type | `addition`, `omission`, `substitution`, `provisional_sum`, `daywork` |
| quoted_amount / agreed_amount | decimal |
| programme_impact_days | integer |
| status | `pending`, `submitted`, `approved`, `rejected`, `on_hold` |

---

#### `rfis`
Requests for Information.

| Column | Notes |
|---|---|
| project_id / created_by / assigned_to | FK |
| rfi_number | integer |
| subject | string |
| query / response | text |
| status | `open`, `pending_response`, `responded`, `closed` |
| priority | `low`, `normal`, `high`, `urgent` |
| date_raised / response_required_by / responded_at | date |
| programme_impact | boolean |
| programme_impact_days / cost_impact_amount | integer / decimal |

#### `site_instructions`
Formal site instructions issued to contractors.

| Column | Notes |
|---|---|
| instruction_number | integer |
| title / description | string / text |
| type | `variation`, `safety`, `quality`, `design`, `general`, `urgent` |
| issued_to / issued_date / compliance_by_date | string / date |
| status | `issued`, `acknowledged`, `complied`, `disputed` |

#### `site_diaries`
Daily site diary entries.

| Column | Notes |
|---|---|
| diary_date | date |
| weather | string |
| workers_on_site | integer |
| works_carried_out / delays_and_disruptions / visitors | text |
| health_safety_observations / materials_delivered / notes | text |
| status | `draft`, `submitted`, `approved` |

#### `meeting_minutes`

| Column | Notes |
|---|---|
| meeting_number | integer |
| title / type | string (`progress`, `design`, `commercial`, `safety`, `subcontractor`, `other`) |
| meeting_date / location | date / string |
| attendees / action_items | json |
| agenda / minutes | text |
| ai_summary | text (AI-generated) |
| status | `draft`, `issued`, `approved` |

#### `eot_requests`
Extension of Time requests.

| Column | Notes |
|---|---|
| eot_number | integer |
| title / grounds | string / text |
| days_claimed / days_granted | integer |
| event_date / notice_date / assessment_date | date |
| status | `submitted`, `under_review`, `granted`, `partially_granted`, `rejected`, `disputed` |
| loss_and_expense_claim | boolean |
| loss_and_expense_amount | decimal |

---

#### `documents`
Generated/uploaded formal documents.

| Column | Notes |
|---|---|
| project_id / organization_id / created_by / template_id | FK |
| documentable_type / documentable_id | polymorphic (links to RFI, variation, etc.) |
| title / type / category / reference_number | string |
| status | `draft`, `pending_approval`, `approved`, `issued`, `superseded`, `archived` |
| file_path / file_name / mime_type / file_size | |
| version | integer |
| ai_generated | boolean |
| template_data | json |

#### `document_versions` — Version history per document.

#### `document_approvals` — Approval workflow per document with reviewer comments.

#### `document_templates`
Platform or org-level templates for generating documents.

| Column | Notes |
|---|---|
| organization_id | nullable FK (null = global template) |
| name / slug / category / type | string |
| content | longText (HTML template) |
| variables | json (available placeholders) |
| is_global / is_active | boolean |

#### `file_uploads`
Raw file uploads to the project file library.

| Column | Notes |
|---|---|
| project_id / organization_id / uploaded_by | FK |
| attachable_type / attachable_id | polymorphic |
| original_name / stored_name / file_path | string |
| mime_type / file_size | |
| folder_path | string (virtual folder path) |
| disk | string (default: `local`) |

---

#### `ai_conversations`

| Column | Notes |
|---|---|
| user_id / project_id / organization_id | FK |
| contextable_type / contextable_id | polymorphic (linked to any model) |
| title | string |
| type | `general`, `document_draft`, `meeting_summary`, `variation_analysis`, `report`, `rfi` |
| status | `active`, `archived` |
| token_count | integer |

#### `ai_messages`
Individual chat turns within a conversation.

| Column | Notes |
|---|---|
| ai_conversation_id | FK |
| role | `user`, `assistant`, `system` |
| content | longText |
| token_count | integer |
| metadata | json (model, temperature, etc.) |

#### `ai_outputs`
Saved AI-generated drafts awaiting human review.

| Column | Notes |
|---|---|
| type | `document_draft`, `meeting_summary`, `variation_summary`, `report`, `extraction` |
| title / content | string / longText |
| status | `pending_review`, `approved`, `rejected`, `used` |
| model_used / source_context | string / json |

---

#### `workflows` / `workflow_steps`
Reusable workflow definitions/templates.

| Workflow Type | Notes |
|---|---|
| `project_setup` | Steps run on project creation |
| `payment` | Payment application workflow |
| `variation` | Variation approval chain |
| `rfi` | RFI response workflow |
| `document_approval` | Multi-step document sign-off |
| `eot` | EOT assessment process |
| `custom` | Bespoke org-defined workflows |

#### `workflow_instances` / `workflow_step_instances`
Running workflow instances per project with step tracking and due dates.

---

#### `audit_logs`
Full audit trail of all user actions.

| Column | Notes |
|---|---|
| user_id / organization_id | FK |
| event | `created`, `updated`, `deleted`, `login`, `logout`, `exported`, `generated`, `approved` |
| auditable_type / auditable_id | polymorphic |
| old_values / new_values | json (before/after) |
| ip_address / user_agent / url | string |

---

#### `reports`
| Column | Notes |
|---|---|
| type | `progress`, `commercial`, `monthly`, `weekly`, `final`, `custom` |
| period_start / period_end | date |
| content | longText |
| status | `draft`, `issued`, `archived` |
| ai_assisted | boolean |

---

#### `document_registers`
Project-level document register entries for formal document tracking.

#### `document_number_sequences`
Atomic counters per project/package/type, used by `DocumentNumberService` to generate unique document numbers (e.g. `SP-COL-001-RF-RFI-015`).

#### `suresign_notifications`
In-app user notifications.

| Column | Notes |
|---|---|
| user_id | FK |
| type | e.g. `file_uploaded`, `document_generated`, `ai_analysis_completed`, `payment_deadline_approaching` |
| title / message | string / text |
| is_read | boolean |
| data | json (extra context) |

#### `activity_logs`
Platform-wide audit trail for all significant user actions.

| Column | Notes |
|---|---|
| user_id / organization_id / project_id | FK (nullable) |
| action | string (e.g. `contract.created`, `payment_application.certified`) |
| description | text |
| ip_address / user_agent | string |

#### `project_activities`
Per-project activity feed (uploads, document generation, status changes, etc.).

| Column | Notes |
|---|---|
| project_id / organization_id / user_id | FK |
| activity_type | string |
| title / description | string / text |

#### `prompt_templates` / `prompt_categories`
Admin-editable prompt library for the manual copy/paste AI workflow.

| Column | Notes |
|---|---|
| category_id | FK → prompt_categories |
| name / slug | string |
| prompt_text | longText (with `{{placeholder}}` variables) |
| is_global | boolean |
| is_active | boolean |

---

#### `suresign_settings` (singleton)
Platform-wide settings for the SureSign instance itself (not per-tenant).

| Column | Notes |
|---|---|
| logo_path | Platform logo |
| letterhead_header_path / letterhead_footer_path / letterhead_pdf_path | PDF branding |
| email_header_path / email_footer_path | Email branding |
| email_reply_to / email_subject_line / email_body_template | Email defaults |
| brevo_api_key | Transactional email (Brevo/Sendinblue) |
| email_sender_name / email_sender_email | Sender identity fields |
| currency / currency_symbol / date_format / timezone | Locale settings |
| ai_enabled | boolean — toggles contract AI analysis feature platform-wide |
| anthropic_api_key | Anthropic Claude API key for contract analysis |
| ai_model | Claude model ID (e.g. `claude-3-5-sonnet-latest`) |
| notification_settings | json — array of enabled email event keys (e.g. `["payment_application.submitted"]`) |
| local_mirror_path | Container-side path for local Windows document mirror |

---

## 6. Backend — Laravel API

### Controllers (`app/Http/Controllers/Api/`)

| Controller | Responsibility |
|---|---|
| `AuthController` | Login, logout, `me`, password update |
| `DashboardController` | Org-scoped stats for tenant dashboard |
| `AdminController` | Platform-wide stats, document explorer, audit log for Super Admin |
| `OrganizationController` | Org CRUD, branding, logo/letterhead uploads, onboarding |
| `ProjectController` | Project CRUD, stats, folder tree, dashboard intelligence |
| `ContractController` | Contract CRUD, attach file, archive/restore |
| `PaymentApplicationController` | Full payment application lifecycle (create, submit, withdraw, certify, mark-paid, cancel, soft-delete, PDF/Excel generation, pay-less/payment notices, breakdown, defaults pre-fill) |
| `PaymentNoticeController` | Standalone payment notices |
| `RetentionReleaseController` | Retention release records |
| `PayLessNoticeController` | Pay Less Notices |
| `VariationController` | Variations, PDF generation |
| `ProgrammeMilestoneController` | Contract programme milestones, seed from AI analysis |
| `CalendarController` | Project calendar events (unified cross-module dates) |
| `TradePackageController` | Trade package CRUD, bulk folder generation |
| `RfiController` | RFIs (nested under projects) |
| `SiteInstructionController` | Site Instructions |
| `SiteDiaryController` | Site Diary entries |
| `MeetingMinutesController` | Meeting Minutes |
| `EotRequestController` | EOT Requests |
| `DocumentController` | Document generation, download, document register, file library |
| `AiController` | Contract AI analysis: start, get latest, list, confirm, cancel, re-parse, generate brief; also legacy AI conversations |
| `ClientController` | Client (company) CRUD |
| `UserController` | User CRUD (admin only) |
| `SuresignSettingController` | Platform settings CRUD, AI settings, notification settings, asset uploads |

### Route Protection

| Middleware | Applied to |
|---|---|
| `auth:sanctum` | All routes except `POST /auth/login` |
| `role:Super Admin\|Admin` | User management, organisation CRUD, all `/admin/*` routes |

### Key API Endpoints Summary

```
POST   /api/auth/login
POST   /api/auth/logout
GET    /api/auth/me

GET    /api/dashboard

GET    /api/projects
POST   /api/projects
GET    /api/projects/{id}
GET    /api/projects/{id}/stats
GET    /api/projects/{id}/dashboard-intelligence
GET    /api/projects/{id}/folders
GET    /api/projects/{id}/files
POST   /api/projects/{id}/files
GET    /api/projects/{id}/programme
GET    /api/projects/{id}/calendar-events
GET    /api/projects/{id}/ai-analyses
GET    /api/projects/{id}/payment-application-defaults
GET    /api/projects/{id}/payment-notices
GET    /api/projects/{id}/retention-releases
POST   /api/projects/{id}/retention-releases

# Contracts
GET/POST/PUT/DELETE  /api/projects/{project}/contracts
POST   /api/contracts/{contract}/attach-file
POST   /api/contracts/{contract}/archive
POST   /api/contracts/{contract}/restore
GET    /api/contracts/{contract}/programme
POST   /api/contracts/{contract}/programme
POST   /api/contracts/{contract}/programme/seed-from-analysis
PUT    /api/programme/{milestone}
DELETE /api/programme/{milestone}

# Contract AI Analysis
POST   /api/contracts/{contract}/ai-analysis
GET    /api/contracts/{contract}/ai-analysis
GET    /api/contracts/{contract}/ai-analyses
GET    /api/ai/status
GET    /api/ai/analyses/{analysis}
POST   /api/ai/analyses/{analysis}/confirm
POST   /api/ai/analyses/{analysis}/cancel
POST   /api/ai/analyses/{analysis}/reparse
POST   /api/ai/analyses/{analysis}/generate-brief

# Payment Applications
GET/POST             /api/contracts/{contract}/payment-applications
POST                 /api/projects/{project}/trade-packages/{pkg}/payment-applications
POST   /api/payment-applications/{pa}/submit
POST   /api/payment-applications/{pa}/withdraw
POST   /api/payment-applications/{pa}/certify
POST   /api/payment-applications/{pa}/mark-paid
POST   /api/payment-applications/{pa}/cancel
POST   /api/payment-applications/{pa}/generate-pdf
POST   /api/payment-applications/{pa}/generate-certificate
POST   /api/payment-applications/{pa}/generate-excel
POST   /api/payment-applications/{pa}/breakdown
GET    /api/payment-applications/{pa}/previous-values
POST   /api/payment-applications/{pa}/pay-less-notice
POST   /api/payment-applications/{pa}/payment-notice
DELETE /api/payment-applications/{pa}
GET    /api/payment-notices/{paymentNotice}
DELETE /api/payment-notices/{paymentNotice}
DELETE /api/retention-releases/{retentionRelease}

# Variations
GET/POST/PUT/DELETE  /api/contracts/{contract}/variations
POST   /api/variations/{variation}/generate-pdf

GET/POST/PUT/DELETE  /api/projects/{project}/rfis
GET/POST/PUT/DELETE  /api/projects/{project}/site-diaries
GET/POST/PUT/DELETE  /api/projects/{project}/meetings
GET/POST/PUT/DELETE  /api/projects/{project}/eot-requests
GET/POST/PUT/DELETE  /api/projects/{project}/pay-less-notices
GET/POST/PUT/DELETE  /api/projects/{project}/site-instructions

GET    /api/documents/{id}/download
GET/POST  /api/projects/{project}/documents
GET    /api/projects/{project}/documents/register

POST   /api/ai/conversations
GET    /api/ai/conversations
POST   /api/ai/conversations/{id}/messages
POST   /api/ai/summarize
POST   /api/ai/draft-document

GET/PUT  /api/organization
GET/POST/PUT  /api/organization/branding
POST   /api/organization/logo
POST   /api/organization/letterhead-header
POST   /api/organization/letterhead-footer

GET    /api/clients
POST   /api/clients
GET    /api/clients/{id}/projects

# Admin only
GET    /api/admin/dashboard
GET    /api/admin/organizations
GET    /api/admin/projects
GET    /api/admin/documents
GET    /api/admin/storage
GET    /api/admin/system-logs
GET    /api/admin/audit-log
GET/PUT  /api/admin/settings
GET/PUT  /api/admin/suresign-settings
PUT    /api/admin/suresign-settings/ai
PUT    /api/admin/suresign-settings/notifications
POST   /api/admin/suresign-settings/logo
POST   /api/admin/suresign-settings/test-email
POST   /api/admin/suresign-settings/test-pdf
```

---

## 7. Frontend — Next.js App

### Route Layout

The frontend has **three separate layout zones**:

#### 1. Public Routes
| Path | Description |
|---|---|
| `/login` | Login page (dark/light branding panel + form) |

#### 2. Tenant App (`/app/*`)
Protected by auth. Redirects to `/admin` if the user is Super Admin or Admin (except project detail pages which are shared).

| Path | Page |
|---|---|
| `/app` | **Dashboard** — greeting, stats (active projects, open RFIs, pending variations, documents this month, payment apps pending, recent activity) |
| `/app/projects` | Project list |
| `/app/projects/[id]` | **Project workspace** with sub-pages: |
| `/app/projects/[id]/overview` | Project overview & details |
| `/app/projects/[id]/contracts` | Contracts list |
| `/app/projects/[id]/commercial` | Commercial overview |
| `/app/projects/[id]/variations` | Variations register |
| `/app/projects/[id]/rfis` | RFI register |
| `/app/projects/[id]/meetings` | Meeting minutes |
| `/app/projects/[id]/notices` | Pay Less Notices |
| `/app/projects/[id]/site-reports` | Site diary / reports |
| `/app/projects/[id]/documents` | Project document library |
| `/app/projects/[id]/closeout` | Project closeout |
| `/app/projects/[id]/qa` | Quality assurance |
| `/app/projects/[id]/snagging` | Snag list |
| `/app/commercial` | Cross-project commercial overview |
| `/app/site` | Site administration overview |
| `/app/documents` | Organisation-wide document library |
| `/app/ai` | AI Assistant (conversation interface) |
| `/app/company` | Company profile management |
| `/app/team` | Team member management |
| `/app/reports` | Reports |
| `/app/settings` | User profile settings |
| `/app/onboarding` | First-time company onboarding wizard |

#### 3. Admin Panel (`/admin/*`)
Protected by auth + role check (`Super Admin` or `Admin` only). Redirects non-admin users to `/app`.

| Path | Page |
|---|---|
| `/admin` | **Admin Dashboard** — total companies, projects, users, storage, AI usage, recent documents, recent activity, recent notifications, unread notification count |
| `/admin/companies` | All tenant companies — with branding logo displayed |
| `/admin/projects` | All projects across all tenants |
| `/admin/users` | All user accounts |
| `/admin/templates` | Global document templates |
| `/admin/documents` | All platform documents + document register |
| `/admin/documents/register` | Admin document register view |
| `/admin/notifications` | User notification centre |
| `/admin/prompts` | Prompt library / template management |
| `/admin/support` | Support tickets |
| `/admin/settings` | **Platform settings** — branding, email (Brevo), PDF letterheads, AI (enable/disable, API key, model), notification event toggles, currency, mirror path |

---

### Frontend State Management

**Zustand** (`authStore`) persists to `localStorage`:

```typescript
{
  user: User | null,         // Full user profile incl. roles, permissions, org
  token: string | null,      // Sanctum bearer token
  _hasHydrated: boolean,     // localStorage rehydration complete
}
```

Actions: `login()`, `logout()`, `fetchUser()`, `hasRole()`, `hasPermission()`

**Axios instance** (`lib/api.ts`):
- Base URL: `NEXT_PUBLIC_API_URL` (default `http://localhost:8000/api`)
- Auto-attaches `Authorization: Bearer <token>` from `localStorage` on every request
- Global 401 interceptor: clears token and redirects to `/login`

**Branding**:
- On `/app` layout mount, fetches `/organization/branding`
- Applies `primary_color` as CSS variable `--gold` directly to `document.documentElement`
- Supports per-tenant accent colour (white-label theming live in the browser)

---

## 8. Authentication & Roles

### Login Flow

```
User enters email + password
        ↓
POST /api/auth/login
        ↓
Laravel validates credentials → issues Sanctum personal access token
        ↓
Frontend stores token in localStorage + Zustand
        ↓
Frontend checks user.roles:
  - Super Admin / Admin → redirect to /admin
  - Client / other      → redirect to /app
```

### Roles (Seeded)

| Role | Access |
|---|---|
| **Super Admin** | Full platform access. All organisations, settings, admin panel. All permissions. |
| **Admin** | Organisation-level management. Projects, contracts, RFIs, variations, documents, AI, reports. Cannot manage organisations or users globally. |
| **Client** | Read-only access to assigned projects (view contracts, RFIs, variations, payment apps, documents, reports). |

### Default Credentials

| Field | Value |
|---|---|
| Email | `admin@suresign.app` |
| Password | `Admin@2024!` |
| Role | Super Admin |

---

## 9. Modules & Workflows

### Module: Projects
- Projects are the central workspace unit.
- On creation, project folders are auto-generated (via `project_folders`).
- Projects have a full team (internal `project_users`) and external contacts (`project_contacts`).
- Project stats: open RFIs, pending variations, document count, payment status.

### Module: Contracts
- Nested under projects. Multiple contracts per project.
- Supports: `main_contract`, `subcontract`, `consultant_appointment`, `supplier_agreement`.
- Tracks contract sum, retention, payment terms, key dates.

### Module: Commercial Administration

#### Payment Applications
Full lifecycle payment claim workflow. Can be raised against a **main contract** or a **trade package**.

- **Status lifecycle**: `draft` → `submitted` → `payment_notice_issued` / `pay_less_notice_issued` → `certified` → `paid`; `cancelled` available post-submission only
- **Withdraw**: a submitted application can be withdrawn back to `draft` (same number retained, `withdrawal_count` incremented, audit log entry created). Not a permanent status — it simply reverts the application to draft for correction and resubmission.
- **Delete**: only available for `draft` and `cancelled` applications (soft delete via `deleted_at`). UI labels this "Delete Draft".
- **Cancel**: restricted to `submitted`, `payment_notice_issued`, `pay_less_notice_issued` — not available on draft or certified.
- **Defaults pre-fill**: `GET /projects/{project}/payment-application-defaults` returns next application number, valuation period, statutory dates, and carried-forward values before the user saves
- **Statutory dates**: calculated by `PaymentDateService` from contract offset rules and/or confirmed AI analysis — due date, final date for payment, payment notice deadline, pay-less notice deadline
- **Retention cap**: optional `retention_cap_percentage` on the contract prevents total retention from exceeding `contract_sum × cap%`
- **Carried-forward values**: previous certified value, previous paid value, previous retention held — accumulated from all prior certified/paid applications
- **Documents**: PDF (DomPDF) and Excel workbook (PhpSpreadsheet) generated on demand
- **Payment Notices**: standalone notices linked to an application; PDF generated with inline branding (no canvas letterhead)
- **Pay Less Notices**: issued against a specific application
- **Retention Releases**: track partial or full release of held retention

#### Variations
Change order management. Types: addition, omission, substitution, provisional sum, daywork. Tracks programme impact. PDF generation supported.

#### Pay Less Notices
Formal counter-notice against a payment application. Status: `draft` → `issued` → `disputed`.

#### Trade Packages (Subcontract Management)
- Each trade package gets a code (`GW`, `BW`, `ME`, etc.) and package reference
- Standard sub-folders auto-created on disk and in database
- Payment applications can be raised per trade package (independent of main contract applications)
- Bulk generation via **Generate Trade Package Folder** feature

### Module: Site Administration
- **RFIs** — formal query/response workflow. Priority levels. Programme and cost impact flags.
- **Site Instructions** — instructions issued to contractors. Types: variation, safety, quality, design, general, urgent.
- **Site Diaries** — daily records: weather, workers on site, works carried out, delays, health & safety observations.
- **Meeting Minutes** — structured meeting records with agenda, attendees (JSON), minutes, action items, and AI-generated summary.
- **EOT Requests** — Extension of Time claims. Tracks days claimed vs granted, loss & expense claims.

### Module: Documents
- Generated documents linked polymorphically to any model (RFI, variation, contract, etc.).
- Template-based PDF generation via DomPDF.
- Version history (incremented on each regeneration).
- Approval workflow (pending → approved/rejected).
- Separate raw file upload library (`file_uploads`) with virtual folder structure.

### Module: AI Assistant
- Conversation interface with context scoped to a project or specific model.
- Conversation types: `general`, `document_draft`, `meeting_summary`, `variation_analysis`, `report`, `rfi`.
- AI outputs saved as `ai_outputs` for human review before use.
- Operations: `summarize` text, `draft-document` from template data.
- Token usage tracked per conversation.

### Module: Workflows
- Reusable workflow blueprints (`workflows` + `workflow_steps`).
- Launched as instances (`workflow_instances`) against a project/trigger.
- Step instances track status, assignment, due date.
- Step types: `task`, `approval`, `notification`, `document`, `ai_action`.

### Module: Reporting
- Periodic reports (progress, commercial, monthly, weekly, final) with date range.
- Optional AI-assisted content generation.
- PDF export.

### Module: Branding & Settings
- Per-organisation: logo, colours, letterhead header/footer, cover image, font.
- Platform-wide (Super Admin): SureSign logo, default email settings (Brevo integration), PDF letterhead, timezone, currency, date format.

---

## 10. AI Integration

SureSign has **two distinct AI systems**. Do not conflate them.

---

### System 1 — Prompt Library (manual copy/paste)

The original AI feature. No API calls are made — users copy prompts and paste them into an external AI tool manually.

- Admin-editable `prompt_templates` records organised into `prompt_categories`
- `PromptRenderService` substitutes `{{project_name}}`, `{{contract_sum}}`, etc. into templates
- Users can favourite prompts (`prompt_favorites`) and copy logs are tracked (`prompt_copy_logs`)
- Accessible via the `/app/ai` page

---

### System 2 — Contract AI Analysis (Anthropic Claude API)

A real API integration specifically for analysing uploaded contract documents. Opt-in, configured per-org in admin settings.

#### Provider Architecture

```php
interface AiProviderInterface {
    public function complete(string $systemPrompt, string $userPrompt): array;
    // Returns: ['text', 'tokens_input', 'tokens_output', 'stop_reason']
}
```

Currently implemented by `ClaudeAiProvider`. The model, API key, and enabled toggle are stored in `suresign_settings` and resolved by `ContractAnalysisService::makeProvider()`.

#### Analysis Flow

```
Admin uploads contract PDF/DOCX/TXT
        ↓
POST /api/contracts/{contract}/ai-analysis
        ↓
ContractAnalysisService::extractText() — extracts plain text from file
        ↓
ClaudeAiProvider::complete() — sends to Anthropic Claude API
        ↓
Response stored in contract_ai_analyses (status: completed)
Token usage + estimated cost recorded
        ↓
Admin reviews extracted data in UI
        ↓
POST /api/ai/analyses/{analysis}/confirm
  → status = confirmed
  → confirmed_data_json saved
        ↓
PaymentDateService uses confirmed_data_json for statutory date calculations
ProgrammeMilestoneController can seed milestones from confirmed analysis
```

#### Analysis Status Lifecycle

```
pending → processing → completed → confirmed
                    → failed
confirmed → (admin can re-parse) → processing
```

#### What AI Extracts
- Payment terms and offset rules (due date, final date, notice deadlines)
- Key contract parties
- Contract sum and retention terms
- Programme milestones and key dates
- Retention cap percentage

#### Token Tracking
- `tokens_input`, `tokens_output`, `estimated_cost` stored per analysis
- Monthly AI usage aggregated in admin dashboard stats

---

### Legacy AI Conversations (chat-style)

The original conversation interface still exists:

```
POST /api/ai/conversations   → create/select session
POST /api/ai/conversations/{id}/messages → send message
POST /api/ai/summarize → summarise text
POST /api/ai/draft-document → draft from template data
```

All conversations, messages (`ai_messages`), and outputs (`ai_outputs`) are persisted in MySQL. Token counts tracked per message.

---

## 11. Docker & Setup

### Services

| Service | Image | Port |
|---|---|---|
| `backend` | Custom Laravel Dockerfile | `8000` |
| `frontend` | `node:20-alpine` (dev mode) | `3000` |
| `mysql` | `mysql:8.0` | `3307` (host) → `3306` (container) |
| `redis` | `redis:7-alpine` | `6379` |
| `nginx` | `nginx:alpine` | `80` |

### Docker Compose Start

```bash
docker-compose up -d
```

### Nginx Configuration
Routes `/` to the Next.js frontend and `/api` to the Laravel backend.

---

## 12. Environment & Configuration

### Local Development (without Docker)

**Backend:**
```bash
cd backend
composer install
cp .env.example .env
# Edit .env: set DB credentials, APP_KEY, AI provider key
php artisan key:generate
php artisan migrate --seed
php artisan serve --port=8000
```

**Frontend:**
```bash
cd frontend
npm install
npm run dev
# Runs on http://localhost:3000
```

### Key Environment Variables (Backend `.env`)

| Variable | Description |
|---|---|
| `APP_KEY` | Laravel application key |
| `DB_HOST` / `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | MySQL connection |
| `REDIS_HOST` | Redis for cache and queues |
| `SANCTUM_STATEFUL_DOMAINS` | Allowed frontend origins |
| `FILESYSTEM_DISK` | `local` or `s3` |
| `MAIL_MAILER` / `BREVO_API_KEY` | Email sending (most email config lives in `suresign_settings`) |
| `COMPANIES_HOUSE_API_KEY` | UK Companies House Public Data API |
| `SURESIGN_LOCAL_MIRROR_PATH` | Container-side path for local Windows document mirror |
| `AI_ENABLED` | Hard-disables AI regardless of DB `suresign_settings.ai_enabled` |

> **Note**: Most runtime configuration (AI API key, model, email settings, mirror path, currency, notification toggles) is managed in the admin settings panel via `suresign_settings`. DB values take precedence over env values.

### Key Environment Variables (Frontend `.env.local`)

| Variable | Description |
|---|---|
| `NEXT_PUBLIC_API_URL` | Backend API base URL (default: `http://localhost:8000/api`) |

### Database Connection (MySQL Workbench)

| Setting | Value |
|---|---|
| Host | `127.0.0.1` |
| Port | `3306` (local) / `3307` (Docker) |
| Database | `suresign` |
| Username | `suresign` |
| Password | *(set in your `.env` / `.env.docker`)* |

### Default Admin Login

| Field | Value |
|---|---|
| Email | `admin@suresign.app` |
| Password | `Admin@2024!` |

---

*Last updated: June 2026 (v1.2)*
