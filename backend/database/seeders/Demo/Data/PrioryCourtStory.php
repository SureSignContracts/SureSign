<?php

namespace Database\Seeders\Demo\Data;

/**
 * Authored story for Priory Court Apartments — the "completed" project in
 * the approved demo portfolio (Phase 3). The whole project — Practical
 * Completion, the 3-month Defects Liability Period, and the agreed Final
 * Account — closed out on 2026-03-22, four months before the demo's
 * "today" of 2026-07-22. This is the reference example for historical
 * reporting: everything here is deliberately resolved, not live.
 *
 * The adjudication case is a genuine historical dispute that arose *during
 * construction* (over Variation 4's valuation) and was resolved well
 * before completion — included so the project demonstrates adjudication
 * support without contradicting "this project finished cleanly."
 */
class PrioryCourtStory
{
    public const PROJECT = [
        'name' => 'Priory Court Apartments',
        'code' => 'PC-APTS',
        'description' => 'New-build residential development of 42 apartments across two '
            . 'blocks, with landscaped courtyard and undercroft parking.',
        'status' => 'completed',
        'type' => 'new_build',
        'contract_type' => 'JCT',
        'contract_value' => 2900000.00,
        'currency' => 'GBP',
        'retention_percentage' => 3.00,
        'retention_cap_percentage' => 3.00,
        'payment_terms_days' => 30,
        'start_date' => '2024-10-22',
        'end_date' => '2025-12-22',
        'practical_completion_date' => '2025-12-22',
        'address' => 'Priory Court, Abbey Lane',
        'city' => 'Chester',
        'state' => 'Cheshire',
        'postcode' => 'CH1 2HH',
        'country' => 'GB',
    ];

    public const CONTRACT = [
        'type' => 'main_contract',
        'title' => 'Priory Court Apartments: Main Contract',
        'reference_number' => 'HG-PC-APTS-001',
        'form_of_contract' => 'JCT Standard Building Contract',
        'standard_form_edition' => '2016',
        'procurement_route' => 'traditional',
        'governing_law' => 'England and Wales',
        'design_responsibility' => 'employer',
        'party_name' => 'Priory Court Developments Ltd',
        'employer_name' => 'Priory Court Developments Ltd',
        'qs_name' => 'Ellery Marchmont Chartered Surveyors',
        'principal_designer' => 'Fenwick Okoye Architects',
        'principal_contractor' => 'Halden Grove Construction Ltd.',
        'contract_sum' => 2900000.00,
        'currency' => 'GBP',
        'retention_percentage' => 3.00,
        'retention_cap_percentage' => 3.00,
        'retention_half1_release' => 'Practical Completion',
        'retention_half2_release' => 'End of Defects Liability Period',
        'payment_terms_days' => 30,
        'payment_frequency' => 'monthly',
        'application_due_day' => 25,
        'due_date_offset_days' => 17,
        'final_date_offset_days' => 31,
        'payment_notice_offset_days' => 5,
        'pay_less_notice_offset_days' => 24,
        'manual_date_override_allowed' => true,
        'valuation_method' => 'interim_valuation',
        'vat_reverse_charge' => true,
        'performance_bond_required' => true,
        'execution_date' => '2024-10-08',
        'commencement_date' => '2024-10-22',
        'possession_date' => '2024-10-22',
        'base_date' => '2024-08-15',
        'completion_date' => '2025-12-22',
        'defects_liability_period' => '3 months',
        'defects_liability_period_months' => 3,
        'liquidated_damages' => '£2,200 per week (part weeks pro-rata)',
        'notice_requirements' => 'All notices to be given in writing and served by email with '
            . 'postal confirmation, per clause 1.7 of the JCT Standard Building Contract Conditions.',
        'variation_procedure' => 'Instructed via Architect\'s Instruction; contractor to submit '
            . 'a priced quotation within 14 days of instruction.',
        'status' => 'completed',
        'archived_at' => '2026-03-22 17:00:00',
    ];

