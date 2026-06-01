<?php

namespace Database\Seeders;

use App\Models\PromptCategory;
use App\Models\PromptTemplate;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class PromptTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $templates = $this->getTemplates();

        foreach ($templates as $data) {
            $category = PromptCategory::where('slug', Str::slug($data['category']))->first();

            PromptTemplate::updateOrCreate(
                ['slug' => $data['slug']],
                [
                    'prompt_category_id' => $category?->id,
                    'title'              => $data['title'],
                    'slug'               => $data['slug'],
                    'description'        => $data['description'] ?? null,
                    'prompt_text'        => $data['prompt_text'],
                    'module'             => $data['module'] ?? $data['category'],
                    'use_case'           => $data['use_case'] ?? null,
                    'variables'          => $data['variables'] ?? null,
                    'is_global'          => true,
                    'is_active'          => true,
                    'is_featured'        => $data['is_featured'] ?? false,
                    'sort_order'         => $data['sort_order'] ?? 0,
                ]
            );
        }
    }

    private function getTemplates(): array
    {
        return [
            // ----------------------------------------------------------------
            // CONTRACTS
            // ----------------------------------------------------------------
            [
                'category'    => 'Contracts',
                'title'       => 'Summarize Construction Contract',
                'slug'        => 'summarize-construction-contract',
                'description' => 'Summarize a construction contract in plain English, highlighting key commercial terms and risks.',
                'use_case'    => 'Contract Review',
                'is_featured' => true,
                'sort_order'  => 1,
                'variables'   => [],
                'prompt_text' => <<<PROMPT
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
PROMPT,
            ],
            [
                'category'    => 'Contracts',
                'title'       => 'Extract Contract Notice Requirements',
                'slug'        => 'extract-contract-notice-requirements',
                'description' => 'Extract all notice requirements from a construction contract in table format.',
                'use_case'    => 'Notice Compliance',
                'sort_order'  => 2,
                'variables'   => [],
                'prompt_text' => <<<PROMPT
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
PROMPT,
            ],
            [
                'category'    => 'Contracts',
                'title'       => 'Contract Risk Review',
                'slug'        => 'contract-risk-review',
                'description' => 'Identify key risks in a construction contract from the contractor\'s perspective.',
                'use_case'    => 'Risk Management',
                'is_featured' => true,
                'sort_order'  => 3,
                'variables'   => [],
                'prompt_text' => <<<PROMPT
Act as a construction contract reviewer. Identify key risks in this contract from the contractor's perspective.

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
PROMPT,
            ],

            // ----------------------------------------------------------------
            // COMMERCIAL / PAYMENT APPLICATIONS
            // ----------------------------------------------------------------
            [
                'category'    => 'Payment Applications',
                'title'       => 'Review Payment Application',
                'slug'        => 'review-payment-application',
                'description' => 'Summarize a payment application and identify risks or missing information.',
                'use_case'    => 'Commercial Review',
                'sort_order'  => 1,
                'variables'   => [],
                'prompt_text' => <<<PROMPT
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
PROMPT,
            ],
            [
                'category'    => 'Payment Applications',
                'title'       => 'Draft Payment Application Cover Letter',
                'slug'        => 'draft-payment-application-cover-letter',
                'description' => 'Draft a professional payment application cover letter using project details.',
                'use_case'    => 'Document Drafting',
                'sort_order'  => 2,
                'variables'   => ['project_name', 'company_name'],
                'prompt_text' => <<<PROMPT
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
PROMPT,
            ],
            [
                'category'    => 'Payment Applications',
                'title'       => 'Analyze Pay Less Notice',
                'slug'        => 'analyze-pay-less-notice',
                'description' => 'Analyze a Pay Less Notice and identify weaknesses and recommended responses.',
                'use_case'    => 'Commercial Analysis',
                'is_featured' => true,
                'sort_order'  => 3,
                'variables'   => [],
                'prompt_text' => <<<PROMPT
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
PROMPT,
            ],

            // ----------------------------------------------------------------
            // VARIATIONS
            // ----------------------------------------------------------------
            [
                'category'    => 'Variations',
                'title'       => 'Draft Variation Claim',
                'slug'        => 'draft-variation-claim',
                'description' => 'Draft a construction variation claim with cost and programme impact.',
                'use_case'    => 'Claim Drafting',
                'is_featured' => true,
                'sort_order'  => 1,
                'variables'   => ['project_name'],
                'prompt_text' => <<<PROMPT
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
PROMPT,
            ],
            [
                'category'    => 'Variations',
                'title'       => 'Review Variation Rejection',
                'slug'        => 'review-variation-rejection',
                'description' => 'Analyze a variation rejection and identify counterarguments.',
                'use_case'    => 'Dispute Support',
                'sort_order'  => 2,
                'variables'   => [],
                'prompt_text' => <<<PROMPT
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
PROMPT,
            ],
            [
                'category'    => 'Variations',
                'title'       => 'Variation Evidence Checklist',
                'slug'        => 'variation-evidence-checklist',
                'description' => 'Create a comprehensive evidence checklist for a variation claim.',
                'use_case'    => 'Evidence Management',
                'sort_order'  => 3,
                'variables'   => [],
                'prompt_text' => <<<PROMPT
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
PROMPT,
            ],

            // ----------------------------------------------------------------
            // RFIs
            // ----------------------------------------------------------------
            [
                'category'    => 'RFIs',
                'title'       => 'Draft RFI',
                'slug'        => 'draft-rfi',
                'description' => 'Draft a clear professional Request for Information for a construction project.',
                'use_case'    => 'Communication Drafting',
                'sort_order'  => 1,
                'variables'   => ['project_name'],
                'prompt_text' => <<<PROMPT
Draft a clear professional Request for Information for a construction project.

Project: {{project_name}}
Subject: [INSERT SUBJECT]
Issue: [DESCRIBE ISSUE]
Required information: [WHAT NEEDS TO BE ANSWERED]
Impact if unanswered: [COST/TIME/QUALITY IMPACT]
Response required by: [INSERT DATE]

The RFI should be concise, professional, and specific.
PROMPT,
            ],
            [
                'category'    => 'RFIs',
                'title'       => 'Summarize RFI Response',
                'slug'        => 'summarize-rfi-response',
                'description' => 'Summarize an RFI response and identify if a variation should be raised.',
                'use_case'    => 'Review & Analysis',
                'sort_order'  => 2,
                'variables'   => [],
                'prompt_text' => <<<PROMPT
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
PROMPT,
            ],
            [
                'category'    => 'RFIs',
                'title'       => 'Determine if RFI Should Become Variation',
                'slug'        => 'rfi-to-variation-assessment',
                'description' => 'Determine whether an RFI response triggers a variation entitlement.',
                'use_case'    => 'Commercial Analysis',
                'sort_order'  => 3,
                'variables'   => [],
                'prompt_text' => <<<PROMPT
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
PROMPT,
            ],

            // ----------------------------------------------------------------
            // NOTICES
            // ----------------------------------------------------------------
            [
                'category'    => 'Notices',
                'title'       => 'Draft Delay Notice',
                'slug'        => 'draft-delay-notice',
                'description' => 'Draft a formal construction delay notice preserving contractual rights.',
                'use_case'    => 'Notice Drafting',
                'is_featured' => true,
                'sort_order'  => 1,
                'variables'   => ['project_name'],
                'prompt_text' => <<<PROMPT
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
PROMPT,
            ],
            [
                'category'    => 'Notices',
                'title'       => 'Draft EOT Notice',
                'slug'        => 'draft-eot-notice',
                'description' => 'Draft a formal Extension of Time notice for a construction project.',
                'use_case'    => 'Notice Drafting',
                'sort_order'  => 2,
                'variables'   => ['project_name'],
                'prompt_text' => <<<PROMPT
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
PROMPT,
            ],
            [
                'category'    => 'Notices',
                'title'       => 'Review Notice Compliance',
                'slug'        => 'review-notice-compliance',
                'description' => 'Check whether a construction notice complies with contractual requirements.',
                'use_case'    => 'Compliance Review',
                'sort_order'  => 3,
                'variables'   => [],
                'prompt_text' => <<<PROMPT
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
PROMPT,
            ],

            // ----------------------------------------------------------------
            // MEETINGS
            // ----------------------------------------------------------------
            [
                'category'    => 'Meetings',
                'title'       => 'Generate Meeting Minutes',
                'slug'        => 'generate-meeting-minutes',
                'description' => 'Convert rough meeting notes into professional construction meeting minutes.',
                'use_case'    => 'Document Drafting',
                'sort_order'  => 1,
                'variables'   => ['project_name'],
                'prompt_text' => <<<PROMPT
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
PROMPT,
            ],
            [
                'category'    => 'Meetings',
                'title'       => 'Extract Action Items from Meeting',
                'slug'        => 'extract-meeting-action-items',
                'description' => 'Extract all action items from construction meeting notes in a structured table.',
                'use_case'    => 'Action Tracking',
                'sort_order'  => 2,
                'variables'   => [],
                'prompt_text' => <<<PROMPT
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
PROMPT,
            ],

            // ----------------------------------------------------------------
            // SITE REPORTS
            // ----------------------------------------------------------------
            [
                'category'    => 'Site Reports',
                'title'       => 'Summarize Site Diary',
                'slug'        => 'summarize-site-diary',
                'description' => 'Convert a site diary entry into a professional daily site report.',
                'use_case'    => 'Report Drafting',
                'sort_order'  => 1,
                'variables'   => [],
                'prompt_text' => <<<PROMPT
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
PROMPT,
            ],
            [
                'category'    => 'Site Reports',
                'title'       => 'Identify Delay Events from Site Reports',
                'slug'        => 'identify-delay-events-site-reports',
                'description' => 'Review site reports to identify potential delay events and EOT relevance.',
                'use_case'    => 'Delay Analysis',
                'sort_order'  => 2,
                'variables'   => [],
                'prompt_text' => <<<PROMPT
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
PROMPT,
            ],

            // ----------------------------------------------------------------
            // QA REPORTS
            // ----------------------------------------------------------------
            [
                'category'    => 'QA Reports',
                'title'       => 'Summarize QA Inspection',
                'slug'        => 'summarize-qa-inspection',
                'description' => 'Summarize a QA inspection report and identify non-conformances.',
                'use_case'    => 'QA Review',
                'sort_order'  => 1,
                'variables'   => [],
                'prompt_text' => <<<PROMPT
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
PROMPT,
            ],
            [
                'category'    => 'QA Reports',
                'title'       => 'Draft Corrective Action Notice',
                'slug'        => 'draft-corrective-action-notice',
                'description' => 'Draft a professional corrective action notice based on a QA issue.',
                'use_case'    => 'Notice Drafting',
                'sort_order'  => 2,
                'variables'   => ['project_name'],
                'prompt_text' => <<<PROMPT
Draft a corrective action notice based on this QA issue.

Project: {{project_name}}
Issue: [DESCRIBE ISSUE]
Location: [INSERT LOCATION]
Required correction: [INSERT ACTION]
Deadline: [INSERT DATE]
Responsible party: [INSERT PARTY]

The notice should be professional, clear, and action-focused.
PROMPT,
            ],

            // ----------------------------------------------------------------
            // SNAGGING
            // ----------------------------------------------------------------
            [
                'category'    => 'Snagging',
                'title'       => 'Summarize Snag List',
                'slug'        => 'summarize-snag-list',
                'description' => 'Summarize a snag list grouping items by location, trade, priority and status.',
                'use_case'    => 'Closeout Support',
                'sort_order'  => 1,
                'variables'   => [],
                'prompt_text' => <<<PROMPT
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
PROMPT,
            ],
            [
                'category'    => 'Snagging',
                'title'       => 'Draft Snagging Completion Notice',
                'slug'        => 'draft-snagging-completion-notice',
                'description' => 'Draft a professional notice confirming completion of snagging items.',
                'use_case'    => 'Notice Drafting',
                'sort_order'  => 2,
                'variables'   => ['project_name'],
                'prompt_text' => <<<PROMPT
Draft a professional notice confirming completion of snagging items.

Project: {{project_name}}
Completed snag items:
[PASTE COMPLETED ITEMS HERE]

Include:
- summary of completed works
- request for inspection/confirmation
- remaining open items if any
- proposed next steps
PROMPT,
            ],

            // ----------------------------------------------------------------
            // CLOSEOUT
            // ----------------------------------------------------------------
            [
                'category'    => 'Closeout',
                'title'       => 'Create Closeout Checklist',
                'slug'        => 'create-closeout-checklist',
                'description' => 'Create a comprehensive project closeout checklist covering all required documents.',
                'use_case'    => 'Closeout Planning',
                'sort_order'  => 1,
                'variables'   => ['project_name', 'contract_type'],
                'prompt_text' => <<<PROMPT
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
PROMPT,
            ],
            [
                'category'    => 'Closeout',
                'title'       => 'Summarize Closeout Status',
                'slug'        => 'summarize-closeout-status',
                'description' => 'Summarize current project closeout status and identify outstanding items.',
                'use_case'    => 'Progress Review',
                'sort_order'  => 2,
                'variables'   => [],
                'prompt_text' => <<<PROMPT
Summarize the current closeout status for a construction project.

Include:
- completed closeout items
- outstanding documents
- overdue items
- risks to handover
- recommended next actions

Closeout data:
[PASTE CLOSEOUT DETAILS HERE]
PROMPT,
            ],

            // ----------------------------------------------------------------
            // ADJUDICATION
            // ----------------------------------------------------------------
            [
                'category'    => 'Adjudication',
                'title'       => 'Summarize Dispute',
                'slug'        => 'summarize-dispute',
                'description' => 'Summarize a construction dispute and identify key evidence and recommended steps.',
                'use_case'    => 'Dispute Analysis',
                'is_featured' => true,
                'sort_order'  => 1,
                'variables'   => ['project_name'],
                'prompt_text' => <<<PROMPT
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
PROMPT,
            ],
            [
                'category'    => 'Adjudication',
                'title'       => 'Draft Notice of Dispute',
                'slug'        => 'draft-notice-of-dispute',
                'description' => 'Draft a formal Notice of Dispute for a construction contract.',
                'use_case'    => 'Notice Drafting',
                'sort_order'  => 2,
                'variables'   => ['project_name'],
                'prompt_text' => <<<PROMPT
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
PROMPT,
            ],
            [
                'category'    => 'Adjudication',
                'title'       => 'Draft Notice of Adjudication',
                'slug'        => 'draft-notice-of-adjudication',
                'description' => 'Draft a formal Notice of Adjudication including parties, dispute summary and remedy.',
                'use_case'    => 'Adjudication Drafting',
                'is_featured' => true,
                'sort_order'  => 3,
                'variables'   => ['project_name'],
                'prompt_text' => <<<PROMPT
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
PROMPT,
            ],
            [
                'category'    => 'Adjudication',
                'title'       => 'Analyze Adjudication Response',
                'slug'        => 'analyze-adjudication-response',
                'description' => 'Analyze an adjudication response and identify weaknesses and counterarguments.',
                'use_case'    => 'Dispute Analysis',
                'sort_order'  => 4,
                'variables'   => [],
                'prompt_text' => <<<PROMPT
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
PROMPT,
            ],
            [
                'category'    => 'Adjudication',
                'title'       => 'Draft Further Submission',
                'slug'        => 'draft-further-submission',
                'description' => 'Draft a further submission or reply in an adjudication proceeding.',
                'use_case'    => 'Adjudication Drafting',
                'sort_order'  => 5,
                'variables'   => [],
                'prompt_text' => <<<PROMPT
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
PROMPT,
            ],
            [
                'category'    => 'Adjudication',
                'title'       => 'Summarize Adjudicator Decision',
                'slug'        => 'summarize-adjudicator-decision',
                'description' => 'Summarize an adjudicator\'s decision and identify enforcement steps.',
                'use_case'    => 'Decision Review',
                'sort_order'  => 6,
                'variables'   => [],
                'prompt_text' => <<<PROMPT
Summarize the following adjudicator's decision.

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
PROMPT,
            ],
            [
                'category'    => 'Adjudication',
                'title'       => 'Draft Enforcement Letter',
                'slug'        => 'draft-enforcement-letter',
                'description' => 'Draft a formal payment demand letter following an adjudicator\'s decision.',
                'use_case'    => 'Enforcement',
                'sort_order'  => 7,
                'variables'   => [],
                'prompt_text' => <<<PROMPT
Draft a formal enforcement/payment demand letter following an adjudicator's decision.

Include:
- decision date
- amount awarded
- payment due date
- failure to pay consequences
- reservation of rights
- demand for immediate payment

Decision summary:
[PASTE DECISION SUMMARY HERE]
PROMPT,
            ],

            // ----------------------------------------------------------------
            // DOCUMENTS
            // ----------------------------------------------------------------
            [
                'category'    => 'Documents',
                'title'       => 'Summarize Uploaded Document',
                'slug'        => 'summarize-uploaded-document',
                'description' => 'Summarize any construction document and identify key obligations and risks.',
                'use_case'    => 'Document Review',
                'sort_order'  => 1,
                'variables'   => [],
                'prompt_text' => <<<PROMPT
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
PROMPT,
            ],
            [
                'category'    => 'Documents',
                'title'       => 'Create Document Index',
                'slug'        => 'create-document-index',
                'description' => 'Create a structured document index from a list of project documents.',
                'use_case'    => 'Document Management',
                'sort_order'  => 2,
                'variables'   => [],
                'prompt_text' => <<<PROMPT
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
PROMPT,
            ],

            // ----------------------------------------------------------------
            // GENERAL ADMIN
            // ----------------------------------------------------------------
            [
                'category'    => 'General Admin',
                'title'       => 'Write Professional Client Update',
                'slug'        => 'write-professional-client-update',
                'description' => 'Write a professional client update email based on current project information.',
                'use_case'    => 'Communication Drafting',
                'sort_order'  => 1,
                'variables'   => ['project_name', 'company_name'],
                'prompt_text' => <<<PROMPT
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
PROMPT,
            ],
            [
                'category'    => 'General Admin',
                'title'       => 'Rewrite Text Professionally',
                'slug'        => 'rewrite-text-professionally',
                'description' => 'Rewrite any text in a professional construction project management tone.',
                'use_case'    => 'Writing Assistance',
                'sort_order'  => 2,
                'variables'   => [],
                'prompt_text' => <<<PROMPT
Rewrite the following text in a professional construction project management tone.

Keep it clear, concise, and formal.

Text:
[PASTE TEXT HERE]
PROMPT,
            ],
            [
                'category'    => 'General Admin',
                'title'       => 'Create Action Plan',
                'slug'        => 'create-action-plan',
                'description' => 'Create a structured action plan from a construction project issue.',
                'use_case'    => 'Planning',
                'sort_order'  => 3,
                'variables'   => [],
                'prompt_text' => <<<PROMPT
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
PROMPT,
            ],
        ];
    }
}
