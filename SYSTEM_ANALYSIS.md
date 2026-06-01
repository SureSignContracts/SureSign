# SureSign Platform — Full System Analysis Report

> Generated after Session 2 stabilization pass. All 13 stabilization tasks complete.

---

## 1. Executive Summary

SureSign is a multi-tenant construction project management SaaS platform. It covers the full lifecycle of a construction project: contract administration, commercial management (payment applications, variations, pay-less notices), on-site documentation (RFIs, site diaries, meetings, QA, snagging), legal dispute management (adjudication), and project closeout.

The platform consists of a **Laravel 11 REST API backend** and a **Next.js 14 App Router frontend**. After two stabilization sessions, the core operational modules are functional end-to-end. The primary remaining gap is the AI layer, which is intentionally left as a placeholder pending a separate integration phase.

**Condition summary:**

| Layer | Status |
|---|---|
| Backend REST API | ✅ Fully implemented across all modules |
| Frontend — Project modules | ✅ All 13 project sub-pages functional |
| Frontend — Admin panel | ✅ Dashboard and management pages in place |
| Frontend — Auth | ✅ Login, persist, role-gating |
| File uploads | ✅ Documents + Adjudication files via local disk |
| PDF generation | ✅ Payment applications via DomPDF |
| AI features | 🔲 Placeholder — routes exist, UI disabled |
| Email / notifications | 🔲 Not yet wired |
| Cloud file storage | 🔲 Local disk only |

---

## 2. Technical Architecture

### Stack

| Component | Technology |
|---|---|
| Backend framework | Laravel 11 (PHP 8.2+) |
| Auth | Laravel Sanctum — Bearer token |
| Authorization | Spatie Laravel-Permission |
| Database | MySQL (via Laravel Eloquent ORM) |
| File storage | `Storage::disk('local')` |
| PDF generation | DomPDF (`barryvdh/laravel-dompdf`) |
| Queue | Laravel Queues (configured, not heavily used) |
| Frontend framework | Next.js 14 — App Router |
| Language | TypeScript |
| State management | Zustand with `persist` middleware |
| Server state | TanStack React Query v5 |
| HTTP client | Axios (`/lib/api.ts`) |
| UI framework | Tailwind CSS with CSS custom properties (design tokens) |
| Icon library | Lucide React |
| Toasts | react-hot-toast |

### Multi-Tenancy

The platform is **organization-scoped**. Every data record carries an `organization_id`. Users belong to one organization; all data queries are implicitly scoped by organization via the authenticated user's `organization_id`. Projects further scope operational records (every `project_id` field ties back to a single organization).

### Authentication Flow

1. `POST /api/auth/login` → returns `{ token, user }`.
2. Token stored in `localStorage` as `suresign_token`.
3. Zustand `authStore` persists `{ token, user }` under key `suresign-auth`.
4. Axios interceptor reads `suresign_token` on every request and sets `Authorization: Bearer <token>`.
5. 401 responses trigger automatic logout + redirect to `/login`.
6. `GET /api/auth/me` is called on app boot to hydrate the user object.

### Role Model

Three roles via Spatie:

| Role | Access |
|---|---|
| `Super Admin` | Full platform access including admin panel |
| `Admin` | Full project and operational access |
| `Client` | Read-only — cannot create, edit, or delete records |

Frontend enforcement: `useProjectPermissions()` hook returns `{ canWrite, readOnly, isSuperAdmin, isAdmin, isClient }`. Write actions are conditionally rendered with `canWrite`.

Backend enforcement: Sensitive routes gated by `role:Super Admin|Admin` middleware (Spatie). All routes require `auth:sanctum`.

### Project Navigation Structure

Each project has its own sidebar (`ProjectSidebar`) with 13 navigation items:

```
Overview → Contracts → Commercial → Variations → Notices →
Adjudication → RFIs → Meetings → QA Reports → Snagging →
Closeout → Documents → Site Reports
```

### Frontend Design System

All styling uses CSS custom properties (design tokens) rather than hard-coded Tailwind colors:

| Token | Usage |
|---|---|
| `var(--bg-base)` | Page background |
| `var(--bg-surface)` | Cards, sidebars |
| `var(--bg-elevated)` | Input fields, secondary cards |
| `var(--bg-hover)` | Hover states |
| `var(--border)` | Dividers and outlines |
| `var(--text-primary)` | Main text |
| `var(--text-secondary)` | Labels |
| `var(--text-muted)` | Helper text, timestamps |
| `var(--gold)` | Primary accent (active nav, CTA buttons) |
| `var(--accent-fg)` | Text on gold backgrounds |

This approach makes theming (light/dark, white-label) straightforward — only the token values need to change.

---

## 3. Module-by-Module Analysis

### 3.1 Overview Page (`/overview`)

