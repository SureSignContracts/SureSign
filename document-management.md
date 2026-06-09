  # SureSign — Document Management

## Architecture Overview

SureSign has two parallel storage systems for documents:

1. **Primary storage** — Laravel local disk at `storage/app/suresign/`
2. **Local mirror** — optional sync to a configured Windows/local path (e.g. `C:\Users\Admin\Documents\SureSign`)

The source of truth is always Laravel storage. The mirror is a convenience copy for desktop tools and should never be written to directly.

---

## Storage Path Structure

### Primary Storage (Laravel disk)

```
storage/app/suresign/
  {org_slug}/
    {project_slug}/
      01_Contracts/
        Main Contract/
        Subcontracts/
        Consultant Agreements/
        Supplier Agreements/
      Subcontracts/
        {Trade Package Name}/
          01 Tender Enquiry/
          02 Schedule of Documents/
          03 Drawings/
          04 Specifications/
          05 Pricing Documents/
          06 Contract Draft/
          07 Correspondence/
          08 Returned Tender/
          09 Executed Contract/
      02_Commercial/
      03_Payment Applications/
      04_Variations/
      05_Notices/
      06_RFIs/
      07_Meetings/
      08_QA Reports/
      09_Snagging/
      10_Closeout/
      11_Adjudication/
      12_Site Reports/
      13_AI Generated/
      99_General Documents/
```

### Local Mirror

```
{mirror_root}/
  {Company Name}/
    {Project Name} {Project Code}/
      (same module folder structure as primary storage)
```

---

## Trade Packages

### What They Are

Trade packages represent distinct work packages let to subcontractors on a construction project. Examples: Groundworks, Brickwork, M&E, Roofing. Each trade package is administered separately with its own documents, correspondence, and contract.

### Database Records

**`trade_packages` table** — one record per trade package:
- `organization_id` — owning company
- `project_id` — owning project
- `name` — human-readable name (e.g. "Groundworks")
- `slug` — URL-safe identifier, unique per project
- `package_code` — short code (e.g. `GW`, `ME`, `BW`)
- `package_reference` — full reference used on documents
- `status` — `active` / `inactive`
- `is_custom` — whether the package was custom-named (not from standard list)
- `source_type` — `standard` or `custom`

**`trade_package_folders` table** — one record per sub-folder per package:
- Each package gets 9 standard folders created automatically on generation

### Standard Package Codes

| Trade Package Name      | Code |
|-------------------------|------|
| Concrete Frame          | CF   |
| Brickwork               | BW   |
| Windows & Doors         | WD   |
| Roofing                 | RF   |
| M&E                     | ME   |
| Groundworks             | GW   |
| Drylining & Plastering  | DP   |
| Steelwork               | ST   |
| Landscaping             | LS   |
| Demolition              | DM   |
| Fire Stopping           | FS   |
| External Works          | EW   |
| Access Control          | AC   |
| CCTV                    | CC   |
| Solar PV                | SP   |

For packages not in the standard list, a code is auto-generated from the initials of each word in the name (e.g. "Curtain Walling" → `CW`). If a code already exists in the project, a suffix is appended (`-01`, `-02`, etc.).

### Standard Sub-Folders per Package

Every trade package gets these 9 folders in the database (`trade_package_folders`) and on disk:

| Key                    | Folder Name               |
|------------------------|---------------------------|
| `tender_enquiry`       | `01 Tender Enquiry`       |
| `schedule_of_documents`| `02 Schedule of Documents`|
| `drawings`             | `03 Drawings`             |
| `specifications`       | `04 Specifications`       |
| `pricing_documents`    | `05 Pricing Documents`    |
| `contract_draft`       | `06 Contract Draft`       |
| `correspondence`       | `07 Correspondence`       |
| `returned_tender`      | `08 Returned Tender`      |
| `executed_contract`    | `09 Executed Contract`    |

---

## Generate Trade Package Folder Feature

### Purpose

Allows users to bulk-create multiple trade packages for a project in one action, rather than creating them one by one. The feature was implemented as part of the Star Pacific / Colchester trade package workflow.

### How It Works

1. User opens the **Generate Package** modal on the project's Subcontracts page.
2. User selects from the standard list of trade packages and/or adds custom packages.
3. On submission, the frontend calls `POST /api/projects/{project}/trade-packages/generate`.
4. `GenerateTradePackageFoldersController` dispatches to `GenerateTradePackageFoldersService`.
5. For each package definition:
   - Checks if a package with the same name already exists in the project (skips duplicates).
   - Resolves or generates a package code.
   - Creates the `TradePackage` database record.
   - Calls `TradePackage::createStandardFolders()` to create the 9 `TradePackageFolder` records.
   - Creates physical storage folders on the Laravel local disk under `Subcontracts/{Package Name}/`.
