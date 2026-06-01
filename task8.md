You are continuing development on my existing SureSign platform.

Project Context:
SureSign is a Laravel 11 + Next.js 14 construction operations, contract administration, and adjudication platform.

The platform already has:
- authentication
- role-based access
- admin workspace
- tenant/client workspace
- company management
- project workspaces
- project modules
- contracts
- commercial/payment applications
- variations
- notices
- RFIs
- meetings
- QA reports
- snagging
- closeout
- documents
- adjudication
- project activity
- document uploads
- generated PDF support

Important:
Do NOT integrate Claude/OpenAI API yet.
Do NOT add AI execution.
Do NOT create AI billing/API usage.
Do NOT call any external AI service.

The goal is to build a Prompt Library system that helps users copy ready-made prompts and use them manually in Claude, ChatGPT, or another AI tool.

This is a low-cost AI-assist feature without API integration.

==================================================
MAIN OBJECTIVE
==================================================

Build a Prompt Library module in SureSign.

The Prompt Library should allow Admin/Super Admin users to view categorized operational prompts and copy them to clipboard.

Prompts should support:
- categories
- search
- copy-to-clipboard
- favorites
- project-aware prompt generation
- reusable variables/placeholders
- admin-managed prompt templates

This module should prepare the platform for future AI integration, but for now it should only copy prompts.

==================================================
WHERE TO PLACE IT
==================================================

Add a new Admin-side page:

/admin/prompts

Sidebar label:
Prompt Library

This page is mainly for Admin and Super Admin.

Optional future project-level integration:
Inside project workspace, add a small button or section called:

Project Prompts

This should allow users to generate prompts with project context inserted.

Do NOT place this as full AI module yet.

==================================================
CORE CONCEPT
==================================================

This feature is NOT AI execution.

It is a prompt operations workspace.

User flow:

Admin opens Prompt Library
→ selects category
→ finds useful prompt
→ clicks Copy Prompt
→ prompt is copied to clipboard
→ user pastes it into Claude/ChatGPT manually

Project-level flow:

User opens a project
→ clicks Project Prompts
→ selects prompt
→ system inserts project details into placeholders
→ user copies completed prompt
→ user pastes into Claude/ChatGPT manually

==================================================
DATABASE STRUCTURE
==================================================

Create table: prompt_categories

Fields:
- id
- name
- slug
- description nullable
- icon nullable
- sort_order integer default 0
- is_active boolean default true
- created_at
- updated_at

Create table: prompt_templates

Fields:
- id
- prompt_category_id nullable
- title
- slug
- description nullable
- prompt_text longText
- module nullable
- use_case nullable
- variables json nullable
- is_global boolean default true
- is_active boolean default true
- is_featured boolean default false
- created_by nullable
- copied_count integer default 0
- sort_order integer default 0
- created_at
- updated_at
- deleted_at nullable

Create table: prompt_favorites

Fields:
- id
- user_id
- prompt_template_id
- created_at
- updated_at

Unique:
- user_id + prompt_template_id

Optional table: prompt_copy_logs

Fields:
- id
- user_id
- prompt_template_id
- project_id nullable
- organization_id nullable
- copied_prompt_snapshot longText nullable
- created_at

This allows tracking which prompts are used most.

==================================================
MODEL RELATIONSHIPS
==================================================

PromptCategory:
- hasMany PromptTemplate

PromptTemplate:
- belongsTo PromptCategory
- belongsTo User as creator
- hasMany PromptFavorite
- hasMany PromptCopyLog

PromptFavorite:
- belongsTo User
- belongsTo PromptTemplate

PromptCopyLog:
- belongsTo User
- belongsTo PromptTemplate
- belongsTo Project nullable
- belongsTo Organization nullable

==================================================
BACKEND ROUTES
==================================================

Create API routes:

GET /api/admin/prompts/categories
POST /api/admin/prompts/categories
PUT /api/admin/prompts/categories/{category}
DELETE /api/admin/prompts/categories/{category}

GET /api/admin/prompts/templates
POST /api/admin/prompts/templates
GET /api/admin/prompts/templates/{template}
PUT /api/admin/prompts/templates/{template}
DELETE /api/admin/prompts/templates/{template}

POST /api/admin/prompts/templates/{template}/copy
POST /api/admin/prompts/templates/{template}/favorite
DELETE /api/admin/prompts/templates/{template}/favorite