    /** All completed — this project is the "everything finished" reference. */
    public const TRADE_PACKAGES = [
        ['name' => 'Groundworks', 'code' => 'PC-01-GW', 'contractor_name' => 'Pennine Groundworks Ltd.', 'contract_value' => 240000.00, 'award_date' => '2024-10-15', 'commencement_date' => '2024-11-04', 'completion_date' => '2025-01-17'],
        ['name' => 'Concrete Frame', 'code' => 'PC-02-CF', 'contractor_name' => 'Ardsley Concrete Frames Ltd.', 'contract_value' => 420000.00, 'award_date' => '2024-12-02', 'commencement_date' => '2025-01-20', 'completion_date' => '2025-04-11'],
        ['name' => 'Brickwork & Blockwork', 'code' => 'PC-03-BW', 'contractor_name' => 'Calder Valley Brickwork Ltd.', 'contract_value' => 310000.00, 'award_date' => '2025-03-10', 'commencement_date' => '2025-04-14', 'completion_date' => '2025-07-11'],
        ['name' => 'Roofing & Waterproofing', 'code' => 'PC-04-RF', 'contractor_name' => 'Summit Roofing Systems Ltd.', 'contract_value' => 195000.00, 'award_date' => '2025-04-21', 'commencement_date' => '2025-05-19', 'completion_date' => '2025-07-25'],
        ['name' => 'Internal Fit-Out', 'code' => 'PC-05-FO', 'contractor_name' => 'Northgate Interiors Ltd.', 'contract_value' => 640000.00, 'award_date' => '2025-06-09', 'commencement_date' => '2025-07-14', 'completion_date' => '2025-11-14'],
        ['name' => 'Mechanical Services', 'code' => 'PC-06-MS', 'contractor_name' => 'Ribble Mechanical & Heating Ltd.', 'contract_value' => 380000.00, 'award_date' => '2025-06-09', 'commencement_date' => '2025-07-28', 'completion_date' => '2025-11-21'],
        ['name' => 'Electrical Services', 'code' => 'PC-07-ES', 'contractor_name' => 'Kirkstall Electrical Contractors Ltd.', 'contract_value' => 350000.00, 'award_date' => '2025-06-09', 'commencement_date' => '2025-07-28', 'completion_date' => '2025-11-21'],
        ['name' => 'Landscaping & External Works', 'code' => 'PC-08-LS', 'contractor_name' => 'Greenfield Landscaping Ltd.', 'contract_value' => 165000.00, 'award_date' => '2025-09-01', 'commencement_date' => '2025-10-13', 'completion_date' => '2025-12-15'],
    ];

    public const PROGRAMME_MILESTONES = [
        ['name' => 'Site Possession & Mobilisation', 'milestone_type' => 'commencement', 'planned_date' => '2024-10-22', 'actual_date' => '2024-10-22', 'status' => 'complete'],
        ['name' => 'Substructure Complete', 'milestone_type' => 'obligation', 'planned_date' => '2025-01-20', 'actual_date' => '2025-01-17', 'status' => 'complete'],
        ['name' => 'Superstructure Frame Complete', 'milestone_type' => 'obligation', 'planned_date' => '2025-04-14', 'actual_date' => '2025-04-11', 'status' => 'complete'],
        ['name' => 'Building Watertight', 'milestone_type' => 'obligation', 'planned_date' => '2025-07-28', 'actual_date' => '2025-07-25', 'status' => 'complete'],
        ['name' => 'Fit-Out & M&E Complete', 'milestone_type' => 'obligation', 'planned_date' => '2025-11-24', 'actual_date' => '2025-11-21', 'status' => 'complete'],
        ['name' => 'Practical Completion', 'milestone_type' => 'completion', 'planned_date' => '2025-12-22', 'actual_date' => '2025-12-22', 'status' => 'complete'],
        ['name' => 'Defects Liability Period Ends', 'milestone_type' => 'handover', 'planned_date' => '2026-03-22', 'actual_date' => '2026-03-22', 'status' => 'complete'],
    ];

    /** Five representative applications spanning the 14-month contract, all paid. */
    public const PAYMENT_APPLICATIONS = [
        ['application_number' => 1, 'application_date' => '2024-12-01', 'valuation_period_start' => '2024-10-22', 'valuation_period_end' => '2024-11-30', 'gross_valuation' => 310000.00, 'certified_amount' => 300700.00, 'notes' => 'Groundworks and early substructure.'],
        ['application_number' => 2, 'application_date' => '2025-03-03', 'valuation_period_start' => '2025-02-01', 'valuation_period_end' => '2025-02-28', 'gross_valuation' => 980000.00, 'certified_amount' => 950600.00, 'notes' => 'Frame progressing; brickwork mobilised. Includes Variation 1.'],
        ['application_number' => 3, 'application_date' => '2025-06-02', 'valuation_period_start' => '2025-05-01', 'valuation_period_end' => '2025-05-31', 'gross_valuation' => 1720000.00, 'certified_amount' => 1668400.00, 'notes' => 'Envelope trades progressing; fit-out mobilising. Includes Variation 4 (external envelope insulation upgrade), agreed following adjudication.'],
        ['application_number' => 4, 'application_date' => '2025-10-01', 'valuation_period_start' => '2025-09-01', 'valuation_period_end' => '2025-09-30', 'gross_valuation' => 2530000.00, 'certified_amount' => 2453100.00, 'notes' => 'Fit-out and M&E substantially complete; landscaping mobilised.'],
        ['application_number' => 5, 'application_date' => '2026-01-05', 'valuation_period_start' => '2025-12-01', 'valuation_period_end' => '2025-12-22', 'gross_valuation' => 2900000.00, 'certified_amount' => 2813000.00, 'notes' => 'Final application, following Practical Completion on 2025-12-22.'],
    ];