6. Returns a summary of created and skipped packages.

### Service: `GenerateTradePackageFoldersService`

Location: `app/Services/TradePackages/GenerateTradePackageFoldersService.php`

Key method:
```php
generate(Project $project, array $packages, int $userId): array{ created: string[], skipped: string[] }
```

Each package definition array may contain:
- `name` (required) — trade package name
- `pkg_code` (optional) — override the auto-resolved code
- `is_custom` (optional, bool) — mark as custom rather than standard
- `original_name` (optional) — original name before any rename

### Frontend Components

- `GeneratePackageModal` — modal for selecting and configuring packages to generate
- `ProjectDocumentsExplorer` — file browser component for viewing project documents

---

## PHPWord Document Generation Workflow

Used by `TradePackagePackageGenerationController` to generate subcontract DOCX packages.

### Process

1. User selects a DOCX template and fills in contractor details via the UI.
2. `POST /api/trade-packages/{tradePackage}/generate-package` is called.
3. The controller loads the `DocumentTemplate` and locates the `.docx` file on disk.
4. `PhpOffice\PhpWord\TemplateProcessor` is used to replace `${placeholder}` tokens in the template.
5. The generated file is saved to `Subcontracts/{Package Name}/{filename}.docx` in primary storage.
6. Unresolved placeholders are detected by inspecting the raw XML in the saved DOCX.
7. A `FileUpload` record is created for the file.
8. A `Document` record is created (polymorphic, linked to the `TradePackage`).
9. `LocalDocumentMirrorService::mirrorFileUpload()` is called to sync the file to the local mirror.
10. A project activity log entry is created.

### Template Placeholders

The following placeholders are replaced in the DOCX template:

| Placeholder                   | Source                          |
|-------------------------------|---------------------------------|
| `${company_name}`             | Organization name               |
| `${project_name}`             | Project name                    |
| `${project_reference}`        | Project code                    |
| `${site_address}`             | Project address                 |
| `${employer_name}`            | Client name or project metadata |
| `${architect_name}`           | Project metadata                |
| `${qs_name}`                  | Project metadata                |
| `${principal_designer}`       | Project metadata                |
| `${trade_package}`            | Trade package name              |
| `${package_reference}`        | Trade package reference         |
| `${pkg_code}`                 | Trade package code              |
| `${package_scope}`            | Trade package description       |
| `${contractor_name}`          | Form input                      |
| `${contractor_legal_name}`    | Form input                      |
| `${contractor_company_number}`| Form input (Companies House)    |
| `${contractor_registered_address}` | Form input (Companies House) |
| `${contractor_contact_name}`  | Form input                      |
| `${contractor_email}`         | Form input                      |
| `${contract_sum}`             | Form input                      |
| `${contract_sum_words}`       | Form input                      |
| `${start_date}`               | Form input                      |
| `${completion_date}`          | Form input                      |
| `${contract_duration}`        | Form input                      |
| `${retention_percentage}`     | Form input                      |
| `${retention_half_percentage}`| Form input                      |
| `${ld_rate}`                  | Form input                      |
| `${rectification_period}`     | Form input                      |
| `${valuation_day}`            | Form input                      |
| `${document_date}`            | Form input                      |
| `${drawing_schedule_ref}`     | Form input                      |
| `${specification_ref}`        | Form input                      |
| `${pricing_doc_ref}`          | Form input                      |
| `${prelims_ref}`              | Form input                      |

### Generated Filename Format

```
{project_code}-{package_code}_Subcontract_Package_{contractor_name}_{date}.docx
```

Example: `SP-001-GW_Subcontract_Package_Acme_Groundworks_2026-06-02.docx`

---

## Companies House Integration

### Purpose

Allows users to look up UK company information when filling in contractor details on subcontract document generation forms. Keeps the API key server-side and avoids CORS issues.

### API Key

Set `COMPANIES_HOUSE_API_KEY` in `.env` / `.env.docker`. Free API key available at: https://developer.company-information.service.gov.uk/manage-applications

### Endpoints

All routes are admin-only proxies:

| Method | Route                                                  | Purpose                        |
|--------|--------------------------------------------------------|--------------------------------|
| GET    | `/api/admin/companies-house/search?q={query}`          | Search for companies by name   |
| GET    | `/api/admin/companies-house/{companyNumber}`           | Get full company details       |
| GET    | `/api/admin/companies-house/{companyNumber}/officers`  | Get company officers list      |

### Data Returned

Search results include: company number, name, status, type, date of creation, registered address, SIC codes.

Detail view additionally includes: accounts data, confirmation statement, insolvency history, jurisdiction.

---

## Folder Path Generation (SureSignFolderPathService)

### Purpose

Centralised, security-hardened path-building. All folder and file name segments used on the filesystem must pass through this service.