Project context route:
POST /api/projects/{project}/prompts/{template}/render

This route should:
- load project
- load company/organization
- load template
- replace placeholders with project/company data
- return rendered_prompt

Do NOT call any AI API.

==================================================
PERMISSIONS
==================================================

Super Admin:
- can create/edit/delete prompt categories
- can create/edit/delete prompt templates
- can view all prompts
- can copy all prompts

Admin:
- can view prompts
- can copy prompts
- can favorite prompts
- optionally create internal org prompts if supported later

Client:
- read-only or no access depending on current permission model
- default: no admin prompt library access

Frontend:
Only show /admin/prompts to Admin and Super Admin.

==================================================
PROMPT TEMPLATE STRUCTURE
==================================================

Each prompt should contain:

- title
- description
- category
- module
- use case
- prompt text
- variables/placeholders
- copy button

Example placeholders:
- {{project_name}}
- {{project_code}}
- {{company_name}}
- {{client_name}}
- {{contract_value}}
- {{contract_type}}
- {{current_date}}
- {{document_title}}
- {{rfi_subject}}
- {{variation_title}}
- {{adjudication_case_title}}
- {{claim_amount}}

When rendering a project prompt:
Replace placeholders with available project data.

If a placeholder does not have data:
Leave it visible like:
[INSERT DETAILS]

Example:
{{contract_value}} becomes [INSERT CONTRACT VALUE] if missing.

==================================================
FRONTEND PAGE — ADMIN PROMPT LIBRARY
==================================================

Create page:

/admin/prompts

Layout:

Top section:
- Page title: Prompt Library
- Subtitle: Ready-made prompts for construction administration, contract review, adjudication, and document drafting.
- Search bar
- Category filter
- Module filter
- Featured toggle

Left side:
- Category list

Categories:
- Contracts
- Commercial
- Payment Applications
- Variations
- Notices
- RFIs
- Meetings
- Site Reports
- QA Reports
- Snagging
- Closeout
- Adjudication
- Documents
- General Admin

Main area:
Prompt cards.

Each prompt card should show:
- title
- description
- category badge
- module badge
- use case badge
- prompt preview
- Copy Prompt button
- Favorite button
- copied count if available

Buttons:
- Copy Prompt
- View Full Prompt
- Favorite

On copy:
- copy prompt_text to clipboard
- show success toast: “Prompt copied to clipboard”
- call backend copy log endpoint
- increment copied_count

==================================================
FRONTEND — PROMPT DETAIL MODAL
==================================================

When user clicks View Full Prompt:

Open modal showing:
- title
- description
- full prompt text
- variables used
- copy button
- favorite button

Do not execute AI.

==================================================
FRONTEND — CREATE/EDIT PROMPT
==================================================

Super Admin should be able to create/edit prompt templates.

Create Prompt Modal fields:
- Title
- Category
- Module
- Use Case
- Description
- Prompt Text
- Variables
- Featured toggle
- Active toggle

Prompt Text should use textarea or code-style editor.

Example:
“Summarize the following construction contract and identify key risks...”

==================================================
PROJECT-LEVEL PROMPT INTEGRATION
==================================================

Add optional project-level Prompt button.

Location options:
1. Project Overview page
2. Project Documents page
3. Project Adjudication page
4. Project sidebar small action

Recommended:
Add a button on Project Overview:

“Project Prompts”

When clicked:
Open modal with prompt templates filtered by project context.

Inside Project Prompt modal:
- choose category
- choose prompt
- preview rendered prompt
- copy rendered prompt

The rendered prompt should automatically include:

Project Context:
- Project name
- Project code
- Company/organization name
- Client name if available
- Contract value if available
- Contract type if available
- Start/end dates if available
- Current date

Example rendered prompt:

Project: {{project_name}}
Company: {{company_name}}
Contract Type: {{contract_type}}
Contract Value: {{contract_value}}

Then the prompt body follows.

==================================================
PROJECT CONTEXT RENDERING
==================================================

Implement a backend service:

PromptRenderService

Methods:
- render(PromptTemplate $template, ?Project $project = null, ?array $extraData = [])
- extractVariables(string $promptText)
- replacePlaceholders(string $promptText, array $context)

Context should include:
- project_name
- project_code
- company_name
- organization_name
- client_name
- contract_value
- contract_type
- currency
- start_date
- end_date
- current_date
- user_name

