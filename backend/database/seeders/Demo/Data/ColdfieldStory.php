<?php

namespace Database\Seeders\Demo\Data;

/**
 * Authored story for Coldfield Retail Park — Unit 4 Fit-Out: the "near
 * completion" project in the approved demo portfolio (Phase 3). Practical
 * Completion has just been achieved (2026-07-15, a week before the demo's
 * "today" of 2026-07-22) — the project is in the snagging/close-out stage,
 * not finished: retention is still pending release and the final account
 * is still in draft. That distinction (PC achieved vs. commercially
 * closed) is the whole point of this project existing alongside Priory
 * Court, which represents the stage *after* this one.
 */
class ColdfieldStory
{
    public const PROJECT = [
        'name' => 'Coldfield Retail Park — Unit 4 Fit-Out',
        'code' => 'CRP-U4',
        'description' => 'Category A to Category B fit-out of Unit 4, Coldfield Retail Park, '
            . 'for an incoming retail tenant — shopfront, internal partitioning, M&E '
            . 'distribution, and signage.',
        'status' => 'active',
        'type' => 'fitout',
        'contract_type' => 'JCT',
        'contract_value' => 1400000.00,
        'currency' => 'GBP',
        'retention_percentage' => 5.00,
        'retention_cap_percentage' => 5.00,
        'payment_terms_days' => 30,
        'start_date' => '2026-01-12',
        'end_date' => '2026-07-13',
        'practical_completion_date' => '2026-07-15',
        'address' => 'Unit 4, Coldfield Retail Park, Chester Road',
        'city' => 'Sutton Coldfield',
        'state' => 'West Midlands',
        'postcode' => 'B75 7RB',
        'country' => 'GB',
    ];

    public const CONTRACT = [
        'type' => 'main_contract',
        'title' => 'Coldfield Retail Park — Unit 4 Fit-Out: Main Contract',
        'reference_number' => 'HG-CRP-U4-001',
        'form_of_contract' => 'JCT Intermediate Building Contract',
        'standard_form_edition' => '2016',
        'procurement_route' => 'traditional',
        'governing_law' => 'England and Wales',
        'design_responsibility' => 'employer',
        'party_name' => 'Coldfield Retail Park Ltd',
        'employer_name' => 'Coldfield Retail Park Ltd',
        'qs_name' => 'Brennan Hodge Surveyors',
        'principal_designer' => 'Ferris Duckworth Architects',
        'principal_contractor' => 'Halden Grove Construction Ltd.',
        'contract_sum' => 1400000.00,
        'currency' => 'GBP',
        'retention_percentage' => 5.00,
        'retention_cap_percentage' => 5.00,
        'retention_half1_release' => 'Practical Completion',
        'retention_half2_release' => 'End of Defects Liability Period',
        'payment_terms_days' => 30,
        'payment_frequency' => 'monthly',
        'application_due_day' => 20,
        'due_date_offset_days' => 14,
        'final_date_offset_days' => 28,
        'payment_notice_offset_days' => 5,
        'pay_less_notice_offset_days' => 21,
        'manual_date_override_allowed' => true,
        'valuation_method' => 'interim_valuation',
        'vat_reverse_charge' => true,
        'performance_bond_required' => false,
        'execution_date' => '2025-12-22',
        'commencement_date' => '2026-01-12',
        'possession_date' => '2026-01-12',
        'base_date' => '2025-11-15',
        'completion_date' => '2026-07-13',
        'defects_liability_period' => '6 months',
        'defects_liability_period_months' => 6,
        'liquidated_damages' => '£800 per week (part weeks pro-rata)',
        'notice_requirements' => 'All notices to be given in writing and served by email with '
            . 'postal confirmation, per clause 1.7 of the JCT Intermediate Conditions.',
        'variation_procedure' => 'Instructed via Architect\'s Instruction; contractor to submit '
            . 'a priced quotation within 10 days of instruction.',
        'status' => 'active',
    ];

