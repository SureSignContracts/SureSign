You are continuing development on my existing SureSign platform.

Context:
SureSign is a Laravel 11 + Next.js 15 multi-tenant Construction Operations & Contract Administration Platform.

Current modules already working:
- Contracts
- Commercial / Payment Applications
- Projects
- Companies
- Branding
- Templates
- Admin/Tenant Workspaces

Current issue:
The following modules are incomplete or non-functional:
- Snagging
- QA Reports
- Closeout

There are also admin navigation and project context issues that need to be fixed.

IMPORTANT:
Do NOT redesign the system.
Do NOT rebuild existing architecture.
Do NOT change authentication or branding.
Work only within the current project structure.

==================================================
PART 1 — FIX SNAGGING MODULE
==================================================

Current issue:
Buttons and actions inside the Snagging module are not working.

Your task:
Audit and fully connect the Snagging workflow.

Requirements:

1. Verify backend routes/controllers exist
2. Verify frontend API calls exist
3. Verify form submissions work
4. Verify records save correctly
5. Verify project relationships
6. Verify activity logging
7. Verify table refresh after actions

Snagging records must belong to:
- organization_id
- project_id
- created_by

Suggested fields:
- snag_number
- title
- description
- location
- category
- priority
- assigned_to
- status
- due_date
- closed_at
- notes

Statuses:
- open
- in_progress
- ready_for_review
- closed

Workflow:
Defect found
→ snag item created
→ assigned
→ work completed
→ reviewed
→ closed

Frontend requirements:
- working create snag modal
- working edit/update
- status updates
- filters/search
- project table display
- success/error handling
- React Query invalidation

After successful actions:
- refresh snagging table
- update project stats
- create project activity log

==================================================
PART 2 — FIX QA REPORTS MODULE
==================================================

Current issue:
QA Reports buttons/forms are not functioning.

Your task:
Audit and connect the QA Reports module.

QA Reports should function as:
quality inspection and compliance records.

Requirements:

QA Report fields:
- report_number
- inspection_type
- area/location
- inspected_by
- inspection_date
- status
- result
- observations
- corrective_action
- follow_up_required

Statuses:
- draft
- open
- failed
- passed
- closed

Workflow:
Inspection performed
→ QA report created
→ issue recorded
→ corrective action assigned
→ follow-up inspection
→ closed

Verify:
- backend validation
- database schema
- API payloads
- frontend form submission
- table rendering
- project relationship
- organization scoping

Frontend requirements:
- create QA report modal
- edit/update flow
- status badges
- table rendering
- filters/search
- loading states
- proper toast handling

After successful actions:
- refresh QA reports table
- update project stats
- create activity log entry

==================================================
PART 3 — BUILD CLOSEOUT MODULE
==================================================

Current issue:
Closeout page is currently static only.

Your task:
Convert the Closeout page into a functional operational module.

Closeout should represent:
project handover and completion documentation.

Requirements:

Create functional closeout workflow including:

1. Closeout checklist
2. Required document tracking
3. Completion progress
4. Outstanding items
5. Handover records

Suggested closeout sections:
- Warranties
- O&M Manuals
- As-Built Drawings
- Certificates
- QA Completion
- Final Snagging
- Handover Documents

Suggested database structure:
closeouts
closeout_items

Closeout fields:
- organization_id
- project_id
- created_by
- title
- category
- status
- due_date
- completed_at
- notes

Statuses:
- pending
- in_progress
- completed
- approved

Frontend requirements:
- closeout checklist UI
- progress indicators
- completion status
- document attachment support
- item completion toggles

Workflow:
Project nearing completion
→ closeout checklist generated
→ documents uploaded/completed
→ outstanding items resolved
→ project ready for handover

After successful actions:
- update closeout progress
- create project activity entries
- update project completion stats

==================================================
PART 4 — ADMIN PROJECT NAVIGATION FIX
==================================================

Current issue:
When Admin users press the Back button inside project view,
it redirects to the Company list instead of the Project list.

Required fix:
When inside project view as Admin:
Back button should redirect to:

/admin/projects

NOT:
/admin/companies

Verify:
- route handling
- breadcrumbs
- navigation state
- shared project layouts
- admin project context handling

==================================================
PART 5 — DISPLAY COMPANY NAME IN PROJECT VIEW
==================================================

Current issue:
When Admin users are viewing a project,
the company/organization name is not clearly displayed.

Required fix:
Display the company/organization name inside project view header.

Example:

Project: Eagle Tower Fit-Out
Company: Eagle Construction Ltd

This should appear consistently in:
- project overview
- project workspace header
- breadcrumbs/navigation

Requirements:
- fetch related company data
- display dynamically
- maintain role-based project access
- ensure proper admin context awareness

==================================================
PART 6 — SYSTEM STABILITY CHECK
==================================================

Verify all new/fixed modules:
- save correctly
- belong to correct project
- belong to correct organization
- refresh correctly
- display correctly
- update project stats
- create project activities

Verify:
- React Query invalidation
- backend validation
- mass assignment
- API resource formatting
- route consistency
- middleware/policies
- loading/error states

==================================================
PART 7 — PROJECT ACTIVITY INTEGRATION
==================================================

Every successful action must create activity entries.

Examples:
- “Snag item #5 created”
- “QA Report #3 passed”
- “Closeout item completed”
- “Project closeout updated”

Ensure all new modules integrate with:
- project activity timeline
- project stats
- documents system

==================================================
FINAL OBJECTIVE
==================================================

After implementation:

Admin should be able to:
- open project
- create snagging items
- create QA reports
- manage closeout checklist
- track completion progress
- view correct company context
- navigate properly back to project list

The platform should feel like:
connected operational construction software,
not isolated pages.

Focus on:
- debugging
- operational connectivity
- backend/frontend stability
- workflow consistency
- project-level integration

Do NOT overengineer.
Do NOT redesign UI.
Do NOT introduce unnecessary abstractions.

Start with:
1. Snagging module fixes
2. QA Reports fixes
3. Closeout functional workflow
4. Admin navigation fixes
5. Company name display in project header