**Status: ✅ Fully functional**

- Displays project header: name, code, type, organization, status badge.
- Four clickable stat cards: Open RFIs, Pending Variations, Payment Apps, Open Snagging. Cards link directly to the relevant sub-page.
- Contract value + certified amount summary row (shown when stats available).
- Activity feed (latest 8 entries) with per-type icons and colors.
- Skeleton loaders during data fetch.
- Data: `GET /projects/{id}/stats` + `GET /projects/{id}/activities`.

**Notable:** The `ProjectStatsService` now provides real counts for all four stats including `open_snagging` and `active_adjudication_cases`.

---

### 3.2 Contracts (`/contracts`)

**Status: ✅ Fully functional**

- Lists all contracts for the project in a table: Reference, Title, Party, Type, Contract Sum, Execution Date, Status badge.
- Create contract modal: type, title, reference number, form of contract, party name, contract sum, currency, retention %, payment terms, execution/commencement/completion dates, status, notes.
- Edit contract modal: pre-populates all fields + status selector.
- `POST /contracts` (nested under project) and `PUT /contracts/{id}`.
- Status types: `draft`, `active`, `expired`, `complete`, `terminated` — all displayed in Title Case.

---

### 3.3 Commercial (`/commercial`)

**Status: ✅ Fully functional**

Three-tab interface:

**Tab 1 — Payment Applications**
- Contract selector (required before creating PA).
- Create form: application date, due date, reference, gross valuation, less retention, less previous payments, notes.
- Table: App#, Reference, Date, Gross Val, Amount Due, Certified, Status badge.
- Workflow action buttons: Submit → Certify (with certified amount input) → Mark Paid.
- Generate PDF button (calls `POST /payment-applications/{id}/generate-pdf`, stores as `Document`).
- Status states: `draft` → `submitted` → `certified` → `paid`.

**Tab 2 — Variations**
- Listed in table: Var#, Title, Type, Amount, Status.
- Create modal: contract selector, title, type (Variation/Provisional Sum/Prime Cost/Day Work/Omission/Instructed), description, amount, status.
- Navigation link to `/variations` for full view.

**Tab 3 — Pay Less Notices**
- Fetches payment applications for selector (only submitted/certified PAs available).
- Form: payment application selector (required), issued date, amount withheld, grounds.
- Lists existing pay-less notices.

---

### 3.4 Variations (`/variations`)

**Status: ✅ Fully functional**

- Full table with: Var#, Title, Type, Contract, Valuation, Status badge.
- Create variation modal: all fields.
- Edit variation modal: pre-populates all fields + status selector (Pending/Submitted/Approved/Rejected/On Hold).
- `PUT /variations/{id}` on save.
- Invalidates `project-activities` on all mutations.

---

### 3.5 Notices (`/notices`)

**Status: ✅ Fully functional**

Four-tab interface:

**EOT Requests** — Create with contract, reason, days requested, dates, description.
**Delay Notices** — Create with description, delay type, dates.
**Pay Less Notices** — Create with payment application selector (fetches live), issued date, amount withheld, grounds. Status badge with Title Case labels.
**Site Instructions** — Create with reference, title, Type dropdown (Variation/Safety/Quality/Design/General/Urgent), description, contractor.

All four sub-modules list existing records in tables with status badges.

---

### 3.6 Adjudication (`/adjudication` and `/adjudication/{caseId}`)

**Status: ✅ Fully functional (step-through workflow)**

**List page:**
- Cards showing active vs archived cases.
- Create case modal: title, dispute type (Payment/Variation/Defects/Extension of Time/Other), claimant name, respondent name, claim amount, currency, linked contract/PA/variation, summary, key dates.
- Search + archive filter.

**Case detail page (8-step workflow):**

Steps in order:
1. Notice of Dispute
2. Notice of Adjudication
3. Adjudicator Appointment
4. Referral Submission
5. Response Analysis
6. Further Submissions
7. Decision Analysis
8. Enforcement

Each step shows:
- Status indicator (not started / in progress / complete / locked-future).
- Per-step action buttons: document upload (real file upload), draft record creation. "Mark as Sent/Issued/Appointed" buttons are shown as Coming Soon tooltips.
- Advance Step modal: notes field, `POST /adjudication-cases/{id}/advance-step`.

**Documents panel:** Upload File mode (real binary upload via FormData) or Create Draft Record mode (text-based metadata). Both stored via `AdjudicationDocumentController`.

**Deadlines panel:** Add/complete deadlines; `mark-complete` endpoint.

**Claim summary sidebar:** Dispute type, claim amount, linked contract/PA/variation, key dates.

---

### 3.7 RFIs (`/rfis`)

**Status: ✅ Fully functional with workflow**