Optional later:
- contract_reference
- payment_application_reference
- variation_reference
- adjudication_case_number

==================================================
COPY TO CLIPBOARD BEHAVIOR
==================================================

Frontend:
Use navigator.clipboard.writeText(prompt)

On success:
- toast.success("Prompt copied to clipboard")

On failure:
- fallback to selecting text and instructing user to copy manually

Backend:
POST /api/admin/prompts/templates/{template}/copy

Should:
- increment copied_count
- create prompt_copy_logs record
- save user_id
- save project_id if provided
- save organization_id if available

==================================================
PROMPT CATEGORIES AND EXAMPLES
==================================================

Seed the system with many useful prompts.

Create seeder:
PromptCategorySeeder
PromptTemplateSeeder

Seed categories and prompts below.

==================================================
CATEGORY: CONTRACTS
==================================================

Prompt 1: Summarize Construction Contract

Prompt text:
You are a construction contract administrator. Summarize the following construction contract in plain English.

Focus on:
- contract parties
- scope of works
- contract value
- payment terms
- retention terms
- variation procedure
- notice requirements
- extension of time procedure
- delay damages
- termination rights
- dispute resolution procedure
- key obligations
- commercial risks
- unusual clauses

Format the output as:
1. Executive Summary
2. Key Commercial Terms
3. Key Dates
4. Notice Requirements
5. Payment Procedure
6. Variations Procedure
7. Risk Items
8. Recommended Actions

Contract text:
[PASTE CONTRACT TEXT HERE]

Prompt 2: Extract Contract Notice Requirements

Prompt text:
Review the following construction contract and extract all notice requirements.

For each notice requirement, identify:
- triggering event
- required notice period
- who must issue the notice
- who receives the notice
- required method of service
- consequences of late notice
- relevant clause reference

Return the result in a table.

Contract text:
[PASTE CONTRACT TEXT HERE]

Prompt 3: Contract Risk Review

Prompt text:
Act as a construction contract reviewer. Identify key risks in this contract from the contractor’s perspective.

Focus on:
- payment risk
- delay risk
- variation risk
- notice risk
- termination risk
- design liability
- liquidated damages
- dispute resolution
- ambiguous wording
- missing protections

For each risk, provide:
- clause/reference
- why it matters
- potential impact
- suggested action

Contract text:
[PASTE CONTRACT TEXT HERE]

==================================================
CATEGORY: COMMERCIAL / PAYMENT APPLICATIONS
==================================================

Prompt 4: Review Payment Application

Prompt text:
Review the following payment application and summarize it for a construction project manager.

Identify:
- application number
- application date
- gross valuation
- retention
- previous payments
- amount due
- supporting documents
- missing information
- risks or inconsistencies
- recommended next actions

Payment application details:
[PASTE PAYMENT APPLICATION DETAILS HERE]

Prompt 5: Draft Payment Application Cover Letter

Prompt text:
Draft a professional payment application cover letter for a construction project.

Use the following details:
Project Name: {{project_name}}
Company: {{company_name}}
Application Number: [INSERT APPLICATION NUMBER]
Gross Valuation: [INSERT AMOUNT]
Retention: [INSERT AMOUNT]
Amount Due: [INSERT AMOUNT]
Due Date: [INSERT DUE DATE]

The letter should:
- be formal and concise
- reference the contract/payment terms
- request assessment and payment
- list attached supporting documents
- include a professional closing

Prompt 6: Analyze Pay Less Notice

Prompt text:
Analyze the following Pay Less Notice.

Identify:
- payment application it relates to
- amount claimed
- amount withheld
- stated reasons for withholding
- whether the reasons are clear
- possible weaknesses
- documents needed to respond
- recommended next steps

Pay Less Notice:
[PASTE PAY LESS NOTICE TEXT HERE]

==================================================
CATEGORY: VARIATIONS
==================================================

Prompt 7: Draft Variation Claim

Prompt text:
Draft a construction variation claim based on the following information.

Project: {{project_name}}
Contract: [INSERT CONTRACT REFERENCE]
Variation title: [INSERT TITLE]
Instruction/source: [INSERT SOURCE]
Description of change: [INSERT DETAILS]
Cost impact: [INSERT AMOUNT]
Programme impact: [INSERT DAYS]
Supporting evidence: [INSERT EVIDENCE]