    public const VARIATIONS = [
        [
            'variation_number' => 1,
            'title' => 'Revised podium waterproofing detail',
            'type' => 'addition',
            'description' => 'Enhanced waterproofing detail to the podium deck following a '
                . 'design development review with the structural engineer.',
            'instruction_date' => '2025-02-10',
            'quoted_amount' => 21500.00,
            'agreed_amount' => 20200.00,
            'variation_date' => '2025-02-24',
            'status' => 'approved',
        ],
        [
            'variation_number' => 2,
            'title' => 'Additional acoustic floor treatment, Block B',
            'type' => 'addition',
            'description' => 'Enhanced acoustic floor treatment to Block B apartments following '
                . 'a Building Control comment.',
            'instruction_date' => '2025-04-28',
            'quoted_amount' => 16800.00,
            'agreed_amount' => 15900.00,
            'variation_date' => '2025-05-09',
            'status' => 'approved',
        ],
        [
            'variation_number' => 3,
            'title' => 'Revised entrance lobby finishes specification',
            'type' => 'substitution',
            'description' => 'Employer-requested upgrade to the entrance lobby floor and wall '
                . 'finishes specification.',
            'instruction_date' => '2025-08-04',
            'quoted_amount' => 12300.00,
            'agreed_amount' => 11700.00,
            'variation_date' => '2025-08-18',
            'status' => 'approved',
        ],
        [
            // The subject of the adjudication case below.
            'variation_number' => 4,
            'title' => 'External envelope insulation upgrade',
            'type' => 'addition',
            'description' => 'Upgraded external wall insulation specification across both '
                . 'blocks, instructed following a revised SAP assessment. The contractor\'s '
                . 'valuation of this variation was disputed by the Employer\'s quantity '
                . 'surveyor and referred to adjudication — see the related adjudication case.',
            'instruction_date' => '2025-05-15',
            'quoted_amount' => 68000.00,
            'agreed_amount' => 61500.00,
            'variation_date' => '2025-08-20',
            'status' => 'approved',
        ],
    ];

    /** All closed — this is the "everything resolved" reference project. */
    public const RISKS = [
        [
            'title' => 'Ground contamination risk from former industrial site use',
            'description' => 'Desk study identified a risk of localised contamination from the '
                . 'site\'s former industrial use.',
            'severity' => 'high',
            'probability' => 'low',
            'category' => 'environmental',
            'urgency' => 'monitor',
            'mitigation' => 'Trial pits confirmed no material contamination; no remediation '
                . 'required beyond standard groundworks.',
            'status' => 'closed',
            'trade_package_code' => 'PC-01-GW',
        ],
        [
            'title' => 'Steel reinforcement price volatility during frame construction',
            'description' => 'Reinforcement steel prices were volatile during the concrete '
                . 'frame package, risking a cost pressure on the fixed-price subcontract.',
            'severity' => 'medium',
            'probability' => 'medium',
            'category' => 'commercial',
            'urgency' => 'monitor',
            'mitigation' => 'Subcontract price was fixed at award with no fluctuations clause '
                . 'exposure to Halden Grove; risk sat with the subcontractor.',
            'status' => 'closed',
            'trade_package_code' => 'PC-02-CF',
        ],
        [
            'title' => 'Disputed valuation of external envelope insulation upgrade',
            'description' => 'The Employer\'s quantity surveyor disputed the contractor\'s '
                . 'valuation of Variation 4, requiring referral to adjudication.',
            'severity' => 'high',
            'probability' => 'high',
            'category' => 'commercial',
            'urgency' => 'act_now',
            'mitigation' => 'Resolved via adjudication — decision issued 2025-08-15 broadly '
                . 'supporting the contractor\'s valuation; agreed amount included in Payment '
                . 'Application 3.',
            'status' => 'closed',
        ],
        [
            'title' => 'Landscaping subcontractor late mobilisation',
            'description' => 'Greenfield Landscaping Ltd. mobilised later than programmed, '
                . 'risking a delay to Practical Completion.',
            'severity' => 'medium',
            'probability' => 'medium',
            'category' => 'programme',
            'urgency' => 'monitor',
            'mitigation' => 'Programme float absorbed the late start; no impact on the '
                . 'Practical Completion date.',
            'status' => 'closed',
            'trade_package_code' => 'PC-08-LS',
        ],
    ];