### Key Methods

```php
// Map a module key to a physical folder name
SureSignFolderPathService::moduleKeyToFolderName('subcontracts'); // → 'Subcontracts'

// Make a string safe for filesystem use (Windows + Linux + macOS)
SureSignFolderPathService::sanitizeSegment('Acme / Build Ltd'); // → 'Acme  Build Ltd'

// Build a project-level folder name
SureSignFolderPathService::projectFolderName('City Road Dev', 'SP-001'); // → 'City Road Dev SP-001'

// List all standard project mirror folders
SureSignFolderPathService::allMirrorFolders(); // → ['01_Contracts/Main Contract', ...]
```

### Sanitization Rules Applied

1. Strip path-traversal sequences (`..`, `./`, `.\\`)
2. Remove characters forbidden on Windows or with special filesystem meaning: `/ \ : * ? " < > |`
3. Collapse multiple whitespace to a single space
4. Trim leading/trailing dots and whitespace (Windows dislikes trailing dots in directory names)
5. Limit to 100 characters
6. Fall back to `'Untitled'` if the result is empty

---

## Local Document Mirror

### Configuration

The mirror is configured in two ways (database takes precedence):

1. **Admin settings panel** — Super Admin can set path and toggle at runtime
2. **Environment variable** — `SURESIGN_LOCAL_MIRROR_PATH`

### Docker Setup

Map the container-side path to the host Windows Documents folder via `docker-compose`:

```yaml
volumes:
  - "C:/Users/Admin/Documents/SureSign:/var/www/html/storage/app/local-mirror/SureSign"
```

Set: `SURESIGN_LOCAL_MIRROR_PATH=/var/www/html/storage/app/local-mirror/SureSign`

### Safety Guarantees

- Never throws — all public methods are catch-all safe
- Mirror failure does NOT affect the original upload
- All user-supplied strings are sanitized before reaching the filesystem
- Path traversal is prevented: no raw user input reaches the filesystem directly
- Only Super Admin can configure the mirror path

### Mirror Routing for Trade Package Files

When a `FileUpload` has `module_key = 'subcontracts'` and a `trade_package_id`:

```
Mirror path: {root}/{Company}/{Project}/Subcontracts/{Package Name}/{filename}
```

This keeps all files for a trade package together in the mirror, matching the primary storage layout.

---

## Template Type System

### The `template_type` Field

`document_templates` records carry a `template_type` string column. For subcontract document generation this column is set to one of the values in `SUBCONTRACT_TEMPLATE_TYPES`. Templates without a `template_type` are treated as generic or legacy templates.

### SUBCONTRACT_TEMPLATE_TYPES

| Value                    | Description                                                |
|--------------------------|------------------------------------------------------------|
| `master_package`         | A single all-in-one DOCX covering the full subcontract package |
| `procurement_summary`    | A summary document used internally during procurement      |
| `tender_enquiry_letter`  | The formal letter sent to tenderers                        |
| `schedule_of_documents`  | The list of documents included in the tender/contract set  |
| `subcontract_draft`      | The draft subcontract agreement                            |
| `other`                  | Any template that does not fit the above categories        |

### Template Lookup Priority

When generating a document the system selects a template via `DocumentTemplate::findForGeneration($type, $organizationId)`:

1. **Company-specific first** — looks for a template with the given `template_type` whose `organization_id` matches the current organisation.
2. **Global fallback** — if no company-specific template is found, falls back to a global template (`organization_id = null`) with the same `template_type`.
3. If no template is found at either level, generation fails with a validation error.

This allows each organisation to override any default template without affecting others.

### Generation Modes

Two modes are supported on `POST /api/trade-packages/{id}/generate-package`:

| Mode                 | Behaviour                                                                                    |
|----------------------|----------------------------------------------------------------------------------------------|
| `master_package`     | Generates a single DOCX using the `master_package` template. All placeholders are replaced in one file. |
| `separate_documents` | Generates one DOCX per entry in `selected_document_types`, using the matching template for each type. |

### Request Format

```json
POST /api/trade-packages/{id}/generate-package
{
  "generation_type": "master_package | separate_documents",
  "selected_document_types": ["tender_enquiry_letter", "schedule_of_documents", "subcontract_draft"],
  "contractor_name": "Acme Groundworks Ltd",
  "contractor_legal_name": "Acme Groundworks Limited",
  "contractor_company_number": "12345678",
  "contractor_registered_address": "1 Example Street, London, EC1A 1BB",
  "contractor_contact_name": "John Smith",
  "contractor_email": "john@acmegroundworks.co.uk",
  "contract_sum": "125000.00",
  "contract_sum_words": "One Hundred and Twenty-Five Thousand Pounds",
  "start_date": "2026-07-01",
  "completion_date": "2026-12-31",
  "contract_duration": "26 weeks",
  "retention_percentage": "3",
  "retention_half_percentage": "1.5",
  "ld_rate": "500",
  "rectification_period": "12 months",
  "valuation_day": "25",
  "document_date": "2026-06-02",
  "drawing_schedule_ref": "DS-001",
  "specification_ref": "SPEC-001",
  "pricing_doc_ref": "PD-001",
  "prelims_ref": "PRELIM-001"
}
```