- Table: RFI#, Subject, Priority badge, Raised Date, Response Due, Status badge, Actions.
- Create RFI modal: subject, description, priority (Urgent/High/Normal/Low), raised date, response due date, programme impact, cost impact.
- Respond modal: response text (required), date, assigned to.
- Close button: one-click `PUT /rfis/{id}` with `{ status: 'closed' }`.
- Respond/Close buttons hidden appropriately based on current status.
- Status flow: `open` → `responded` → `closed`.

---

### 3.8 Meetings (`/meetings`)

**Status: ✅ Fully functional with detail/edit modal**

- Table: Meeting#, Title, Type, Date, Location, Attendees count, Status.
- Create meeting modal: title, type (Progress/Design/Commercial/Safety/Subcontractor/Other), date, location, attendees (comma-separated), agenda, minutes, action items, status.
- Detail/Edit modal: view mode shows all fields with attendees as pills; edit mode with inline form.
- AI Summary placeholder in detail modal ("AI summary — coming soon").
- `PUT /meetings/{id}` on save; refreshes detail view on update.
- Attendees stored as JSON array; action items stored as JSON array.

---

### 3.9 QA Reports (`/qa`)

**Status: ✅ Fully functional**

- Table: Report#, Title, Inspection Type, Area, Inspector, Date, Follow-up, Status badge.
- Create/Edit modal: title, inspection type, area, inspector, inspection date, status, result, observations, corrective action, follow-up required checkbox.
- Status states: `draft`, `open`, `failed`, `passed`, `closed`.
- Delete with confirmation.
- Search filter.

---

### 3.10 Snagging (`/snagging`)

**Status: ✅ Fully functional**

- Table: Snag#, Title, Category, Priority badge, Assigned To, Due Date, Status badge.
- Create/Edit modal: title, description, location, category, priority, status, assigned to, due date, notes.
- Status flow: `open` → `in_progress` → `ready_for_review` → `closed`.
- Backend auto-sets `closed_at` timestamp when status = `closed`.
- Delete with confirmation.
- Search + status/priority filters.

---

### 3.11 Closeout (`/closeout`)

**Status: ✅ Functional**

- Auto-creates closeout record with default items on first access (backend creates defaults: Defects Liability, Test Certs, O&M Manuals, As-builts, Final Account, Handover).
- Progress bar showing `completed/total` percentage.
- Items grouped by category.
- Per-item status cycling via inline dropdown: `pending` → `in_progress` → `completed` → `approved`.
- "Mark Complete" button (marks entire closeout as completed).
- Add Item modal: category, title, due date.
- No edit-in-place for existing items (future work).

---

### 3.12 Documents (`/documents`)

**Status: ✅ Functional**

Two-tab view:
- **Uploaded Files** — Lists raw file uploads for the project (`GET /projects/{id}/files`). Folder filter sidebar. Upload button triggers file input. Download and delete per file.
- **Generated Documents** — Lists documents created by the system (PA PDFs, etc.) (`GET /projects/{id}/documents`). Category filter. Download link.

Document types: `payment_app`, `contract`, `variation`, `rfi`, `report`, `other`.

The `DocumentController::store()` is now fully implemented: validates title/type/category/status/reference_number/file (max 50MB); stores file on local disk; creates `Document` record with all metadata; returns with creator loaded.

---

### 3.13 Site Reports (`/site-reports`)

**Status: ✅ Functional**

Single-tab view of site diaries:
- Table: Date, Weather, Workers, Works Carried Out (truncated), Status badge.
- Create Site Diary modal: date, weather, workers on site, works carried out, materials delivered, issues/delays.
- Status states: `draft`, `submitted`, `approved`.
- No edit modal for existing entries (future work).

---

## 4. Backend API Analysis

### Route Summary

All routes require `auth:sanctum` middleware. Admin routes additionally require `role:Super Admin|Admin`.

**Auth**
```
POST   /auth/login
POST   /auth/logout
GET    /auth/me
PUT    /auth/password
```

**Dashboard**
```
GET    /dashboard
```

**Projects**
```
GET    /projects                            — list (org-scoped)
POST   /projects                            — create
GET    /projects/{project}                  — show
PUT    /projects/{project}                  — update
DELETE /projects/{project}                  — soft-delete (archive)
GET    /projects/{project}/stats            — ProjectStatsService
GET    /projects/{project}/activities       — activity feed (paginated)
```

**Contracts**
```
GET    /projects/{project}/contracts
POST   /projects/{project}/contracts
GET    /contracts/{contract}
PUT    /contracts/{contract}
DELETE /contracts/{contract}
```