The claim should include:
- background
- instruction or basis of entitlement
- description of varied work
- cost breakdown
- programme impact
- supporting evidence
- request for approval

Prompt 8: Review Variation Rejection

Prompt text:
Review the following rejection of a variation claim.

Identify:
- reasons for rejection
- strengths of the rejection
- weaknesses of the rejection
- missing evidence
- possible counterarguments
- recommended next response

Variation claim:
[PASTE VARIATION CLAIM HERE]

Rejection:
[PASTE REJECTION HERE]

Prompt 9: Variation Evidence Checklist

Prompt text:
Create an evidence checklist for the following construction variation.

Variation details:
[PASTE DETAILS HERE]

Include evidence needed from:
- contract clauses
- site instructions
- drawings/specifications
- RFIs
- emails
- site diaries
- photos
- labour/material records
- cost breakdowns
- programme records

==================================================
CATEGORY: RFIs
==================================================

Prompt 10: Draft RFI

Prompt text:
Draft a clear professional Request for Information for a construction project.

Project: {{project_name}}
Subject: [INSERT SUBJECT]
Issue: [DESCRIBE ISSUE]
Required information: [WHAT NEEDS TO BE ANSWERED]
Impact if unanswered: [COST/TIME/QUALITY IMPACT]
Response required by: [INSERT DATE]

The RFI should be concise, professional, and specific.

Prompt 11: Summarize RFI Response

Prompt text:
Summarize the following RFI response.

Identify:
- question asked
- answer provided
- whether the response is complete
- whether there is cost impact
- whether there is time impact
- whether a variation should be raised
- recommended next action

RFI and response:
[PASTE RFI DETAILS HERE]

Prompt 12: Determine if RFI Should Become Variation

Prompt text:
Review the following RFI and response and determine whether it should trigger a variation.

Assess:
- whether the response changes scope
- whether additional work is required
- whether there is cost impact
- whether there is programme impact
- what evidence is needed
- recommended next step

RFI:
[PASTE RFI HERE]

Response:
[PASTE RESPONSE HERE]

==================================================
CATEGORY: NOTICES
==================================================

Prompt 13: Draft Delay Notice

Prompt text:
Draft a formal construction delay notice.

Project: {{project_name}}
Contract: [INSERT CONTRACT REFERENCE]
Delay event: [DESCRIBE DELAY]
Date delay started: [INSERT DATE]
Cause of delay: [INSERT CAUSE]
Expected impact: [TIME/COST IMPACT]
Relevant clause: [INSERT CLAUSE IF KNOWN]

The notice should:
- be formal
- preserve contractual rights
- identify the delay event
- explain potential impact
- reserve rights to claim EOT/loss and expense
- request confirmation/instructions

Prompt 14: Draft EOT Notice

Prompt text:
Draft a formal Extension of Time notice for a construction project.

Include:
- project details
- contract reference
- delaying event
- date of occurrence
- cause of delay
- effect on the programme
- preliminary number of days claimed
- supporting evidence
- reservation of rights

Project: {{project_name}}
Details:
[PASTE DETAILS HERE]

Prompt 15: Review Notice Compliance

Prompt text:
Review the following construction notice for compliance.

Assess:
- whether it clearly identifies the event
- whether it was issued within time
- whether it was sent to the correct party
- whether it includes enough detail
- whether it reserves rights properly
- any weaknesses
- recommended improvements

Notice text:
[PASTE NOTICE HERE]

Contract clause if available:
[PASTE CLAUSE HERE]

==================================================
CATEGORY: MEETINGS
==================================================

Prompt 16: Generate Meeting Minutes

Prompt text:
Convert the following rough construction meeting notes into professional meeting minutes.

Include:
- project name
- meeting type
- date
- attendees
- apologies
- agenda items
- discussion summary
- decisions made
- action items
- responsible person
- due dates
- next meeting

Project: {{project_name}}
Meeting notes:
[PASTE NOTES HERE]

Prompt 17: Extract Action Items from Meeting

Prompt text:
Extract all action items from the following construction meeting notes.

Return a table with:
- action item
- responsible person
- due date
- priority
- related issue
- status

Meeting notes:
[PASTE NOTES HERE]

==================================================
CATEGORY: SITE REPORTS
==================================================

Prompt 18: Summarize Site Diary

