# AGENTS.md

## Project Overview

This project is **SureSign**, a Laravel 11 + Next.js 14 platform for UK construction contract administration.

The system focuses on practical construction workflows, including:

* Contract-first project administration
* Document management
* Adjudication support
* Subcontract package management
* Prompt-library-based AI workflows
* Local Windows folder sync
* Star Pacific / Colchester trade package workflows

Avoid overengineering. Prioritize clear, maintainable, production-ready code.

---

## Tech Stack

### Backend

* Laravel 11
* PHP 8.2+
* MySQL or MariaDB
* Laravel queues where appropriate
* Laravel policies for authorization
* Laravel Form Requests for validation

### Frontend

* Next.js 14
* TypeScript
* Tailwind CSS
* React components
* Server actions / API routes where appropriate

---

## Coding Principles

1. Prefer simple, readable code over clever abstractions.
2. Keep business logic out of controllers and React components.
3. Use service classes for construction-specific workflows.
4. Use policies for permission checks.
5. Use Form Requests for validation.
6. Avoid introducing unnecessary packages.
7. Do not rewrite large parts of the project unless explicitly asked.
8. Make small, safe, incremental changes.

---

## Backend Guidelines

### Controllers

Controllers should be thin.

They may:

* Validate requests
* Call services
* Return responses

They should not contain complex business rules.

### Services

Use service classes for workflows such as:

* Contract creation
* Subcontract package generation
* Document processing
* Adjudication preparation
* Folder sync logic
* Prompt library execution

Example naming:

```php
App\Services\Contracts\CreateContractService
App\Services\Documents\DocumentSyncService
App\Services\Adjudication\PrepareAdjudicationBundleService
```

### Models

Keep Eloquent models clean.

Acceptable in models:

* Relationships
* Casts
* Scopes
* Simple helpers

Avoid putting large workflow logic in models.

### Validation

Use Form Request classes for non-trivial validation.

Example:

```php
StoreContractRequest
UpdateSubcontractPackageRequest
UploadProjectDocumentRequest
```

### Authorization

Use Laravel Policies for access control.

Examples:

```php
ContractPolicy
ProjectPolicy
DocumentPolicy
AdjudicationPolicy
```

---

## Frontend Guidelines

### Components

Prefer small, focused components.

Use this structure where useful:

```txt
components/
  contracts/
  documents/
  adjudication/
  subcontract-packages/
  shared/
```

### TypeScript

Use explicit types for API responses and props.

Avoid `any` unless absolutely necessary.

### Styling

Use Tailwind CSS.

Keep UI practical and clean. This is a business/workflow application, not a marketing site.

Prioritize:

* Readability
* Clear actions
* Good spacing
* Consistent tables
* Useful filters
* Good empty states

---

## Construction Workflow Context

This project is for construction contract administration.

When implementing features, consider:

* Projects
* Employers
* Contractors
* Subcontractors
* Trade packages
* Notices
* Payment applications
* Variations
* Extensions of time
* Adjudication bundles
* Supporting documents
* Contract correspondence

Use construction-specific naming when it improves clarity.

---

## AI Workflow Context

The project currently uses a **prompt-library-based AI workflow**.

Do not assume direct AI API integration unless explicitly requested.

AI-related features should usually involve:

* Prompt templates
* Reusable prompt categories
* Manual copy/paste workflows
* Document preparation
* Structured outputs
* Admin-editable prompt libraries

Avoid adding OpenAI, Anthropic, or other AI API calls unless specifically requested.

---

## Document Management Context

Document workflows are important.

When working on document features, consider:

* File versioning
* Upload metadata
* Project association
* Contract association
* Document category
* Local Windows folder sync
* Audit trail
* Searchability
* Bundle generation

Avoid destructive file operations unless explicitly requested.

---

## Database Guidelines

Use clear table names.

Examples:

```txt
projects
contracts
contract_documents
subcontract_packages
adjudication_cases
adjudication_documents
prompt_templates
folder_sync_mappings
```

Use migrations for all schema changes.

Prefer foreign keys where appropriate.

---

## Testing Guidelines

When adding backend logic, add or update tests where practical.

Useful test areas:

* Contract creation
* Document upload
* Permission checks
* Subcontract package workflows
* Adjudication bundle preparation
* Prompt template handling

Do not add excessive tests for simple UI-only changes.

---

## Response Style for AI Coding Agents

When making code changes:

1. Explain what will change briefly.
2. Modify only the necessary files.
3. Preserve existing conventions.
4. Show changed files clearly.
5. Mention risks or assumptions.
6. Avoid unrelated refactoring.

When unsure, choose the smallest safe implementation.

---

## Things to Avoid

Do not:

* Rebuild the architecture without permission
* Add unnecessary dependencies
* Mix business logic into controllers
* Use vague names like `DataService` or `HelperService`
* Add AI API integration unless requested
* Overcomplicate document sync
* Remove existing workflows without confirmation
* Make broad formatting-only changes across many files

---

## Preferred Implementation Style

Use practical, production-friendly Laravel and Next.js patterns.

Favor:

* Clear naming
* Small services
* Explicit validation
* Good TypeScript types
* Reusable UI components
* Safe migrations
* Simple database relationships
* Clean workflow-based code

The goal is to build a reliable construction contract administration system, not a generic SaaS template.