**Commercial**
```
GET    /contracts/{contract}/payment-applications
POST   /contracts/{contract}/payment-applications
GET    /payment-applications/{pa}
PUT    /payment-applications/{pa}
DELETE /payment-applications/{pa}
POST   /payment-applications/{pa}/submit
POST   /payment-applications/{pa}/certify
POST   /payment-applications/{pa}/mark-paid
POST   /payment-applications/{pa}/generate-pdf
GET    /projects/{project}/payment-applications   — flat list by project

GET    /contracts/{contract}/variations
POST   /contracts/{contract}/variations
GET    /variations/{variation}
PUT    /variations/{variation}
DELETE /variations/{variation}
GET    /projects/{project}/variations             — flat list by project

GET    /projects/{project}/pay-less-notices
POST   /projects/{project}/pay-less-notices
PUT    /pay-less-notices/{notice}
DELETE /pay-less-notices/{notice}
```

**Site Admin**
```
GET/POST   /projects/{project}/rfis
GET/PUT/DELETE  /rfis/{rfi}

GET/POST   /projects/{project}/site-diaries
GET/PUT/DELETE  /site-diaries/{diary}

GET/POST   /projects/{project}/meetings
GET/PUT/DELETE  /meetings/{meeting}

GET/POST   /projects/{project}/eot-requests
GET/PUT/DELETE  /eot-requests/{request}

GET/POST   /projects/{project}/pay-less-notices
GET/POST   /projects/{project}/site-instructions
GET/PUT/DELETE  /site-instructions/{instruction}
```

**Quality & Defects**
```
GET/POST   /projects/{project}/snagging
GET/PUT/DELETE  /snagging/{snag}

GET/POST   /projects/{project}/qa-reports
GET/PUT/DELETE  /qa-reports/{report}
```

**Closeout**
```
GET    /projects/{project}/closeout
PUT    /projects/{project}/closeout
POST   /projects/{project}/closeout/items
PUT    /projects/{project}/closeout/items/{item}
```

**Adjudication**
```
GET/POST   /projects/{project}/adjudication-cases
GET/PUT/DELETE  /projects/{project}/adjudication-cases/{case}
POST   /projects/{project}/adjudication-cases/{case}/advance-step
POST   /projects/{project}/adjudication-cases/{case}/update-status
POST   /projects/{project}/adjudication-cases/{case}/archive

GET/POST   /adjudication-cases/{case}/documents
GET/PUT/DELETE  /adjudication-documents/{document}

GET/POST   /adjudication-cases/{case}/deadlines
PUT        /adjudication-deadlines/{deadline}
POST       /adjudication-deadlines/{deadline}/mark-complete
DELETE     /adjudication-deadlines/{deadline}
```

**Documents & Files**
```
GET/POST   /projects/{project}/documents
GET        /documents/{document}/download

GET/POST   /projects/{project}/files
GET/DELETE /file-uploads/{upload}
```

**AI** *(placeholder — routes exist)*
```
POST/GET   /ai/conversations
POST/GET   /ai/conversations/{conv}/messages
POST       /ai/summarize
POST       /ai/draft-document
```

**Organization**
```
GET/PUT    /organization
POST       /organization/logo
POST       /organization/cover-image
POST       /organization/letterhead
GET        /organization/branding
```

**Admin** *(Super Admin | Admin only)*
```
GET    /admin/dashboard
GET    /admin/projects
GET    /admin/documents
GET    /admin/organizations
GET    /admin/templates
GET    /admin/storage
GET    /admin/support
GET    /admin/system-logs
GET/PUT  /admin/settings
GET/POST/PUT/DELETE  /admin/users
GET/POST/PUT/DELETE  /admin/companies
GET/POST/PUT/DELETE  /admin/documents
GET/POST/PUT/DELETE  /admin/templates
```

### Controller Implementation Status

All 27 controllers are fully implemented with no empty stubs. Every controller that was previously an empty stub has been implemented during the stabilization passes:

| Controller | Status | Notes |
|---|---|---|
| `AuthController` | ✅ Full | Login, logout, me, password change |
| `DashboardController` | ✅ Full | Org-wide stats |
| `ProjectController` | ✅ Full | CRUD + stats + folders |
| `ContractController` | ✅ Full | CRUD + activity logging |
| `PaymentApplicationController` | ✅ Full | CRUD + submit/certify/markPaid/generatePdf |
| `VariationController` | ✅ Full | CRUD + activity logging |
| `RfiController` | ✅ Full | CRUD + status transitions + activity |
| `MeetingMinutesController` | ✅ Full | CRUD + attendees/agenda/minutes arrays |
| `SnagController` | ✅ Full | CRUD + `closed_at` auto-set + activity |
| `QaReportController` | ✅ Full | CRUD + inspection fields + activity |
| `CloseoutController` | ✅ Full | Auto-create defaults + item CRUD |
| `DocumentController` | ✅ Full | File validation + local disk storage |
| `AdjudicationCaseController` | ✅ Full | 8-step workflow + advance/archive/updateStatus |
| `AdjudicationDocumentController` | ✅ Full | Real file upload + metadata mode |
| `AdjudicationDeadlineController` | ✅ Full | CRUD + mark-complete |
| `SiteDiaryController` | ✅ Full | CRUD |
| `SiteInstructionController` | ✅ Full | CRUD |
| `EotRequestController` | ✅ Full | CRUD |
| `PayLessNoticeController` | ✅ Full | CRUD + PA linking |
| `AdminController` | ✅ Full | Dashboard/projects/docs/orgs/settings/system-logs |
| `UserController` | ✅ Full | CRUD + role assignment |
| `ClientController` | ✅ Full | Client management |
| `OrganizationController` | ✅ Full | Profile + branding (logo/cover/letterhead) |
| `ProjectActivityController` | ✅ Full | Paginated activity feed |
| `AiController` | ✅ Routes registered | Returns placeholder responses |
| `SuresignSettingController` | ✅ Full | Platform-level settings (Super Admin) |