    public const RFIS = [
        ['rfi_number' => 1, 'subject' => 'Confirmation of podium waterproofing detail', 'description' => 'Requesting confirmation of the podium waterproofing detail ahead of Variation 1 pricing.', 'priority' => 'high', 'status' => 'closed', 'raised_date' => '2025-02-03', 'response_due_date' => '2025-02-10', 'responded_at' => '2025-02-07', 'response' => 'Detail confirmed per revised drawing STR-PC-088 Rev B — see Variation 1.'],
        ['rfi_number' => 2, 'subject' => 'Entrance lobby finishes schedule query', 'description' => 'Requesting the finalised finishes schedule for the entrance lobby ahead of Variation 3 pricing.', 'priority' => 'normal', 'status' => 'closed', 'raised_date' => '2025-07-22', 'response_due_date' => '2025-07-29', 'responded_at' => '2025-07-28', 'response' => 'Finishes schedule issued — see Variation 3.'],
        ['rfi_number' => 3, 'subject' => 'Landscaping boundary treatment detail', 'description' => 'Requesting confirmation of the boundary treatment detail at the southern edge of the courtyard.', 'priority' => 'normal', 'status' => 'closed', 'raised_date' => '2025-10-06', 'response_due_date' => '2025-10-13', 'responded_at' => '2025-10-10', 'response' => 'Boundary treatment confirmed per landscape drawing LA-204 Rev A — no cost impact.'],
    ];

    public const QA_REPORTS = [
        ['report_number' => 1, 'title' => 'Pre-Practical Completion inspection — Block A', 'inspection_type' => 'general', 'area' => 'Block A, all floors', 'inspection_date' => '2025-12-15', 'status' => 'passed', 'result' => 'Pass', 'observations' => 'Block A complete and fit for handover; minor snags raised separately.'],
        ['report_number' => 2, 'title' => 'Pre-Practical Completion inspection — Block B', 'inspection_type' => 'general', 'area' => 'Block B, all floors', 'inspection_date' => '2025-12-16', 'status' => 'passed', 'result' => 'Pass', 'observations' => 'Block B complete and fit for handover; minor snags raised separately.'],
        ['report_number' => 3, 'title' => 'End of Defects Liability Period inspection', 'inspection_type' => 'general', 'area' => 'Whole site', 'inspection_date' => '2026-03-15', 'status' => 'passed', 'result' => 'Pass — no outstanding defects', 'observations' => 'Full site walk ahead of the end of the Defects Liability Period. All defects notified during the period have been closed out. No further items outstanding.'],
    ];

    public const MEETINGS = [
        ['meeting_number' => 1, 'meeting_date' => '2024-11-05', 'title' => 'Progress Meeting 1 — Mobilisation', 'type' => 'progress'],
        ['meeting_number' => 2, 'meeting_date' => '2025-02-11', 'title' => 'Progress Meeting 4 — Frame & Variation 1', 'type' => 'progress'],
        ['meeting_number' => 3, 'meeting_date' => '2025-05-20', 'title' => 'Progress Meeting 8 — Envelope Trades & Adjudication Update', 'type' => 'progress'],
        ['meeting_number' => 4, 'meeting_date' => '2025-09-16', 'title' => 'Progress Meeting 12 — Fit-Out & M&E Progress', 'type' => 'progress'],
        ['meeting_number' => 5, 'meeting_date' => '2025-12-18', 'title' => 'Practical Completion & Handover Meeting', 'type' => 'other'],
        ['meeting_number' => 6, 'meeting_date' => '2026-03-20', 'title' => 'End of Defects Liability Period Close-Out Meeting', 'type' => 'other'],
    ];