    public const TRADE_PACKAGES = [
        [
            'name' => 'Internal Fit-Out',
            'code' => 'CRP-01-FO',
            'status' => 'completed',
            'contractor_name' => 'Northgate Interiors Ltd.',
            'contract_value' => 780000.00,
            'award_date' => '2026-01-05',
            'commencement_date' => '2026-01-19',
            'completion_date' => '2026-07-10',
        ],
        [
            'name' => 'Mechanical & Electrical Fit-Out',
            'code' => 'CRP-02-ME',
            'status' => 'completed',
            'contractor_name' => 'Ribble Mechanical & Heating Ltd.',
            'contract_value' => 420000.00,
            'award_date' => '2026-01-05',
            'commencement_date' => '2026-02-02',
            'completion_date' => '2026-07-08',
        ],
        [
            'name' => 'Shopfront & Signage',
            'code' => 'CRP-03-SF',
            'status' => 'completed',
            'contractor_name' => 'Meridian Facades Ltd.',
            'contract_value' => 200000.00,
            'award_date' => '2026-02-16',
            'commencement_date' => '2026-05-11',
            'completion_date' => '2026-07-12',
        ],
    ];

    public const PROGRAMME_MILESTONES = [
        ['name' => 'Site Possession & Strip-Out Commences', 'milestone_type' => 'commencement', 'planned_date' => '2026-01-12', 'actual_date' => '2026-01-12', 'status' => 'complete'],
        ['name' => 'Strip-Out Complete', 'milestone_type' => 'obligation', 'planned_date' => '2026-02-06', 'actual_date' => '2026-02-06', 'status' => 'complete'],
        ['name' => 'Fit-Out First Fix Complete', 'milestone_type' => 'obligation', 'planned_date' => '2026-04-17', 'actual_date' => '2026-04-22', 'status' => 'complete'],
        ['name' => 'Shopfront Installation Complete', 'milestone_type' => 'obligation', 'planned_date' => '2026-07-06', 'actual_date' => '2026-07-12', 'status' => 'complete'],
        ['name' => 'Practical Completion', 'milestone_type' => 'completion', 'planned_date' => '2026-07-13', 'actual_date' => '2026-07-15', 'status' => 'complete'],
        ['name' => 'Defects Liability Period Ends', 'milestone_type' => 'handover', 'planned_date' => '2027-01-15', 'actual_date' => null, 'status' => 'not_started'],
    ];

    /** Six monthly applications; the sixth is the final application raised at PC. */
    public const PAYMENT_APPLICATIONS = [
        ['application_number' => 1, 'application_date' => '2026-02-20', 'valuation_period_start' => '2026-01-12', 'valuation_period_end' => '2026-02-15', 'gross_valuation' => 165000.00, 'status' => 'paid', 'certified_amount' => 156750.00, 'notes' => 'Strip-out and initial partitioning.'],
        ['application_number' => 2, 'application_date' => '2026-03-20', 'valuation_period_start' => '2026-02-16', 'valuation_period_end' => '2026-03-15', 'gross_valuation' => 410000.00, 'status' => 'paid', 'certified_amount' => 389500.00, 'notes' => 'M&E first fix commenced; partitioning substantially complete.'],
        ['application_number' => 3, 'application_date' => '2026-04-20', 'valuation_period_start' => '2026-03-16', 'valuation_period_end' => '2026-04-15', 'gross_valuation' => 690000.00, 'status' => 'paid', 'certified_amount' => 655500.00, 'notes' => 'Includes Variation 1 (relocated service riser).'],
        ['application_number' => 4, 'application_date' => '2026-05-20', 'valuation_period_start' => '2026-04-16', 'valuation_period_end' => '2026-05-15', 'gross_valuation' => 990000.00, 'status' => 'paid', 'certified_amount' => 940500.00, 'notes' => 'Ceiling grids, floor finishes, and M&E second fix progressing.'],
        ['application_number' => 5, 'application_date' => '2026-06-20', 'valuation_period_start' => '2026-05-16', 'valuation_period_end' => '2026-06-15', 'gross_valuation' => 1240000.00, 'status' => 'paid', 'certified_amount' => 1178000.00, 'notes' => 'Includes Variation 2 (upgraded shopfront glazing spec). Shopfront installation commenced.'],
        ['application_number' => 6, 'application_date' => '2026-07-17', 'valuation_period_start' => '2026-06-16', 'valuation_period_end' => '2026-07-15', 'gross_valuation' => 1400000.00, 'status' => 'certified', 'certified_amount' => 1330000.00, 'notes' => 'Final application, raised following Practical Completion on 2026-07-15. Payment pending final date for payment.'],
    ];