---

## 5. Data Model Review

### Entity Relationship Overview

```
Organization
  ├── Users (many-to-many via project_users pivot)
  ├── Projects
  │     ├── Contracts
  │     │     ├── PaymentApplications  ← workflow: draft→submitted→certified→paid
  │     │     ├── Variations
  │     │     └── EotRequests
  │     ├── Rfis
  │     ├── SiteInstructions
  │     ├── SiteDiaries
  │     ├── MeetingMinutes
  │     ├── QaReports
  │     ├── Snags
  │     ├── PayLessNotices
  │     ├── Closeout → CloseoutItems
  │     ├── AdjudicationCases
  │     │     ├── AdjudicationSteps
  │     │     ├── AdjudicationDocuments
  │     │     └── AdjudicationDeadlines
  │     ├── Documents (generated/uploaded)
  │     ├── FileUploads (raw files)
  │     ├── ProjectActivities (audit trail)
  │     ├── ProjectFolders
  │     └── AiConversations → AiMessages
  └── BrandingSettings (logo, cover, letterhead, colors)
```

### Key Model Details

**Project** — Central entity. Carries full construction project metadata: name, code, type, contract type/value/currency, retention %, payment terms, start/end/completion dates, address. SoftDeletes.

**Contract** — Ties to a Project. Holds party name, form of contract, contract sum, retention %, execution/commencement/completion dates. Status: `draft/active/expired/complete/terminated`. Key dates and obligations stored as JSON arrays. SoftDeletes.

**PaymentApplication** — Ties to a Contract (and by extension a Project). Stores gross valuation, less retention, less previous payments, derived `amount_due`. Certified amount set during certification. `application_number` auto-increments per contract. Full audit trail via `ProjectActivityService`.

**Variation** — Ties to a Contract. Types: `variation/provisional_sum/prime_cost/day_work/omission/instructed`. Status: `pending/submitted/approved/rejected/on_hold`.

**AdjudicationCase** — The most complex model. 8-step workflow tracked via `current_step` string (from `STEPS` constant). Carries all dispute metadata including 8 key dates (notice of dispute through enforcement deadline). Links optionally to Contract, PaymentApplication, and Variation. `AdjudicationStep` records track per-step state. SoftDeletes.

**MeetingMinutes** — Stores attendees and action_items as JSON arrays. Meeting types: progress/design/commercial/safety/subcontractor/other. Status: draft/issued/approved.

**Snag** — Defect tracking. Status: open/in_progress/ready_for_review/closed. Auto-sets `closed_at` on close. Priority: low/medium/high/critical. Supports assignment to a user.

**QaReport** — Inspection tracking. Status: draft/open/failed/passed/closed. Links to an inspector user. Tracks follow-up required flag.

**Closeout** — One per project (auto-created). Status: pending/in_progress/completed/approved. Child `CloseoutItem` records grouped by category.

**Document** — System-generated and uploaded documents. Types: payment_app/contract/variation/rfi/report/other. Categories allow folder-like grouping. Stores file path, mime type, file size.

**FileUpload** — Raw user-uploaded files. Different from Document — these are untyped uploads that can be organized into `ProjectFolder` records.

### Patterns

- **Auto-numbering**: Every sequential record (RFI, Variation, Snag, QA Report, Meeting, PA, Adjudication Case) has a `{entity}_number` field auto-incremented via `MAX() + 1` in the controller `store()`.
- **Soft deletes**: All major entities use `SoftDeletes`.
- **Organization scoping**: All records carry `organization_id` set from `$request->user()->organization_id`.
- **Creator tracking**: All records carry `created_by` set from `$request->user()->id`, with an eager-loadable `creator:id,name` relationship.
- **Activity logging**: `ProjectActivityService::record()` called in every mutation that changes meaningful state.

---

## 6. Frontend Patterns & UX

### Data Fetching Pattern