    public const CLOSEOUT_ITEMS = [
        ['category' => 'certification', 'title' => 'Practical Completion Certificate', 'status' => 'approved', 'due_date' => '2025-12-22'],
        ['category' => 'documentation', 'title' => 'As-Built Drawings', 'status' => 'approved', 'due_date' => '2026-01-19'],
        ['category' => 'documentation', 'title' => 'O&M Manuals', 'status' => 'approved', 'due_date' => '2026-01-19'],
        ['category' => 'documentation', 'title' => 'Warranties Register', 'status' => 'approved', 'due_date' => '2026-01-19'],
        ['category' => 'handover', 'title' => 'Resident Handover Packs', 'status' => 'approved', 'due_date' => '2025-12-22'],
        ['category' => 'certification', 'title' => 'Certificate of Making Good Defects', 'status' => 'approved', 'due_date' => '2026-03-22'],
    ];

    public const ADJUDICATION_CASE = [
        'case_number' => 'ADJ-PC-2025-01',
        'title' => 'Valuation dispute — Variation 4 (External Envelope Insulation Upgrade)',
        'dispute_type' => 'valuation_dispute',
        'claimant_name' => 'Halden Grove Construction Ltd.',
        'respondent_name' => 'Priory Court Developments Ltd',
        'claim_amount' => 68000.00,
        'summary' => 'Halden Grove referred a dispute over the valuation of Variation 4 '
            . '(external envelope insulation upgrade) to adjudication after Ellery Marchmont '
            . 'Chartered Surveyors, acting for the Employer, assessed the variation at '
            . '£42,000 against Halden Grove\'s quotation of £68,000. The adjudicator\'s '
            . 'decision, issued 2025-08-15, valued the variation at £61,500 — largely '
            . 'supporting the contractor\'s position — and this amount was subsequently '
            . 'agreed and included in Payment Application 3.',
        'status' => 'closed',
        'current_step' => 'enforcement',
        'notice_of_dispute_date' => '2025-06-20',
        'notice_of_adjudication_date' => '2025-06-27',
        'referral_due_date' => '2025-07-04',
        'response_due_date' => '2025-07-18',
        'decision_due_date' => '2025-08-15',
        'decision_received_date' => '2025-08-15',
        'enforcement_deadline' => '2025-08-29',
    ];

    public const APPOINTMENTS = [
        ['reference' => 'APT-DEMO-PC-001', 'type_slug' => 'customer-onboarding', 'attendee_email' => 'priya.chandra@haldengroveconstruction.com', 'starts_at' => '2024-10-15 10:00:00', 'ends_at' => '2024-10-15 11:00:00', 'completion_notes' => 'Onboarding review ahead of Priory Court mobilisation — no new SureSign setup required, existing Halden Grove account used.'],
        ['reference' => 'APT-DEMO-PC-002', 'type_slug' => 'account-review', 'attendee_email' => 'daniel.okafor@haldengroveconstruction.com', 'starts_at' => '2025-12-19 11:00:00', 'ends_at' => '2025-12-19 11:30:00', 'completion_notes' => 'Post-Practical Completion account review — final account and retention release process discussed.'],
        ['reference' => 'APT-DEMO-PC-003', 'type_slug' => 'account-review', 'attendee_email' => 'priya.chandra@haldengroveconstruction.com', 'starts_at' => '2026-03-25 14:00:00', 'ends_at' => '2026-03-25 14:30:00', 'completion_notes' => 'Project close-out review following the end of the Defects Liability Period and agreed Final Account.'],
    ];

    public const PROJECT_TEAM = [
        ['email' => 'priya.chandra@haldengroveconstruction.com', 'role' => 'project_manager'],
        ['email' => 'daniel.okafor@haldengroveconstruction.com', 'role' => 'contract_admin'],
        ['email' => 'james.ridley@haldengroveconstruction.com', 'role' => 'member'],
    ];

    public const PROJECT_CONTACTS = [
        ['name' => 'Edmund Ellery', 'company' => 'Ellery Marchmont Chartered Surveyors', 'role' => 'consultant', 'email' => 'e.ellery@ellerymarchmont.co.uk', 'phone' => '+44 1244 355 021', 'is_primary' => true],
        ['name' => 'Charlotte Fenn', 'company' => 'Priory Court Developments Ltd', 'role' => 'client', 'email' => 'c.fenn@priorycourtdevelopments.co.uk', 'phone' => '+44 1244 355 890', 'is_primary' => true],
    ];
}