    public const VARIATIONS = [
        [
            'variation_number' => 1,
            'title' => 'Relocated service riser to accommodate tenant kitchen layout',
            'type' => 'substitution',
            'description' => 'Relocation of the M&E service riser from the originally designed '
                . 'position to clear the incoming tenant\'s revised kitchen layout, confirmed '
                . 'after lease agreement.',
            'instruction_date' => '2026-03-25',
            'quoted_amount' => 14800.00,
            'agreed_amount' => 13600.00,
            'variation_date' => '2026-04-02',
            'status' => 'approved',
            'programme_impact_days' => 0,
        ],
        [
            'variation_number' => 2,
            'title' => 'Upgraded shopfront glazing specification',
            'type' => 'addition',
            'description' => 'Upgrade from single to double-glazed shopfront units at the '
                . 'landlord\'s request, to bring the unit in line with the retail park\'s '
                . 'updated energy performance standard.',
            'instruction_date' => '2026-06-01',
            'quoted_amount' => 22400.00,
            'agreed_amount' => 21200.00,
            'variation_date' => '2026-06-12',
            'status' => 'approved',
            'programme_impact_days' => 0,
        ],
    ];

    public const RISKS = [
        [
            'title' => 'Tenant fit-out coordination during landlord works',
            'description' => 'The incoming tenant\'s own shop-fitters were scheduled to access '
                . 'the unit before Practical Completion, risking overlap with landlord works.',
            'severity' => 'medium',
            'probability' => 'medium',
            'category' => 'programme',
            'urgency' => 'monitor',
            'mitigation' => 'Access sequencing agreed directly with the tenant\'s shop-fitter '
                . 'ahead of Practical Completion — no clash occurred.',
            'status' => 'closed',
        ],
        [
            'title' => 'Long lead time on double-glazed shopfront units (Variation 2)',
            'description' => 'The upgraded glazing specification carried a longer manufacturing '
                . 'lead time than the standard units originally allowed for in the programme.',
            'severity' => 'medium',
            'probability' => 'medium',
            'category' => 'programme',
            'urgency' => 'monitor',
            'mitigation' => 'Order placed same week as instruction to protect the programme; '
                . 'shopfront completed only 6 days behind the original milestone date.',
            'status' => 'closed',
        ],
        [
            'title' => 'Outstanding O&M manuals from M&E subcontractor',
            'description' => 'Ribble Mechanical & Heating Ltd. has not yet submitted complete '
                . 'O&M manuals ahead of the close-out deadline.',
            'severity' => 'low',
            'probability' => 'medium',
            'category' => 'commercial',
            'urgency' => 'monitor',
            'mitigation' => 'Formally requested with a revised submission date; final retention '
                . 'release moiety will be withheld if not received in time.',
            'status' => 'open',
        ],
    ];

    public const RFIS = [
        [
            'rfi_number' => 1,
            'subject' => 'Confirmation of tenant kitchen extract route',
            'description' => 'Requesting confirmation of the extract duct route to roof level '
                . 'following the tenant\'s revised kitchen layout.',
            'priority' => 'high',
            'status' => 'closed',
            'raised_date' => '2026-03-18',
            'response_due_date' => '2026-03-25',
            'responded_at' => '2026-03-23',
            'response' => 'Extract route confirmed via the northeast riser — see Variation 1.',
        ],
        [
            'rfi_number' => 2,
            'subject' => 'Shopfront signage zone lighting connection point',
            'description' => 'Requesting confirmation of the electrical connection point for '
                . 'tenant signage, not shown on the M&E second fix drawing.',
            'priority' => 'normal',
            'status' => 'closed',
            'raised_date' => '2026-06-05',
            'response_due_date' => '2026-06-12',
            'responded_at' => '2026-06-09',
            'response' => 'Connection point confirmed at the shopfront header, fed from the '
                . 'retail distribution board — no additional cost.',
        ],
    ];

    public const SNAGS = [
        ['title' => 'Paint touch-ups required to rear stockroom walls', 'description' => 'Several small paint touch-ups required to the rear stockroom following final clean.', 'category' => 'finishes', 'priority' => 'low', 'status' => 'closed', 'due_date' => '2026-07-18'],
        ['title' => 'Ceiling tile misalignment above sales floor, Bay 3', 'description' => 'Suspended ceiling tiles misaligned above the sales floor at Bay 3 — re-set required.', 'category' => 'internal_fit_out', 'priority' => 'low', 'status' => 'ready_for_review', 'due_date' => '2026-07-25'],
        ['title' => 'Shopfront door closer adjustment', 'description' => 'Main entrance door closer requires adjustment — currently closing too quickly.', 'category' => 'shopfront', 'priority' => 'medium', 'status' => 'in_progress', 'due_date' => '2026-07-28'],
        ['title' => 'Sanitaryware sealant finish, staff WC', 'description' => 'Sealant finish around staff WC sanitaryware requires re-doing to a neater standard.', 'category' => 'mechanical_electrical', 'priority' => 'low', 'status' => 'open', 'due_date' => '2026-07-29'],
        ['title' => 'Signage zone light fitting flickering', 'description' => 'One light fitting in the signage zone intermittently flickers — suspected loose connection.', 'category' => 'mechanical_electrical', 'priority' => 'medium', 'status' => 'open', 'due_date' => '2026-07-30'],
    ];