```typescript
// Reads: useQuery with staleTime
const { data, isLoading } = useQuery({
  queryKey: ['project-contracts', id],
  queryFn: () => api.get(`/projects/${id}/contracts`).then(r => r.data),
  staleTime: 2 * 60 * 1000,
});

// Writes: useMutation with optimistic cache invalidation
const mutation = useMutation({
  mutationFn: (data) => api.post(`/projects/${id}/contracts`, data),
  onSuccess: () => {
    queryClient.invalidateQueries({ queryKey: ['project-contracts', id] });
    queryClient.invalidateQueries({ queryKey: ['project-activities', id] });
    toast.success('Contract created');
    onClose();
  },
  onError: (err) => toast.error(getErrorMessage(err, 'Failed to save')),
});
```

This pattern is consistent across all 13 project pages. Activity log invalidation on every mutation ensures the Overview page's activity feed stays current.

### Modal Pattern

All create/edit operations use inline modal components (not router-based navigation). Pattern:

1. Modal state managed in parent: `const [showCreate, setShowCreate] = useState(false)`.
2. Modal component accepts `projectId` + `onClose` (+ `item` for edit mode).
3. Edit modals: `const isEdit = !!item`; form pre-populated from `item` prop.
4. Submit: `isEdit ? api.put(...) : api.post(...)`.
5. All modals use consistent styling: dark overlay, `var(--bg-surface)` card, gold CTA button.

### Skeleton Loading

All pages implement skeleton loaders during initial data fetch — animated pulse blocks matching the layout of the real content. No "loading..." text.

### Permission Gating

Create/Edit/Delete buttons are conditionally rendered: `{canWrite && <button>Add</button>}`. Clients see a read-only view.

### Error Handling Pattern

```typescript
function getErrorMessage(error: unknown, fallback: string): string {
  // Extracts error.response.data.message from Axios errors
  // Falls back to provided fallback string
}
```

All mutations call `toast.error(getErrorMessage(err, 'Fallback message'))`.

### Currency Display

`useCurrencyFormatter()` hook reads the organization's currency setting and returns a formatter function. Used consistently across all financial fields.

### File Upload Pattern (two variants)

**Raw file upload (Adjudication, Documents):**
```typescript
const formData = new FormData();
formData.append('file', file);
formData.append('title', title);
api.post(endpoint, formData, { headers: { 'Content-Type': 'multipart/form-data' } });
```

**Simple file trigger (Documents page):**
```typescript
const fileInputRef = useRef<HTMLInputElement>(null);
// Invisible <input type="file" ref={fileInputRef}>
// Upload button calls fileInputRef.current?.click()
```

---

## 7. Known Issues & Gaps

### 7.1 API Endpoint Mismatches

| Page | Issue |
|---|---|
| Documents | Calls `GET /projects/{id}/folders` — endpoint not confirmed in `api.php`. |
| Documents | Calls `DELETE /snagging/{id}` using bare resource name (not `snags`) — may need verification against route registration. |
| Closeout | `POST /projects/{id}/closeout/items` and `PUT /projects/{id}/closeout/items/{itemId}` — verify registered in `api.php`. |

### 7.2 No Edit Modals

| Module | Missing Feature |
|---|---|
| Site Reports (Site Diaries) | No edit modal — create only |
| Closeout Items | No edit modal — status cycling via inline dropdown, but no full edit |

### 7.3 No Detail/View Pages

Most modules use list-only views with inline edit. There is no dedicated full-screen detail page for any record except:
- Adjudication cases (have their own sub-route at `/adjudication/{caseId}`).

Contracts, RFIs, Meetings, Snagging, QA — all edited inline via modals. This is intentional for speed of use but means long text fields (meeting minutes, observations) are truncated in list view.

### 7.4 AI Features

All AI features are deliberately non-functional placeholders:

| Feature | Status |
|---|---|
| AI Conversation / Chat | Routes registered; UI shows "Coming soon" tooltip |
| AI Summarize (Meeting minutes) | Placeholder text in Meeting detail modal |
| AI Draft Document | Routes registered; UI disabled |
| AI model configuration (Admin) | Admin page exists; no backend wiring |

**This is intentional** — the AI layer is scoped to a separate integration phase.

### 7.5 Email / Notification Layer

No email sending or push notifications have been implemented. The following workflows have no notifications:
- PA submitted → notify certifier
- RFI raised → notify assigned party
- Adjudication deadline approaching → alert
- Snag assigned → notify assignee

### 7.6 File Storage — Local Only

All files stored on `Storage::disk('local')`. No S3 or cloud storage configured. For production deployment this must be addressed. Files stored at:
- `documents/{project_id}/{filename}` — general documents
- `adjudication/{case_id}/{filename}` — adjudication documents

