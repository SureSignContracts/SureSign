# SureSign — Project Context

## Overview

SureSign is a construction contract administration platform for UK-based construction companies. It is built on **Laravel 11** (backend API) and **Next.js 14** (frontend). The system helps construction companies manage their projects, contracts, subcontract packages, documents, payments, variations, adjudication, and related workflows.

The target users are contractors and their teams who need to administer JCT/NEC-style construction contracts in a structured, auditable way.

---

## Tech Stack

| Layer     | Technology                        |
|-----------|-----------------------------------|
| Backend   | Laravel 11, PHP 8.2+, MySQL/MariaDB |
| Frontend  | Next.js 14, TypeScript, Tailwind CSS |
| Storage   | Laravel local disk (`storage/app/suresign/`) |
| Mirror    | Optional local Windows folder sync via `LocalDocumentMirrorService` |
| Documents | PHPWord (`phpoffice/phpword`) for DOCX generation |
| Auth      | Laravel Sanctum                   |

---

## Document Structure

Files and documents in SureSign are organised in a clear hierarchy:

```
Company (Organization)
  └── Project
        └── Module (Contracts, Subcontracts, Commercial, etc.)
              └── Documents / Files
```

The physical storage path mirrors this structure:

```
storage/app/suresign/{org_slug}/{project_slug}/{Module Folder}/{file}
```

For example:
```
storage/app/suresign/acme-construction/city-road-development/Subcontracts/Groundworks/contract-draft.docx
```

For the local mirror (Windows desktop sync):
```
{mirror_root}/{Company Name}/{Project Name}/{Module Folder}/{file}
```

---

## Module Folders

Every project has a standard set of module folders created automatically on project creation:

| Module Key             | Physical Folder Name       |
|------------------------|---------------------------|
| `contracts`            | `01_Contracts`             |
| `subcontracts`         | `Subcontracts`             |
| `commercial`           | `02_Commercial`            |
| `payment_applications` | `03_Payment Applications`  |
| `variations`           | `04_Variations`            |
| `notices`              | `05_Notices`               |
| `rfis`                 | `06_RFIs`                  |
| `meetings`             | `07_Meetings`              |
| `qa_reports`           | `08_QA Reports`            |
| `snagging`             | `09_Snagging`              |
| `closeout`             | `10_Closeout`              |
| `adjudication`         | `11_Adjudication`          |
| `site_reports`         | `12_Site Reports`          |
| `ai_generated`         | `13_AI Generated`          |
| `general`              | `99_General Documents`     |

The `01_Contracts` folder also has sub-folders: `Main Contract`, `Subcontracts`, `Consultant Agreements`, `Supplier Agreements`.

---

## Trade Package Workflow

Trade packages represent distinct work packages on a project (e.g. Groundworks, Brickwork, M&E). Each trade package:

1. Is created under a project in the Subcontracts module.
2. Gets a **package code** (e.g. `GW`, `BW`, `ME`) resolved from a standard map or generated from initials.
3. Gets a **package reference** for identifying documents.
4. Has **9 standard sub-folders** created automatically in database and on disk.
5. Can have a **subcontract document package** generated from a DOCX template.

Trade packages are created either individually or in bulk via the **Generate Trade Package Folder** feature.

---

## Key Services

### `ProjectStorageService`
Manages the primary Laravel storage folder structure for projects.
- `projectRoot(Project)` — builds `suresign/{org_slug}/{project_slug}`
- `modulePath(Project, moduleKey)` — builds module-specific path
- `createProjectFolders(Project)` — creates all standard folders on disk + triggers mirror

### `LocalDocumentMirrorService`
Mirrors files from Laravel storage to a configured local path (e.g. Windows Documents folder).
- Toggle enabled/disabled at runtime via admin settings (no deployment needed)
- Never fails the original upload — errors are logged and silently skipped
- Mirrors `FileUpload`, `Document`, and `AdjudicationDocument` records
- For subcontract files, routes to `Subcontracts/{Package Name}/` within the mirror tree

### `SureSignFolderPathService`
Centralised, security-hardened path-building helpers.
- `moduleKeyToFolderName(key)` — maps module key to physical folder name
- `sanitizeSegment(value)` — makes a string safe for filesystem use (Windows/Mac/Linux)
- `projectFolderName(name, code)` — builds the project-level folder display name
- `allMirrorFolders()` — lists all folders to create when mirroring a project

### `GenerateTradePackageFoldersService`
Bulk-creates trade packages for a project.
- Resolves package codes from a standard map or from name initials
- Deduplicates codes within the project (appends `-01`, `-02` etc.)
- Skips packages that already exist in the project
- Creates the `TradePackage` record, standard `TradePackageFolder` records, and physical storage folders

### `DocumentGenerationService`
Generates documents (PDFs, DOCX) from prompt templates and stored templates.

### `ProjectActivityService`
Records activity log entries for projects (uploads, document generation, status changes, etc.).

### `NotificationService`
Creates user-facing notification records in the `suresign_notifications` table.
- `send(User $user, string $type, string $title, string $message, array $data = [])` — creates and returns a notification record
- Type constants: `FILE_UPLOADED`, `FILE_DELETED`, `DOCUMENT_GENERATED`, `TEMPLATE_UPLOADED`, `TRADE_PACKAGE_GENERATED`, and others
- Called from controllers after successful operations (document upload, delete, template upload, package generation)

---

## Admin Panel Structure

The admin panel (`/admin`) is available to Super Admin and Admin users. It includes:

- **Dashboard** — stats across all companies (total companies, projects, users, documents, storage, monthly AI usage)
- **Companies** — manage organizations/companies
- **Projects** — view all projects across companies
- **Documents** — document template management and admin document explorer
- **Find** — cross-company search (admin find page)
- **Users** — user management
- **Settings** — SureSign-wide settings including local mirror path configuration and Companies House API key
- **Prompts** — prompt library / template management
- **Notifications** — user notification center at `/admin/notifications`

---

## Authorization

- **Super Admin** — full access to all companies and admin panel features
- **Admin** — organization-scoped admin access
- **User** — project-level access within their organization

Authorization is enforced via Laravel Policies. The local mirror path can only be configured by Super Admin.

---

## Environment Configuration

Key environment variables:

| Variable                    | Purpose                                      |
|-----------------------------|----------------------------------------------|
| `COMPANIES_HOUSE_API_KEY`   | UK Companies House Public Data API key       |
| `SURESIGN_LOCAL_MIRROR_PATH`| Container-side path for local document mirror|

The local mirror is also configurable at runtime via the admin settings panel, which takes precedence over env values.