Prompt text:
Summarize the following site diary entry into a professional daily site report.

Include:
- weather
- labour on site
- works carried out
- materials delivered
- plant/equipment
- delays/disruptions
- health and safety observations
- quality issues
- instructions received
- photos/evidence referenced
- recommended follow-up actions

Site diary:
[PASTE SITE DIARY HERE]

Prompt 19: Identify Delay Events from Site Reports

Prompt text:
Review the following site reports and identify potential delay events.

For each delay event, extract:
- date
- cause
- affected works
- responsible party if known
- evidence available
- potential EOT relevance
- recommended action

Site reports:
[PASTE SITE REPORTS HERE]

==================================================
CATEGORY: QA REPORTS
==================================================

Prompt 20: Summarize QA Inspection

Prompt text:
Summarize the following QA inspection report.

Identify:
- inspection type
- area inspected
- result
- non-conformances
- corrective actions
- follow-up required
- evidence/photos needed
- closeout impact

QA report:
[PASTE QA REPORT HERE]

Prompt 21: Draft Corrective Action Notice

Prompt text:
Draft a corrective action notice based on this QA issue.

Project: {{project_name}}
Issue: [DESCRIBE ISSUE]
Location: [INSERT LOCATION]
Required correction: [INSERT ACTION]
Deadline: [INSERT DATE]
Responsible party: [INSERT PARTY]

The notice should be professional, clear, and action-focused.

==================================================
CATEGORY: SNAGGING
==================================================

Prompt 22: Summarize Snag List

Prompt text:
Summarize the following snag list for project closeout.

Group items by:
- location
- trade
- priority
- status
- overdue items
- items requiring client review

Snag list:
[PASTE SNAG LIST HERE]

Prompt 23: Draft Snagging Completion Notice

Prompt text:
Draft a professional notice confirming completion of snagging items.

Project: {{project_name}}
Completed snag items:
[PASTE COMPLETED ITEMS HERE]

Include:
- summary of completed works
- request for inspection/confirmation
- remaining open items if any
- proposed next steps

==================================================
CATEGORY: CLOSEOUT
==================================================

Prompt 24: Create Closeout Checklist

Prompt text:
Create a construction project closeout checklist.

Project: {{project_name}}
Project type: {{contract_type}}

Include sections for:
- as-built drawings
- O&M manuals
- warranties
- test certificates
- commissioning certificates
- QA records
- snagging completion
- training records
- final account
- handover documents
- client sign-off

Prompt 25: Summarize Closeout Status

Prompt text:
Summarize the current closeout status for a construction project.

Include:
- completed closeout items
- outstanding documents
- overdue items
- risks to handover
- recommended next actions

Closeout data:
[PASTE CLOSEOUT DETAILS HERE]

==================================================
CATEGORY: ADJUDICATION
==================================================

Prompt 26: Summarize Dispute

Prompt text:
Summarize the following construction dispute.

Project: {{project_name}}
Dispute type: [INSERT DISPUTE TYPE]
Claim amount: [INSERT CLAIM AMOUNT]

Identify:
- background
- parties involved
- key events
- contractual basis
- amount claimed
- evidence available
- weaknesses
- missing information
- recommended next steps

Dispute details:
[PASTE DISPUTE DETAILS HERE]

Prompt 27: Draft Notice of Dispute

Prompt text:
Draft a formal Notice of Dispute for a construction contract.

Project: {{project_name}}
Contract: [INSERT CONTRACT REFERENCE]
Claimant: [INSERT CLAIMANT]
Respondent: [INSERT RESPONDENT]
Dispute type: [INSERT TYPE]
Amount claimed: [INSERT AMOUNT]
Background: [PASTE BACKGROUND]
Contractual basis: [PASTE CLAUSES IF AVAILABLE]
Relief sought: [INSERT RELIEF]

The notice should:
- identify the dispute clearly
- explain the factual background
- state the contractual/legal basis
- identify the amount or remedy sought
- reserve rights
- be professional and formal

Prompt 28: Draft Notice of Adjudication

Prompt text:
Draft a formal Notice of Adjudication.

Include:
- parties
- contract details
- dispute summary
- remedy sought
- amount claimed
- relevant clauses
- key chronology
- request for adjudicator appointment if applicable

Project: {{project_name}}
Details:
[PASTE CASE DETAILS HERE]

Prompt 29: Analyze Adjudication Response