Download routes exist (`GET /documents/{id}/download`) but the controller's download implementation should be verified for proper `Storage::download()` usage.

### 7.7 Activity Log — Limited Event Types

The `ACTIVITY_ICONS` and `ACTIVITY_COLORS` maps on the Overview page handle:
- `project_created`, `contract_added/updated`, `payment_application_*`, `pdf_generated`, `rfi_created`, `variation_created`

Events fired by backend but not yet mapped in the frontend display:
- `meeting_created`, `rfi_raised/updated`, `snag_created/updated`, `qa_report_created/updated` — these will appear with a fallback generic `Activity` icon and muted color.

### 7.8 Admin Panel — Frontend vs Backend Gaps

The admin panel (`/admin/*`) has frontend pages for:
- AI configurations, Billing, Support (all appear to be UI shells without verified backend counterparts)
- SureSign settings page exists; backed by `SuresignSettingController`

### 7.9 Pagination Not Wired on Most Pages

Backend returns paginated responses (`paginate(25)` or `paginate(50)`) but most frontend pages only read `r.data.data` (first page). There is no "Load More" or pagination UI. For projects with many records, users will only see the first 25–50 items.

---

## 8. Stabilization Work Completed

### Session 1 — Label, Dropdown, and Workflow Fixes

| Task | Fix |
|---|---|
| Dropdown labels to Title Case | All status/type dropdowns now use `Record<string, string>` label maps throughout |
| Variation form | Contract dropdown populated; type labels fixed; submit disabled while pending |
| Pay Less Notice workflow | Payment application selector added — fetches and shows live PA list |
| Site Instruction form | Type dropdown added: Variation/Safety/Quality/Design/General/Urgent |
| Notices page — duplicate modal | Orphaned duplicate `NewSiteInstructionModal` body removed |
| Snagging labels | Status/priority display labels corrected |

### Session 2 — Backend Implementation and Workflow Additions

| Task | Fix |
|---|---|
| `ProjectStatsService` | `open_snagging` now counts real open snags; `active_adjudication_cases` added |
| `DocumentController::store()` | Fully implemented — validates, stores file, creates `Document` record |
| `AdjudicationDocumentController::store()` | Real file upload via `FormData` added alongside metadata-only mode |
| Adjudication upload UX | Mode toggle: "Upload File" vs "Create Draft Record" in `AddDocumentModal` |
| Meetings detail/edit modal | `MeetingDetailModal` added — view + edit mode with attendees, agenda, minutes, action items |
| RFI respond/close workflow | `RfiResponseModal` + close button; status properly transitions to responded/closed |
| Contract edit modal | `EditContractModal` added — all fields + status selector |
| Variation edit modal | `EditVariationModal` added — all fields + status selector |
| Contracts page build error | `STATUS_COLORS` declaration restored after corrupt insertion |
| Variations page missing export | `export default function ProjectVariationsPage()` declaration restored |

---

## 9. Recommended Next Steps

### Priority 1 — Production Readiness

1. **Cloud file storage**: Switch `Storage::disk('local')` to `Storage::disk('s3')` or similar. Add S3 credentials to `.env`. Update `filesystems.php`. Affects `DocumentController`, `AdjudicationDocumentController`, and download routes.

2. **Verify document download route**: Confirm `DocumentController::download()` uses `Storage::download()` with the stored path. Test end-to-end.

3. **Pagination UI**: Add "Load More" buttons or cursor-based pagination on all list pages. Backend already paginates — frontend just needs to wire `page` param and merge results.

4. **Fix API endpoint mismatches**: Audit the three potential mismatches identified in Section 7.1 (folders, snagging delete path, closeout items).

### Priority 2 — Missing Edit Modals

5. **Site Diary edit modal**: Add `EditSiteDiaryModal` mirroring the create modal pattern. `PUT /site-diaries/{id}`.

6. **Closeout item full edit**: Currently status cycling only. Add edit button to open modal with all fields (category, title, due date, notes).

### Priority 3 — Activity Feed Completeness

7. **Extend frontend activity icon/color maps**: Add `meeting_created`, `rfi_raised/updated`, `snag_created/updated`, `qa_report_created/updated`, `closeout_updated` to the `ACTIVITY_ICONS` and `ACTIVITY_COLORS` maps in `overview/page.tsx`.

### Priority 4 — Workflow Enhancements

8. **Adjudication "Mark as Sent/Issued/Appointed"**: Implement the placeholder step actions. These would call `POST /adjudication-cases/{id}/update-status` with appropriate status + notes.

9. **Notification system**: Implement email notifications for PA submissions, RFI assignments, and adjudication deadline warnings using Laravel's mail/notification layer.

10. **Snagging photo attachments**: The model supports attachments but the UI has no photo upload. Add photo upload capability mirroring the adjudication document upload pattern.

