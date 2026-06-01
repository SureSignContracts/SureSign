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
| **Backend** | Laravel 11 (PHP 8.3) |
| **Authentication** | Laravel Sanctum (token-based) |
| **Authorization** | Spatie Laravel Permission (RBAC) |
| **Database** | MySQL 8.0 |
| **Cache / Queues** | Redis 7 |
| **Frontend** | Next.js 15 + TypeScript |
| **Styling** | TailwindCSS v4 + CSS Variables (theming) |
| **State Management** | Zustand (with localStorage persistence) |
| **Data Fetching** | TanStack React Query v5 |
| **HTTP Client** | Axios |
| **Containerisation** | Docker + Docker Compose |
| **Reverse Proxy** | Nginx (Alpine) |
| **PDF Generation** | DomPDF (via Laravel) |
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
            ├── contracts (1:many)
            │       ├── payment_applications
            │       │       └── pay_less_notices
            │       ├── variations
            │       └── eot_requests
            ├── rfis
            ├── site_instructions
            ├── site_diaries
            ├── meeting_minutes
            ├── documents
            │       ├── document_versions
            │       └── document_approvals
            ├── file_uploads
            ├── ai_conversations
            │       ├── ai_messages
            │       └── ai_outputs
            ├── workflow_instances
            │       └── workflow_step_instances
            └── reports

suresign_settings (singleton — platform-wide)
workflows / workflow_steps (definitions/templates)
document_templates
audit_logs
notifications
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
| execution_date / commencement_date / completion_date | date | |
| status | string | `draft`, `active`, `on_hold`, `completed`, `terminated`, `disputed` |
| notes | text | |
| key_dates / key_obligations | json | |

---

#### `payment_applications`
Interim payment certificates / applications.

| Column | Notes |
|---|---|
| contract_id / project_id / created_by | FK |
| application_number | integer |
| application_date / due_date | date |
| gross_valuation / less_retention / less_previous_payments / amount_due | decimal |
| certified_amount / certified_date / payment_date / paid_amount | decimal / date |
| status | `draft`, `submitted`, `certified`, `pay_less_notice_issued`, `paid`, `disputed` |
| breakdown | json (line items) |

#### `pay_less_notices`
Issued against a payment application.

| Column | Notes |
|---|---|
| payment_application_id / project_id / created_by | FK |
| notice_date | date |
| notified_sum | decimal |
| basis_of_difference | text |
| status | `draft`, `issued`, `disputed` |

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

#### `suresign_settings` (singleton)
Platform-wide settings for the SureSign instance itself (not per-tenant).

| Column | Notes |
|---|---|
| logo_path | Platform logo |
| letterhead_header_path / letterhead_footer_path / letterhead_pdf_path | PDF branding |
| email_header_path / email_footer_path | Email branding |
| email_reply_to / email_subject_line / email_body_template | Email defaults |
| brevo_api_key | Transactional email (Brevo/Sendinblue) |
| email_sender_name / email_sender_address | Sender identity fields |
| currency / currency_symbol / date_format / timezone | Locale settings |

---

## 6. Backend — Laravel API

### Controllers (`app/Http/Controllers/Api/`)

| Controller | Responsibility |
|---|---|
| `AuthController` | Login, logout, `me`, password update |
| `DashboardController` | Org-scoped stats for tenant dashboard |
| `AdminController` | Platform-wide stats and management for Super Admin |
| `OrganizationController` | Org CRUD, branding, logo/letterhead uploads, onboarding |
| `ProjectController` | Project CRUD, stats, folder tree |
| `ContractController` | Contract CRUD (nested under projects) |
| `PaymentApplicationController` | Payment apps (nested under contracts) |
| `VariationController` | Variations (nested under contracts) |
| `PayLessNoticeController` | Pay Less Notices (nested under projects) |
| `RfiController` | RFIs (nested under projects) |
| `SiteInstructionController` | Site Instructions |
| `SiteDiaryController` | Site Diary entries |
| `MeetingMinutesController` | Meeting Minutes |
| `EotRequestController` | EOT Requests |
| `DocumentController` | Document generation, download, file library |
| `AiController` | AI conversations, messages, summarise, draft document |
| `ClientController` | Client (company) CRUD |
| `UserController` | User CRUD (admin only) |
| `SuresignSettingController` | Platform settings CRUD, asset uploads |

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
GET    /api/projects/{id}/folders
GET    /api/projects/{id}/files
POST   /api/projects/{id}/files

GET/POST/PUT/DELETE  /api/projects/{project}/contracts
GET/POST/PUT/DELETE  /api/contracts/{contract}/payment-applications
GET/POST/PUT/DELETE  /api/contracts/{contract}/variations
GET/POST/PUT/DELETE  /api/projects/{project}/rfis
GET/POST/PUT/DELETE  /api/projects/{project}/site-diaries
GET/POST/PUT/DELETE  /api/projects/{project}/meetings
GET/POST/PUT/DELETE  /api/projects/{project}/eot-requests
GET/POST/PUT/DELETE  /api/projects/{project}/pay-less-notices
GET/POST/PUT/DELETE  /api/projects/{project}/site-instructions

GET    /api/documents/{id}/download
GET/POST  /api/projects/{project}/documents

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
GET/PUT  /api/admin/settings
GET/PUT  /api/admin/suresign-settings
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
| `/admin` | **Admin Dashboard** — total companies, projects, users, storage, AI usage |
| `/admin/companies` | All tenant companies (create, manage, view) |
| `/admin/projects` | All projects across all tenants |
| `/admin/users` | All user accounts |
| `/admin/templates` | Global document templates |
| `/admin/ai-configurations` | AI model and prompt configuration |
| `/admin/storage` | File storage usage |
| `/admin/billing` | Subscription / billing management |
| `/admin/support` | Support tickets |
| `/admin/system-logs` | Audit and system logs |
| `/admin/documents` | All platform documents |
| `/admin/suresign` | **Platform settings** — branding, email, PDF, letterheads |
| `/admin/settings` | System-level settings |

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
- **Payment Applications** — progressive claim cycle against a contract. Tracks gross valuation, retention, previous payments, net amount due, certification, and payment date.
- **Variations** — change order management. Types: addition, omission, substitution, provisional sum, daywork. Tracks programme impact.
- **Pay Less Notices** — formal counter-notice against a payment application.

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

### Architecture
AI is designed to be **invisible to users**. It works as a background automation layer:

- Users work on Meeting Minutes → AI silently generates a summary (`ai_summary` column).
- Users ask to draft a document → AI returns a draft for human review before it becomes a formal document.
- AI analysis of variations → surfaced as a suggestion, not automated action.

### Data Flow
```
User action (e.g. "Summarise this meeting")
        ↓
POST /api/ai/conversations   (create/select session)
POST /api/ai/conversations/{id}/messages  (send message)
        ↓
Backend calls AI provider API
        ↓
Response stored in ai_messages
Output stored in ai_outputs (pending_review)
        ↓
User reviews → approves/rejects
        ↓
On approval → content applied to the record (e.g. meeting_minutes.ai_summary)
```

### Storage
- All AI conversations, messages, and outputs are persisted in MySQL.
- Token counts tracked per message and per conversation.
- Model metadata stored in `ai_messages.metadata` JSON.

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
| `MAIL_MAILER` / `BREVO_API_KEY` | Email sending |

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
| Password | `SET_IN_ENV_FILE` |

### Default Admin Login

| Field | Value |
|---|---|
| Email | `admin@suresign.app` |
| Password | `Admin@2024!` |

---

*Documentation generated: May 2026*
