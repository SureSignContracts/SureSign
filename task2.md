# SURESIGN CONSTRUCTION OPERATIONS PLATFORM — FULL SYSTEM RESTRUCTURE PROMPT

I am rebuilding and restructuring an existing construction administration SaaS platform called SureSign.

The current system already has:

* authentication,
* dashboard UI,
* Docker environment,
* Laravel backend,
* React/Next frontend structure.

However, the architecture needs to be completely restructured into a proper multi-tenant construction operations platform.

The goal is NOT to create a generic CRUD dashboard.

The goal is to create a white-label commercial construction operations and contract administration platform where construction companies manage projects, commercial administration, RFIs, notices, documents, reports and AI-assisted workflows.

The AI should remain mostly invisible to the end user.

The platform should feel like:

* Procore,
* Aconex,
* Autodesk Construction Cloud,
* enterprise construction administration software.

The system must support:

* multi-tenancy,
* white-label branding,
* project-centric workflows,
* document generation,
* AI integrations later,
* scalable architecture.

---

## CORE SYSTEM ARCHITECTURE

The platform must have 3 architectural levels:

1. SUPER ADMIN LEVEL (SureSign Internal)
2. COMPANY/TENANT LEVEL (Construction Company)
3. PROJECT LEVEL (Individual Construction Project)

---

1. SUPER ADMIN LEVEL

---

Purpose:
Manage all tenant companies using the platform.

Super admin routes:

* /admin/*
* separate from tenant app routes.

Super admin dashboard should include:

* total companies,
* active subscriptions,
* storage usage,
* AI/API usage,
* active projects across platform,
* support logs,
* tenant management,
* template management,
* system settings.

Super admin sidebar:

* Dashboard
* Companies
* Templates
* AI Configurations
* Storage
* Billing / Subscriptions
* Users
* Support
* System Logs
* Settings

Super admin should NOT directly manage project RFIs or commercial workflows.

---

2. COMPANY / TENANT LEVEL

---

Each construction company is an isolated tenant.

Each tenant has:

* separate users,
* projects,
* branding,
* files,
* workflows,
* templates.

Every major database table must support tenancy using:

* company_id

The tenant/company portal should be accessed from:

* /app/*
  or
* /company/*

The tenant portal is the operational workspace for contractors.

Tenant sidebar:

* Dashboard
* Projects
* Commercial
* Site Admin
* Contracts
* Documents
* Reports
* AI Assistant
* Team
* Settings

---

## COMPANY INFORMATION STRUCTURE

Each company must store:

* Company name
* Company address
* Contact name
* Contact numbers
* Email address
* Company registration number
* VAT number
* Company logo
* Company letterhead assets
* Footer configuration
* Header configuration
* Brand colors

The system must support white-label branding.

All generated documents must automatically apply:

* logo,
* letterhead,
* header,
* footer,
* company details,
* branding styles.

---

3. PROJECT LEVEL

---

Projects are the core operational unit.

Each project belongs to:

* one company

Projects must contain the following information:

* Project name
* Project number
* Type of contract
* Contract value
* Type of work
* Start date
* Completion date
* Client information
* Project status

Each project should have its own workspace and modules.

Project routes example:

* /app/projects/{project_id}

Project sidebar:

* Overview
* Contracts
* Commercial
* RFIs
* Variations
* Site Reports
* Meetings
* Notices
* QA Reports
* Documents
* Snagging
* Closeout

---

## PROJECT FILE STRUCTURE

Each project must automatically generate and manage the following document categories:

1. Contract documents
2. Contract summary file
3. Tender breakdown
4. RAMS and method statements / risk assessments
5. Monthly payment applications
6. Valuations by main contractor
7. QA reports
8. Letters
9. Notices
10. Snagging
11. Operation and maintenance manuals
12. Collateral warranties and other warranties

The system must automatically generate folder structures for each project.

Example:

01_Contracts
02_Contract_Summary
03_Tender_Breakdown
04_RAMS
05_Payment_Applications
06_Valuations
07_QA_Reports
08_Letters
09_Notices
10_Snagging
11_Operation_Maintenance
12_Warranties

---

## COMMERCIAL MODULE

The commercial module should support:

* Interim applications
* Payment notices
* Pay less notices
* Variations
* Quotations
* Final account statements

The commercial dashboard should prioritize:

* overdue actions,
* pending approvals,
* valuation deadlines,
* payment tracking,
* variation status.

This should feel operational and deadline-driven.

---

## SITE ADMIN MODULE

The site administration module should support:

* RFIs
* Site instructions
* Meeting minutes
* Daily diaries
* Delay notices
* EOT requests
* Progress reports

The system architecture should support future AI integrations for:

* meeting minute generation,
* document summarization,
* email parsing,
* variation extraction,
* action extraction,
* smart reminders.

---

## DOCUMENT MANAGEMENT

The platform must support:

* file uploads,
* categorized storage,
* automatic naming conventions,
* versioning,
* document history,
* PDF generation,
* Word export,
* automatic filing.

Naming convention example:

2026-05-20_InterimApplication_05_ProjectName.pdf

---

## DATABASE DESIGN REQUIREMENTS

Design scalable multi-tenant database relationships.

Core tables should include:

companies
users
projects
project_members
contracts
documents
rfis
variations
payment_applications
meetings
notices
qa_reports
snagging_items
warranties

Every operational table should support:

* company_id
* project_id where applicable

Use scalable relational architecture.

---

## PERMISSIONS & ROLES

Support role-based permissions.

Example roles:

* Super Admin
* Company Admin
* Project Manager
* Quantity Surveyor
* Site Manager
* Commercial Manager
* Read-only User

Permissions should be modular and scalable.

---

## UI / UX REQUIREMENTS

The UI should feel:

* enterprise,
* modern,
* operational,
* clean,
* construction-focused.

The system should prioritize:

* actionable workflows,
* deadlines,
* risk items,
* outstanding tasks.

Avoid generic admin dashboard design.

The dashboard should answer:
“What needs attention today?”

NOT:
“Here are some statistics.”

---

## TECH STACK

Current environment:

* Laravel backend
* React/Next frontend
* Docker local development

Keep existing Docker environment.

Do NOT rebuild infrastructure unnecessarily.

Focus on:

* restructuring architecture,
* route hierarchy,
* database structure,
* navigation,
* permissions,
* multi-tenancy,
* project-centric workflows.

---

## IMPORTANT DEVELOPMENT REQUIREMENTS

The platform must be built with long-term scalability in mind.

Avoid:

* tightly coupled modules,
* global mixed workflows,
* flat navigation structures,
* single-level dashboards.

Use:

* modular architecture,
* scalable routing,
* isolated tenant data,
* service-based backend structure,
* reusable document workflows.

The platform should be prepared for:

* AI orchestration,
* document automation,
* white-label SaaS deployment,
* enterprise client onboarding,
* future mobile support.

Generate:

* recommended architecture,
* folder structure,
* routing structure,
* database schema recommendations,
* module separation,
* UI hierarchy,
* suggested backend services,
* suggested frontend structure,
* workflow recommendations.