    public const QA_REPORTS = [
        ['report_number' => 1, 'title' => 'Pre-Practical Completion inspection', 'inspection_type' => 'general', 'area' => 'Whole unit', 'inspection_date' => '2026-07-08', 'status' => 'passed', 'result' => 'Pass, minor snags noted', 'observations' => 'Unit substantially complete and fit for tenant fit-out to commence; minor snagging items raised separately.'],
        ['report_number' => 2, 'title' => 'M&E commissioning sign-off', 'inspection_type' => 'mechanical_electrical', 'area' => 'Plant room and distribution', 'inspection_date' => '2026-07-11', 'status' => 'passed', 'result' => 'Pass', 'observations' => 'All M&E systems commissioned and tested satisfactorily. O&M manuals outstanding — see related risk.'],
    ];

    public const MEETINGS = [
        ['meeting_number' => 1, 'meeting_date' => '2026-02-09', 'title' => 'Progress Meeting 1 — Strip-Out', 'type' => 'progress'],
        ['meeting_number' => 2, 'meeting_date' => '2026-03-16', 'title' => 'Progress Meeting 2 — Partitioning & M&E First Fix', 'type' => 'progress'],
        ['meeting_number' => 3, 'meeting_date' => '2026-04-20', 'title' => 'Progress Meeting 3 — Variation 1 Coordination', 'type' => 'progress'],
        ['meeting_number' => 4, 'meeting_date' => '2026-05-18', 'title' => 'Progress Meeting 4 — Second Fix & Shopfront Order', 'type' => 'progress'],
        ['meeting_number' => 5, 'meeting_date' => '2026-06-15', 'title' => 'Progress Meeting 5 — Shopfront Installation Programme', 'type' => 'progress'],
        ['meeting_number' => 6, 'meeting_date' => '2026-07-16', 'title' => 'Practical Completion & Handover Meeting', 'type' => 'other'],
    ];

    public const CLOSEOUT_ITEMS = [
        ['category' => 'certification', 'title' => 'Practical Completion Certificate', 'status' => 'completed', 'due_date' => '2026-07-15'],
        ['category' => 'documentation', 'title' => 'As-Built Drawings', 'status' => 'in_progress', 'due_date' => '2026-07-29'],
        ['category' => 'documentation', 'title' => 'O&M Manuals', 'status' => 'pending', 'due_date' => '2026-08-05'],
        ['category' => 'documentation', 'title' => 'Warranties Register', 'status' => 'completed', 'due_date' => '2026-07-22'],
        ['category' => 'handover', 'title' => 'Tenant Training & Systems Handover', 'status' => 'completed', 'due_date' => '2026-07-16'],
        ['category' => 'snagging', 'title' => 'Snag List Close-Out', 'status' => 'in_progress', 'due_date' => '2026-08-05'],
    ];

    public const PROJECT_TEAM = [
        ['email' => 'tom.aldridge@haldengroveconstruction.com', 'role' => 'project_manager'],
        ['email' => 'daniel.okafor@haldengroveconstruction.com', 'role' => 'contract_admin'],
        ['email' => 'sarah.blythe@haldengroveconstruction.com', 'role' => 'site_manager'],
        ['email' => 'james.ridley@haldengroveconstruction.com', 'role' => 'member'],
    ];

    public const PROJECT_CONTACTS = [
        ['name' => 'Nadia Hodge', 'company' => 'Brennan Hodge Surveyors', 'role' => 'consultant', 'email' => 'nadia.hodge@brennanhodge.co.uk', 'phone' => '+44 121 233 4471', 'is_primary' => true],
        ['name' => 'Michael Pryce', 'company' => 'Coldfield Retail Park Ltd', 'role' => 'client', 'email' => 'm.pryce@coldfieldretailpark.co.uk', 'phone' => '+44 121 233 9012', 'is_primary' => true],
    ];
}