Prompt text:
Analyze the following adjudication response.

Identify:
- key arguments raised by the responding party
- factual disputes
- contractual disputes
- legal weaknesses
- evidential gaps
- contradictions
- possible counterarguments
- documents needed for reply
- risk assessment

Response:
[PASTE RESPONSE HERE]

Prompt 30: Draft Further Submission

Prompt text:
Draft a further submission/reply in an adjudication.

Use the following:
Original claim:
[PASTE CLAIM SUMMARY]

Response:
[PASTE RESPONSE]

Counter-evidence:
[PASTE EVIDENCE]

The submission should:
- respond directly to key points
- maintain professional tone
- reference evidence
- identify contradictions
- strengthen the claimant/respondent position
- avoid unsupported assertions

Prompt 31: Summarize Adjudicator Decision

Prompt text:
Summarize the following adjudicator’s decision.

Identify:
- outcome
- amount awarded
- reasons for decision
- key findings
- rejected arguments
- payment deadline
- enforcement risk
- next steps

Decision:
[PASTE DECISION HERE]

Prompt 32: Draft Enforcement Letter

Prompt text:
Draft a formal enforcement/payment demand letter following an adjudicator’s decision.

Include:
- decision date
- amount awarded
- payment due date
- failure to pay consequences
- reservation of rights
- demand for immediate payment

Decision summary:
[PASTE DECISION SUMMARY HERE]

==================================================
CATEGORY: DOCUMENTS
==================================================

Prompt 33: Summarize Uploaded Document

Prompt text:
Summarize the following construction document.

Identify:
- document type
- purpose
- key obligations
- key dates
- commercial implications
- risks
- required actions

Document text:
[PASTE DOCUMENT TEXT HERE]

Prompt 34: Create Document Index

Prompt text:
Create a document index from the following list of project documents.

For each document, identify:
- document name
- type
- date
- author/source
- relevance
- related project issue
- whether it may be useful as evidence

Document list:
[PASTE DOCUMENT LIST HERE]

==================================================
CATEGORY: GENERAL ADMIN
==================================================

Prompt 35: Write Professional Client Update

Prompt text:
Write a professional client update email based on the following project information.

Project: {{project_name}}
Company: {{company_name}}

Include:
- progress summary
- completed actions
- current issues
- upcoming deadlines
- requested client actions
- professional closing

Project notes:
[PASTE NOTES HERE]

Prompt 36: Rewrite Text Professionally

Prompt text:
Rewrite the following text in a professional construction project management tone.

Keep it clear, concise, and formal.

Text:
[PASTE TEXT HERE]

Prompt 37: Create Action Plan

Prompt text:
Create a clear action plan from the following construction project issue.

Include:
- issue summary
- required actions
- responsible party
- priority
- deadline
- risks if not completed

Issue:
[PASTE ISSUE HERE]

==================================================
UI POLISH REQUIREMENTS
==================================================

Make the Prompt Library look professional.

Prompt cards should not look like plain text boxes.

Use:
- category badges
- module badges
- clean spacing
- copy button
- favorite icon
- preview text
- search and filters

Use existing design tokens:
- var(--bg-base)
- var(--bg-surface)
- var(--bg-elevated)
- var(--border)
- var(--text-primary)
- var(--text-secondary)
- var(--text-muted)
- var(--gold)

==================================================
IMPORTANT IMPLEMENTATION NOTES
==================================================

1. Do not connect to AI APIs.
2. Do not require API keys.
3. Do not create billing logic.
4. Do not build chat UI yet.
5. Do not execute prompts.
6. Only copy prompts to clipboard.
7. Keep this feature cheap and simple.
8. Design it so future AI integration can reuse the same prompt templates.
9. Make prompts editable by Super Admin.
10. Use professional labels and categories.

==================================================
FINAL EXPECTED RESULT
==================================================

After implementation:

Admin can:
- open /admin/prompts
- browse prompt categories
- search prompts
- view prompt details
- copy prompts to clipboard
- favorite prompts
- create/edit prompts if Super Admin

Project users/admin can:
- open project prompt modal
- choose a relevant prompt
- auto-insert project context
- copy the rendered prompt
- paste it manually into Claude/ChatGPT

The system should provide immediate AI-assisted value without paying API costs.

Future AI integration should be easy because prompts are already structured, categorized, and stored in the database.