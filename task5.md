You are auditing and stabilizing my existing SureSign platform.

Project Context:
SureSign is a multi-tenant Construction Operations & Contract Administration Platform built with:

- Laravel 11 backend
- Next.js 15 frontend
- PostgreSQL/MySQL database
- TanStack React Query
- Tailwind UI

The system already has:
- authentication
- roles
- admin side
- tenant/client side
- project workspaces
- branding system
- templates system
- contracts module
- commercial module
- RFIs module
- variations module
- meetings module
- notices module
- documents module
- activity system structure

Current architecture:
Super Admin
→ Companies / Organisations
→ Projects
→ Operational Modules
→ Documents / PDFs / Records

IMPORTANT:
Do NOT redesign the platform.
Do NOT rebuild authentication.
Do NOT rebuild the frontend.
Do NOT create a new architecture.

Your task is ONLY:
1. audit the current implementation
2. verify module structure
3. fix broken forms and workflows
4. stabilize operational connections
5. ensure backend/frontend consistency

==================================================
PART 1 — FULL SYSTEM AUDIT
==================================================

Audit the entire system architecture and verify:

1. Database structure consistency
2. Model relationships
3. API route consistency
4. Frontend/backend integration
5. Form submission flows
6. Validation consistency
7. Required fields consistency
8. Project and organization scoping
9. Role permission enforcement
10. React Query cache invalidation
11. Activity log integration
12. Documents integration
13. Error handling consistency

Verify every module correctly belongs to:
- organization_id
- project_id
- created_by/user_id

Verify all modules:
- save correctly
- fetch correctly
- update correctly
- display correctly
- filter correctly

==================================================
PART 2 — CHECK MODULE RELATIONSHIPS
==================================================

Verify the following relationships exist and work correctly:

Project
├── Contracts
├── Payment Applications
├── Variations
├── RFIs
├── Meetings
├── Notices
├── QA Reports
├── Site Reports
├── Snagging
├── Documents
└── Activity Logs

Contracts should connect to:
- payment applications
- variations
- notices

Documents should support:
- uploaded files
- generated PDFs
- polymorphic links

Verify all foreign keys and relationships.

==================================================
PART 3 — FIX BROKEN MODULE FORMS
==================================================

The following modules currently fail when submitting forms:

1. RFIs
2. Variations
3. Meetings
4. Notices

Contracts and Commercial currently work.

Your task:
Find and fix ALL backend/frontend issues causing failed submissions.

Possible causes to inspect:
- missing required database fields
- validation mismatches
- enum mismatches
- incorrect API payloads
- missing organization_id
- missing project_id
- missing created_by
- wrong field names
- nullable conflicts
- mass assignment issues
- route/controller mismatches
- migration inconsistencies
- date formatting issues
- frontend form schema mismatch
- React Query mutation problems
- incorrect status defaults
- missing relationships
- policy/middleware issues

==================================================
PART 4 — REQUIRED FORM BEHAVIOR
==================================================

After fixing, all modules must:

1. Successfully create records
2. Store records under correct project
3. Store records under correct organization
4. Save current user as creator
5. Refresh tables after submission
6. Show success/error toasts
7. Update project stats
8. Create project activity log entries
9. Display correctly in tables
10. Support future document/PDF generation

==================================================
PART 5 — EXPECTED WORKFLOW PER MODULE
==================================================

RFI Workflow:
- User creates RFI
- Status defaults to open
- RFI appears in table
- Open RFI count updates
- Activity log created

Variation Workflow:
- User selects contract
- Creates variation
- Variation links to contract
- Pending variation stats update
- Activity log created

Meeting Workflow:
- User creates meeting
- Meeting appears in meetings table
- Activity log created
- Ready for future AI summary generation

Notice Workflow:
- User creates notice/EOT request
- Notice saved correctly
- Appears in notices table
- Activity log created
- Ready for future PDF generation

==================================================
PART 6 — FRONTEND STABILITY CHECK
==================================================

Verify:
- all forms use correct payload structure
- all mutations handle errors properly
- loading states exist
- modals close correctly after success
- tables refresh after creation
- search/filter still works
- query invalidation is correct

Recommended query invalidation keys:
- ['project', projectId]
- ['project-rfis', projectId]
- ['project-variations', projectId]
- ['project-meetings', projectId]
- ['project-notices', projectId]
- ['project-stats', projectId]
- ['project-activities', projectId]

==================================================
PART 7 — ACTIVITY LOG INTEGRATION
==================================================

Every successful module action should create activity entries.

Examples:
- “RFI #3 raised”
- “Variation #5 created”
- “Meeting minutes issued”
- “EOT Notice submitted”

Verify activity service works correctly.

==================================================
PART 8 — FINAL OBJECTIVE
==================================================

The system should behave like real operational software.

After fixes:
Admin should be able to:

1. Open company
2. Open project
3. Create contract
4. Create payment application
5. Create RFI
6. Create variation
7. Create meeting
8. Create notice
9. View activity timeline
10. View updated stats
11. See records inside correct module tables

Do NOT redesign UI.
Do NOT overengineer.
Do NOT create unnecessary abstractions.

Focus only on:
- debugging
- fixing
- stabilizing
- connecting workflows
- ensuring operational consistency

Start by:
1. tracing failed form submissions
2. identifying backend validation/database issues
3. fixing payload mismatches
4. testing successful create flow for each broken module

Then summarize:
- root causes found
- fixes applied
- remaining architectural risks
- recommended next priorities