`selected_document_types` is required when `generation_type` is `separate_documents` and is ignored when `generation_type` is `master_package`.

### Filename Conventions

#### `master_package`

```
{project_code}-{package_code}_Subcontract_Package_{contractor_name}_{date}.docx
```

Example: `SP-001-GW_Subcontract_Package_Acme_Groundworks_2026-06-02.docx`

#### `separate_documents` — per-type filenames

| Template Type           | Filename Pattern                                                     |
|-------------------------|----------------------------------------------------------------------|
| `procurement_summary`   | `{project_code}-{package_code}_Procurement_Summary_{date}.docx`     |
| `tender_enquiry_letter` | `{project_code}-{package_code}_Tender_Enquiry_Letter_{date}.docx`   |
| `schedule_of_documents` | `{project_code}-{package_code}_Schedule_of_Documents_{date}.docx`   |
| `subcontract_draft`     | `{project_code}-{package_code}_Subcontract_Draft_{date}.docx`       |
| `other`                 | `{project_code}-{package_code}_Document_{date}.docx`                |

All filenames are sanitized through `SureSignFolderPathService::sanitizeSegment()` before writing to disk.

---

## File Deletion

### Soft Delete Behaviour

File deletion in SureSign is non-destructive. Both the `file_uploads` and `documents` tables carry a `deleted_at` timestamp column (Laravel soft deletes). When a document is deleted:

- The `deleted_at` column is set to the current timestamp.
- The record is excluded from all standard Eloquent queries automatically.
- The physical file on disk (Laravel storage and any local mirror copy) is **retained**. No bytes are removed.

This ensures that files remain recoverable and that audit trails remain intact.

### Endpoint

```
DELETE /api/file-uploads/{fileUpload}
```

**Success response (HTTP 200):**

```json
{
  "success": true,
  "message": "Document deleted successfully."
}
```

The `{fileUpload}` route parameter is the integer primary key of the `file_uploads` record.

### Authorization

A request to this endpoint is permitted when **any** of the following conditions is met:

1. The authenticated user has the role `Admin` or `Super Admin`.
2. The authenticated user belongs to the same `organization_id` as the `FileUpload` record being deleted.

Requests that satisfy neither condition receive a `403 Forbidden` response. Authorization is enforced via a Laravel Policy (`FileUploadPolicy`) — it is not checked inline in the controller.

### Audit Log

On successful deletion, `ProjectActivityService` records a `document_deleted` event. The log entry includes:

- Event type: `document_deleted`
- Original filename (taken from the `FileUpload` model before soft deletion)
- Associated project context (project ID and project code where available)
- Timestamp and acting user ID

This provides a full audit trail for compliance and dispute-resolution purposes.

### Notification

On successful deletion, `NotificationService` sends a `file_deleted` notification to the acting user:
- Type: `file_deleted`
- Title: "Document Deleted"
- Message: "Document removed from active documents."

Similarly, on successful file upload, `NotificationService` sends a `file_uploaded` notification.

### UI Confirmation Modal Pattern

Before issuing the `DELETE` request the frontend renders a confirmation modal. The pattern is:

1. User clicks a "Delete" action on a document row or card.
2. A modal appears with the document filename and a warning that the action cannot easily be undone.
3. The modal has two buttons: **Cancel** (dismisses the modal, no request sent) and **Delete Document** (issues the `DELETE` request).
4. While the request is in-flight the Delete button is disabled and shows a loading indicator.
5. On success the modal closes and the document is removed from the list without a full page reload.
6. On error a toast notification displays the error message and the modal remains open.

The modal component is `GeneratePackageModal`-style (re-uses the shared modal pattern in `components/documents/`).

### Future-Ready Notes

The soft-delete approach deliberately leaves room for the following features, which can be added without schema changes:

| Feature | Notes |
|---|---|
| **Restore** | Call `restore()` on the `FileUpload` model; clear `deleted_at`. |
| **Recycle bin** | Query `FileUpload::onlyTrashed()` to display soft-deleted records. |
| **Permanent delete** | Call `forceDelete()` on the model and then delete the physical file from storage. |
| **Bulk delete** | Accept an array of IDs, apply the same policy check per record, soft-delete in a single transaction. |

None of these require a migration. They only need new endpoints, policy clauses, and UI surfaces.