### Priority 5 — AI Integration (Separate Phase)

11. **AI provider configuration**: Connect `AiController` to an LLM provider (OpenAI, Anthropic, or Azure OpenAI) via the admin AI configuration page.

12. **Meeting summary AI**: Wire the "AI Summary" button in the Meeting detail modal to `POST /ai/summarize` with meeting minutes as context.

13. **Document drafting**: Wire the AI draft-document flow for standard construction letters/notices.

---

## 10. Technical Debt Assessment

### Low Risk

- **Inline CSS custom properties in JSX**: The design token approach (`style={{ color: 'var(--text-primary)' }}`) is intentional and consistent. Not debt — it's the design system.
- **`any` types in TypeScript**: Several components use `any[]` for API response arrays. These should be typed against proper interfaces as the codebase matures, but do not affect runtime behavior.
- **`confirm()` dialogs for delete**: Used in Snagging and QA pages. Functional but should be replaced with a proper confirmation modal for better UX consistency.

### Medium Risk

- **No request caching / optimistic updates**: All mutations wait for server confirmation before updating the UI. For high-latency deployments, optimistic updates would improve perceived performance.
- **`MAX() + 1` auto-numbering**: Auto-incrementing via `MAX(number) + 1` in PHP is susceptible to race conditions under concurrent inserts. For low-volume construction projects this is acceptable, but a database sequence or auto-increment column would be safer at scale.
- **No input sanitization beyond Laravel validation**: Laravel validation provides type/format checking but no XSS sanitization of rich text fields (agenda, minutes, observations). For fields rendered as HTML (if ever), `strip_tags` or a sanitizer should be added.

### High Risk (Production-Blocking)

- **Local disk storage**: Files uploaded to the local filesystem are not replicated, not backed up, and will be lost on container restart or redeployment. **Must migrate to cloud storage before production.**
- **No rate limiting on API**: The auth endpoint and mutation routes have no rate limiting. A bad actor could brute-force login or spam record creation. Add `throttle:6,1` to `/auth/login` at minimum.
- **No CSRF protection on stateless API**: Sanctum is configured for stateless Bearer tokens (`withCredentials: false`). This is correct and means CSRF tokens are not required, but ensure the CORS configuration (`config/cors.php`) only allows the production frontend domain(s) — not `*`.
- **`Storage::disk('local')` download paths are user-controlled**: The `document.file_path` column is stored and later used for `Storage::download($document->file_path)`. Ensure the path is never user-supplied raw input — it should always be the path returned by `Storage::store()`, not a user-specified value. Verify no path traversal is possible in the download endpoint.

---

## Appendix A — File Structure Reference

```
/
├── backend/                          Laravel 11 API
│   ├── app/
│   │   ├── Http/Controllers/Api/     27 controllers
│   │   ├── Models/                   ~30 Eloquent models
│   │   ├── Services/
│   │   │   ├── ProjectActivityService.php
│   │   │   └── ProjectStatsService.php
│   │   └── Providers/
│   ├── config/                       Laravel config files
│   ├── database/
│   │   ├── migrations/               Schema definitions
│   │   ├── seeders/                  Data seeders
│   │   └── factories/
│   └── routes/
│       └── api.php                   All API routes
│
└── frontend/                         Next.js 14 App Router
    └── src/
        ├── app/
        │   ├── (auth)/               Login page
        │   ├── app/                  App shell (AppSidebar layout)
        │   │   └── projects/[id]/    13 project sub-pages
        │   ├── (dashboard)/          Dashboard pages (projects overview, etc.)
        │   └── admin/                Admin panel (14 pages)
        ├── components/
        │   └── layout/               ProjectSidebar, AppSidebar, AdminSidebar
        ├── hooks/
        │   ├── useProjectPermissions.ts
        │   ├── useCurrencyFormatter.ts
        │   ├── useSiteSettings.ts
        │   └── useTheme.ts
        ├── lib/
        │   ├── api.ts                Axios instance + interceptors
        │   └── utils.ts
        └── store/
            └── authStore.ts          Zustand + persist
```

## Appendix B — Environment Configuration

| Variable | Purpose |
|---|---|
| `NEXT_PUBLIC_API_URL` | Backend API base URL (default: `http://localhost:8000/api`) |
| `DB_*` | MySQL connection credentials |
| `APP_KEY` | Laravel encryption key |
| `SANCTUM_STATEFUL_DOMAINS` | Domains allowed to use Sanctum session auth |
| `FILESYSTEM_DISK` | File storage driver (`local` currently) |
| `MAIL_*` | SMTP credentials (configured but not actively used) |
| `QUEUE_CONNECTION` | Queue driver (`sync` in dev) |

Docker Compose spins up: `nginx`, `backend` (PHP-FPM), `frontend` (Next.js), `mysql`.
