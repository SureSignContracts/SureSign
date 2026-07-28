<?php

namespace Database\Seeders\Demo\Data;

/**
 * Single authored source of truth for the Riverside Wharf — Block C
 * Residential flagship demo project (Phase 2 of the approved demo
 * environment blueprint). Every date, value, and description below was
 * chosen deliberately to form one internally consistent 9-month project
 * history — nothing here is random or filler.
 *
 * Anchor: "today" for the whole story is 2026-07-22. The project commenced
 * 2025-10-20 and is 9 months into an 18-month programme (planned completion
 * 2027-04-20) — see PROJECT['start_date']/['end_date'].
 *
 * All demo seeders for this project read from here rather than hard-coding
 * dates/values inline, so the story can be reasoned about and adjusted in
 * one place.
 */
class RiversideWharfStory
{
    public const TODAY = '2026-07-22';

    public const PROJECT = [
        'name' => 'Riverside Wharf — Block C Residential',
        'code' => 'RW-BLC',
        'description' => 'New-build residential block (Block C) forming phase two of the '
            . 'Riverside Wharf regeneration scheme — 84 apartments across 8 storeys over a '
            . 'concrete podium, with associated external works.',
        'status' => 'active',
        'type' => 'new_build',
        'contract_type' => 'JCT',
        'contract_value' => 6200000.00,
        'currency' => 'GBP',
        'retention_percentage' => 3.00,
        'retention_cap_percentage' => 3.00,
        'payment_terms_days' => 30,
        'start_date' => '2025-10-20',
        'end_date' => '2027-04-20',
        'practical_completion_date' => null,
        'address' => 'Riverside Wharf, Plot C, Navigation Street',
        'city' => 'Manchester',
        'state' => 'Greater Manchester',
        'postcode' => 'M1 7DU',
        'country' => 'GB',
    ];

    public const CONTRACT = [
        'type' => 'main_contract',
        'title' => 'Riverside Wharf — Block C Residential: Main Contract',
        'reference_number' => 'HG-RW-BLC-001',
        'form_of_contract' => 'JCT Design and Build Contract',
        'standard_form_edition' => '2016',
        'procurement_route' => 'design_and_build',
        'governing_law' => 'England and Wales',
        'design_responsibility' => 'contractor',
        'party_name' => 'Riverside Wharf Developments LLP',
        'employer_name' => 'Riverside Wharf Developments LLP',
        'qs_name' => 'Whitfield & Sutton Chartered Surveyors',
        'principal_designer' => 'Marlow Bennett Architects',
        'principal_contractor' => 'Halden Grove Construction Ltd.',
        'contract_sum' => 6200000.00,
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
        'fluctuations_clause' => 'none',
        'execution_date' => '2025-10-06',
        'commencement_date' => '2025-10-20',
        'possession_date' => '2025-10-20',
        'base_date' => '2025-08-15',
        'completion_date' => '2027-04-20',
        'defects_liability_period' => '12 months',
        'defects_liability_period_months' => 12,
        'liquidated_damages' => '£4,500 per week (part weeks pro-rata)',
        'notice_requirements' => 'All notices to be given in writing and served by email with postal '
            . 'confirmation, per clause 1.7 of the JCT Design and Build Conditions.',
        'variation_procedure' => 'Instructed via Architect\'s Instruction or Employer\'s Agent '
            . 'confirmation; contractor to submit a priced quotation within 14 days of instruction '
            . 'unless a shorter period is agreed.',
        'status' => 'active',
    ];

    public const CONTRACT_AI_ANALYSIS = [
        'provider' => 'anthropic',
        'model' => 'claude-sonnet-4-5',
        'summary' => 'JCT Design and Build 2016 main contract between Halden Grove Construction '
            . 'Ltd. and Riverside Wharf Developments LLP for the Block C residential works. '
            . 'Monthly interim valuations, 3% retention released in two equal moieties at '
            . 'Practical Completion and end of a 12-month Defects Liability Period, and '
            . 'liquidated damages of £4,500 per week for late completion.',
        'tokens_input' => 18420,
        'tokens_output' => 2140,
        'estimated_cost' => 0.184200,
        'stop_reason' => 'end_turn',
    ];

    /**
     * Each row: [name, package_code, status, contractor_name, contract_value,
     * retention_percentage, payment_frequency, award_date, commencement_date,
     * completion_date (planned or actual)]. completion_date is actual for
     * completed packages and a forward-looking plan for the rest — this is
     * what makes the project read as "month 9 of 18" rather than a random
     * snapshot: three packages are genuinely finished, two are genuinely
     * mid-progress, one has barely started, and four haven't started yet.
     */
    public const TRADE_PACKAGES = [
        [
            'name' => 'Groundworks',
            'code' => 'TP-01-GW',
            'status' => 'completed',
            'contractor_name' => 'Pennine Groundworks Ltd.',
            'contract_value' => 480000.00,
            'retention_percentage' => 3.00,
            'payment_frequency' => 'monthly',
            'award_date' => '2025-10-13',
            'commencement_date' => '2025-11-03',
            'completion_date' => '2026-01-16',
        ],
        [
            'name' => 'Concrete Frame',
            'code' => 'TP-02-CF',
            'status' => 'completed',
            'contractor_name' => 'Ardsley Concrete Frames Ltd.',
            'contract_value' => 890000.00,
            'retention_percentage' => 3.00,
            'payment_frequency' => 'monthly',
            'award_date' => '2025-12-01',
            'commencement_date' => '2026-01-19',
            'completion_date' => '2026-04-10',
        ],
        [
            'name' => 'Structural Steel',
            'code' => 'TP-03-SS',
            'status' => 'completed',
            'contractor_name' => 'Kirkstall Steel Fabrications Ltd.',
            'contract_value' => 610000.00,
            'retention_percentage' => 3.00,
            'payment_frequency' => 'monthly',
            'award_date' => '2026-01-12',
            'commencement_date' => '2026-03-02',
            'completion_date' => '2026-05-15',
        ],
        [
            'name' => 'Brickwork & Blockwork',
            'code' => 'TP-04-BW',
            'status' => 'active',
            'contractor_name' => 'Calder Valley Brickwork Ltd.',
            'contract_value' => 540000.00,
            'retention_percentage' => 3.00,
            'payment_frequency' => 'monthly',
            'award_date' => '2026-03-09',
            'commencement_date' => '2026-04-13',
            'completion_date' => '2026-08-21',
        ],
        [
            'name' => 'Roofing & Waterproofing',
            'code' => 'TP-05-RF',
            'status' => 'active',
            'contractor_name' => 'Summit Roofing Systems Ltd.',
            'contract_value' => 375000.00,
            'retention_percentage' => 3.00,
            'payment_frequency' => 'monthly',
            'award_date' => '2026-04-20',
            'commencement_date' => '2026-05-18',
            'completion_date' => '2026-08-07',
        ],
        [
            'name' => 'Facade & Cladding',
            'code' => 'TP-06-FC',
            'status' => 'active',
            'contractor_name' => 'Meridian Facades Ltd.',
            'contract_value' => 720000.00,
            'retention_percentage' => 3.00,
            'payment_frequency' => 'monthly',
            'award_date' => '2026-06-08',
            'commencement_date' => '2026-07-06',
            'completion_date' => '2026-11-13',
        ],
        [
            'name' => 'Internal Fit-Out',
            'code' => 'TP-07-FO',
            'status' => 'documents_issued',
            'contractor_name' => 'Northgate Interiors Ltd.',
            'contract_value' => 810000.00,
            'retention_percentage' => 3.00,
            'payment_frequency' => 'monthly',
            'award_date' => '2026-07-20',
            'commencement_date' => '2026-09-01',
            'completion_date' => '2027-01-29',
        ],
        [
            'name' => 'Mechanical Services',
            'code' => 'TP-08-MS',
            'status' => 'awarded',
            'contractor_name' => 'Ribble Mechanical & Heating Ltd.',
            'contract_value' => 460000.00,
            'retention_percentage' => 3.00,
            'payment_frequency' => 'monthly',
            'award_date' => '2026-07-15',
            'commencement_date' => '2026-09-15',
            'completion_date' => '2027-02-19',
        ],
        [
            'name' => 'Electrical Services',
            'code' => 'TP-09-ES',
            'status' => 'under_review',
            'contractor_name' => null,
            'contract_value' => 395000.00,
            'retention_percentage' => 3.00,
            'payment_frequency' => 'monthly',
            'award_date' => null,
            'commencement_date' => '2026-09-15',
            'completion_date' => '2027-02-19',
        ],
        [
            'name' => 'Landscaping & External Works',
            'code' => 'TP-10-LS',
            'status' => 'tendering',
            'contractor_name' => null,
            'contract_value' => 210000.00,
            'retention_percentage' => 3.00,
            'payment_frequency' => 'monthly',
            'award_date' => null,
            'commencement_date' => '2027-02-01',
            'completion_date' => '2027-04-13',
        ],
    ];

    /**
     * Contract-level programme milestones (not tied to a single trade
     * package) — the top of the programme a client/EA would actually see.
     * [name, milestone_type, planned_date, actual_date (null if not yet
     * reached), status].
     */
    public const PROGRAMME_MILESTONES = [
        ['name' => 'Site Possession & Mobilisation', 'milestone_type' => 'commencement', 'planned_date' => '2025-10-20', 'actual_date' => '2025-10-20', 'status' => 'complete'],
        ['name' => 'Substructure Complete', 'milestone_type' => 'obligation', 'planned_date' => '2026-01-16', 'actual_date' => '2026-01-16', 'status' => 'complete'],
        ['name' => 'Superstructure Frame Complete', 'milestone_type' => 'obligation', 'planned_date' => '2026-04-24', 'actual_date' => '2026-04-10', 'status' => 'complete'],
        ['name' => 'Structural Steel Complete', 'milestone_type' => 'obligation', 'planned_date' => '2026-05-22', 'actual_date' => '2026-05-15', 'status' => 'complete'],
        ['name' => 'Building Watertight', 'milestone_type' => 'obligation', 'planned_date' => '2026-09-04', 'actual_date' => null, 'status' => 'in_progress'],
        ['name' => 'Fit-Out Commences', 'milestone_type' => 'obligation', 'planned_date' => '2026-09-01', 'actual_date' => null, 'status' => 'not_started'],
        ['name' => 'Mechanical & Electrical First Fix Complete', 'milestone_type' => 'obligation', 'planned_date' => '2026-12-11', 'actual_date' => null, 'status' => 'not_started'],
        ['name' => 'Practical Completion', 'milestone_type' => 'completion', 'planned_date' => '2027-04-20', 'actual_date' => null, 'status' => 'not_started'],
        ['name' => 'Defects Liability Period Ends', 'milestone_type' => 'handover', 'planned_date' => '2028-04-20', 'actual_date' => null, 'status' => 'not_started'],
    ];

    /**
     * Six monthly interim payment applications. gross_valuation is
     * cumulative (a standard JCT interim valuation), matching an S-curve
     * of progress against the £6.2m contract sum as trade packages
     * complete. Application #6 (the latest) is where the live pay-less
     * notice dispute lives — the Employer's Agent disputes part of the
     * Facade & Cladding valuation on the grounds the package has only just
     * mobilised.
     */
    public const PAYMENT_APPLICATIONS = [
        [
            'application_number' => 1,
            'application_date' => '2025-12-01',
            'valuation_period_start' => '2025-11-01',
            'valuation_period_end' => '2025-11-30',
            'gross_valuation' => 385000.00,
            'status' => 'paid',
            'certified_amount' => 373450.00,
            'notes' => 'First interim valuation — groundworks mobilisation and early substructure.',
        ],
        [
            'application_number' => 2,
            'application_date' => '2026-01-05',
            'valuation_period_start' => '2025-12-01',
            'valuation_period_end' => '2025-12-31',
            'gross_valuation' => 760000.00,
            'status' => 'paid',
            'certified_amount' => 737200.00,
            'notes' => 'Substructure substantially complete; concrete frame mobilised.',
        ],
        [
            'application_number' => 3,
            'application_date' => '2026-02-02',
            'valuation_period_start' => '2026-01-01',
            'valuation_period_end' => '2026-01-31',
            'gross_valuation' => 1215000.00,
            'status' => 'paid',
            'certified_amount' => 1178550.00,
            'notes' => 'Includes Variation 1 (podium slab reinforcement amendment).',
        ],
        [
            'application_number' => 4,
            'application_date' => '2026-03-02',
            'valuation_period_start' => '2026-02-01',
            'valuation_period_end' => '2026-02-28',
            'gross_valuation' => 1690000.00,
            'status' => 'paid',
            'certified_amount' => 1638300.00,
            'notes' => 'Concrete frame progressing to upper floors; structural steel mobilised.',
        ],
        [
            'application_number' => 5,
            'application_date' => '2026-04-06',
            'valuation_period_start' => '2026-03-01',
            'valuation_period_end' => '2026-03-31',
            'gross_valuation' => 2180000.00,
            'status' => 'paid',
            'certified_amount' => 2114600.00,
            'notes' => 'Includes Variation 2 (acoustic insulation to Block C party walls).',
        ],
        [
            'application_number' => 6,
            'application_date' => '2026-07-06',
            'valuation_period_start' => '2026-06-01',
            'valuation_period_end' => '2026-06-30',
            'gross_valuation' => 2735000.00,
            'status' => 'pay_less_notice_issued',
            'certified_amount' => null,
            'notes' => 'Brickwork and roofing progressing; facade and cladding newly mobilised. '
                . 'Employer\'s Agent has queried the facade mobilisation valuation — see Pay Less Notice.',
        ],
    ];

    public const PAY_LESS_NOTICE = [
        'notice_date' => '2026-07-24',
        'amount' => 42500.00,
        'reason' => 'Facade & Cladding mobilisation valuation disputed — Employer\'s Agent assesses '
            . 'the claimed value of site set-up and initial bracket procurement as overstated '
            . 'relative to work genuinely executed at the valuation date.',
        'basis_of_difference' => 'Site inspection on 2026-07-18 found facade works limited to '
            . 'scaffold access and material delivery; no fixing works had commenced, whereas the '
            . 'application valued an allowance for early fixing progress.',
        'issued_by' => 'Whitfield & Sutton Chartered Surveyors (Employer\'s Agent)',
    ];

    /**
     * Three variations at different stages of the same lifecycle, so the
     * variations module shows a live pipeline rather than only closed
     * records. V1 and V2 are already reflected in payment applications 3
     * and 5 above; V3 is the currently-open one.
     */
    public const VARIATIONS = [
        [
            'variation_number' => 1,
            'title' => 'Amendment to podium slab reinforcement following ground investigation',
            'type' => 'addition',
            'description' => 'Additional reinforcement to the podium transfer slab over Grid '
                . 'Lines D-F following the supplementary ground investigation report, which found '
                . 'lower bearing capacity than the original geotechnical survey assumed.',
            'instruction_date' => '2026-01-14',
            'quoted_amount' => 44200.00,
            'agreed_amount' => 42500.00,
            'variation_date' => '2026-01-28',
            'status' => 'approved',
            'programme_impact_days' => 0,
        ],
        [
            'variation_number' => 2,
            'title' => 'Additional acoustic insulation to Block C party walls',
            'type' => 'addition',
            'description' => 'Enhanced acoustic insulation to party walls between apartments 3C '
                . 'and 4C following a Building Control comment on the approved plans, exceeding '
                . 'the Part E requirement assumed at tender.',
            'instruction_date' => '2026-03-20',
            'quoted_amount' => 19500.00,
            'agreed_amount' => 18750.00,
            'variation_date' => '2026-03-31',
            'status' => 'approved',
            'programme_impact_days' => 0,
        ],
        [
            'variation_number' => 3,
            'title' => 'Revised balcony balustrade specification (glass to aluminium)',
            'type' => 'substitution',
            'description' => 'Employer-requested substitution of frameless glass balustrades for '
                . 'powder-coated aluminium balustrades to all Block C balconies, following a '
                . 'cost review at RIBA Stage 5.',
            'instruction_date' => '2026-07-02',
            'quoted_amount' => 27300.00,
            'agreed_amount' => null,
            'variation_date' => null,
            'status' => 'quoted',
            'programme_impact_days' => 0,
        ],
    ];

    public const DELAY_EVENT = [
        'title' => 'Extended wet weather during groundworks',
        'description' => 'Rainfall over the period 2025-12-01 to 2025-12-12 exceeded the Met '
            . 'Office 10-year average for the region by a material margin, causing standing water '
            . 'in the open excavation and preventing safe continuation of piling works for a '
            . 'sustained period beyond the seasonal weather allowance in the programme.',
        'cause_category' => 'weather',
        'date_occurred' => '2025-12-10',
        'date_notified' => '2025-12-12',
        'notified_by' => 'Sarah Blythe (Site Manager)',
        'estimated_delay_days' => 12,
        'status' => 'under_assessment',
    ];

    public const EOT_REQUEST = [
        'eot_number' => 1,
        'title' => 'EOT — extended wet weather during groundworks (December 2025)',
        'grounds' => 'Clause 2.26.13 (exceptionally adverse weather conditions) — rainfall '
            . 'records from the nearest Met Office station over the affected period are attached '
            . 'in support of the claim.',
        'days_claimed' => 12,
        'notice_date' => '2025-12-15',
        'status' => 'under_review',
    ];

    public const LOSS_AND_EXPENSE_CLAIM = [
        'claim_number' => 1,
        'title' => 'Site overheads during December 2025 weather-related standstill',
        'description' => 'Preliminaries and site overhead costs incurred during the weather '
            . 'standstill period — welfare, security, and supervisory staff retained on site '
            . 'with no productive groundworks activity possible.',
        'amount_claimed' => 18000.00,
        'amount_assessed' => 15200.00,
        'amount_agreed' => 14500.00,
        'status' => 'agreed',
    ];

    /**
     * Six risks spanning open/mitigated/closed. R1/R2 sit against specific
     * trade packages (contract_id left null); the rest sit against the
     * main contract.
     */
    public const RISKS = [
        [
            'title' => 'Podium clay shrinkage affecting settlement monitoring',
            'description' => 'The supplementary ground investigation identified shrinkable clay '
                . 'beneath the podium slab. Ongoing settlement monitoring is required through the '
                . 'superstructure phase to confirm movement stays within design tolerance.',
            'severity' => 'medium',
            'probability' => 'medium',
            'category' => 'geotechnical',
            'urgency' => 'monitor',
            'mitigation' => 'Monthly settlement survey readings compared against the structural '
                . 'engineer\'s tolerance envelope; no action required unless two consecutive '
                . 'readings trend outside tolerance.',
            'status' => 'open',
            'trade_package_code' => null,
        ],
        [
            'title' => 'Brickwork subcontractor resourcing constrained',
            'description' => 'Calder Valley Brickwork Ltd. has a concurrent commitment on another '
                . 'scheme that could constrain gang numbers on Riverside Wharf through August.',
            'severity' => 'medium',
            'probability' => 'medium',
            'category' => 'resource',
            'urgency' => 'monitor',
            'mitigation' => 'Weekly resourcing confirmation requested from the subcontractor; '
                . 'programme float on this package currently absorbs a two-week resourcing dip.',
            'status' => 'open',
            'trade_package_code' => 'TP-04-BW',
        ],
        [
            'title' => 'Rising material costs for remaining structural steel procurement',
            'description' => 'Structural steel prices have risen since the original tender; '
                . 'while the main Structural Steel package is complete, any late design changes '
                . 'requiring further steel would be exposed to current market rates.',
            'severity' => 'medium',
            'probability' => 'low',
            'category' => 'commercial',
            'urgency' => 'monitor',
            'mitigation' => 'No further structural steel packages are anticipated; risk retained '
                . 'for awareness only pending final design sign-off.',
            'status' => 'open',
            'trade_package_code' => null,
        ],
        [
            'title' => 'Steel frame delivery lead time exceeding programme allowance',
            'description' => 'At award, Kirkstall Steel Fabrications Ltd. quoted a fabrication '
                . 'lead time exceeding the programme\'s allowance for structural steel delivery.',
            'severity' => 'high',
            'probability' => 'medium',
            'category' => 'programme',
            'urgency' => 'act_now',
            'mitigation' => 'Steel package was awarded four weeks earlier than originally '
                . 'programmed, and fabrication drawings were fast-tracked, absorbing the lead '
                . 'time gap. Structural Steel completed on programme.',
            'status' => 'mitigated',
            'trade_package_code' => 'TP-03-SS',
        ],
        [
            'title' => 'Party wall agreement delay with neighbouring owner',
            'description' => 'The neighbouring owner at 14 Navigation Street initially disputed '
                . 'the party wall surveyor\'s award, creating a risk to groundworks commencement.',
            'severity' => 'high',
            'probability' => 'medium',
            'category' => 'legal',
            'urgency' => 'act_now',
            'mitigation' => 'Early engagement between both parties\' surveyors resolved the '
                . 'dispute ahead of the planned groundworks start date; no delay resulted.',
            'status' => 'mitigated',
            'trade_package_code' => null,
        ],
        [
            'title' => 'Ground contamination from former industrial site use',
            'description' => 'Desk study identified the site\'s former use for light industrial '
                . 'purposes, with a risk of localised hydrocarbon contamination.',
            'severity' => 'high',
            'probability' => 'low',
            'category' => 'environmental',
            'urgency' => 'monitor',
            'mitigation' => 'Trial pits confirmed only minor, localised contamination, remediated '
                . 'as part of the groundworks package. Validation report accepted by the local '
                . 'authority.',
            'status' => 'closed',
            'trade_package_code' => 'TP-01-GW',
        ],
    ];

    /**
     * RFIs at different stages, spanning the project's full 9 months so
     * the register doesn't read as clustered around one event.
     */
    public const RFIS = [
        [
            'rfi_number' => 1,
            'subject' => 'Confirmation of podium slab construction joint locations',
            'description' => 'Requesting confirmation of construction joint locations on the '
                . 'podium transfer slab pour sequence drawing, which appears to conflict with the '
                . 'reinforcement continuity detail on the structural engineer\'s drawing.',
            'priority' => 'high',
            'status' => 'closed',
            'raised_date' => '2025-12-02',
            'response_due_date' => '2025-12-09',
            'responded_at' => '2025-12-06',
            'response' => 'Joint locations confirmed at Grid Lines B and D only, per revised '
                . 'drawing STR-104 Rev C — continuity reinforcement to be maintained through the '
                . 'joint as originally detailed.',
        ],
        [
            'rfi_number' => 2,
            'subject' => 'Clarification on brick specification for Block C parapet detail',
            'description' => 'The parapet detail references a brick specification not included '
                . 'in the approved schedule of materials — requesting confirmation of the correct '
                . 'specification and colour reference.',
            'priority' => 'normal',
            'status' => 'closed',
            'raised_date' => '2026-04-22',
            'response_due_date' => '2026-04-29',
            'responded_at' => '2026-04-25',
            'response' => 'Parapet brick to match the main elevation specification (Ibstock '
                . 'Anglia Weathered Multi) — schedule of materials to be updated to remove the '
                . 'ambiguous reference.',
        ],
        [
            'rfi_number' => 3,
            'subject' => 'Drainage fall levels to podium deck',
            'description' => 'Requesting confirmation of finished fall levels to the podium deck '
                . 'drainage, which appear shallower than the minimum 1:80 fall shown on the '
                . 'landscape architect\'s drawing.',
            'priority' => 'normal',
            'status' => 'responded',
            'raised_date' => '2026-06-15',
            'response_due_date' => '2026-06-22',
            'responded_at' => '2026-06-19',
            'response' => 'Falls to be regraded to a minimum 1:80 across the full deck per the '
                . 'landscape architect\'s drawing — no cost or programme impact, absorbed within '
                . 'the roofing package\'s screed allowance.',
        ],
        [
            'rfi_number' => 4,
            'subject' => 'Steel connection detail at grid line E/12 — clash with services zone',
            'description' => 'The steel connection detail at grid line E/12 appears to clash with '
                . 'the mechanical riser zone shown on the M&E coordination drawing. Requesting a '
                . 'revised detail or confirmation the clash has already been resolved.',
            'priority' => 'high',
            'status' => 'pending_response',
            'raised_date' => '2026-07-14',
            'response_due_date' => '2026-07-21',
            'responded_at' => null,
            'response' => null,
        ],
        [
            'rfi_number' => 5,
            'subject' => 'Facade fixing bracket tolerance query',
            'description' => 'Requesting confirmation of the acceptable tolerance for facade '
                . 'fixing bracket setting-out relative to the structural frame, ahead of the '
                . 'first fixing lift.',
            'priority' => 'normal',
            'status' => 'open',
            'raised_date' => '2026-07-20',
            'response_due_date' => '2026-07-27',
            'responded_at' => null,
            'response' => null,
        ],
    ];

    public const SITE_INSTRUCTIONS = [
        [
            'instruction_number' => 1,
            'title' => 'Additional temporary works propping to retain excavation face',
            'description' => 'Instruction to install additional trench sheeting and propping to '
                . 'the southern excavation face following the site engineer\'s inspection, in '
                . 'advance of continued piling works.',
            'issued_date' => '2025-11-18',
            'issued_to' => 'Pennine Groundworks Ltd.',
            'status' => 'complied',
        ],
        [
            'instruction_number' => 2,
            'title' => 'Proceed with revised balcony balustrade specification',
            'description' => 'Instruction to proceed with the aluminium balustrade specification '
                . 'pending formal agreement of Variation 3, to avoid delaying the facade package '
                . 'fixing sequence.',
            'issued_date' => '2026-07-08',
            'issued_to' => 'Meridian Facades Ltd.',
            'status' => 'acknowledged',
        ],
    ];

    /**
     * One meeting per month since commencement — 9 progress meetings. The
     * two most recent use the timed-scheduling fields (starts_at/ends_at)
     * to demonstrate that feature; earlier ones are date-only, which is
     * itself realistic — timed scheduling is a newer capability.
     */
    public const MEETINGS = [
        ['meeting_number' => 1, 'meeting_date' => '2025-11-04', 'title' => 'Progress Meeting 1 — Mobilisation', 'timed' => false],
        ['meeting_number' => 2, 'meeting_date' => '2025-12-09', 'title' => 'Progress Meeting 2 — Groundworks Progress', 'timed' => false],
        ['meeting_number' => 3, 'meeting_date' => '2026-01-13', 'title' => 'Progress Meeting 3 — Substructure Complete', 'timed' => false],
        ['meeting_number' => 4, 'meeting_date' => '2026-02-10', 'title' => 'Progress Meeting 4 — Frame Progress', 'timed' => false],
        ['meeting_number' => 5, 'meeting_date' => '2026-03-17', 'title' => 'Progress Meeting 5 — Frame & Steel Coordination', 'timed' => false],
        ['meeting_number' => 6, 'meeting_date' => '2026-04-14', 'title' => 'Progress Meeting 6 — Frame Complete', 'timed' => false],
        ['meeting_number' => 7, 'meeting_date' => '2026-05-19', 'title' => 'Progress Meeting 7 — Steel Complete, Envelope Trades Mobilising', 'timed' => true, 'starts_at' => '2026-05-19 10:00:00', 'ends_at' => '2026-05-19 11:30:00'],
        ['meeting_number' => 8, 'meeting_date' => '2026-06-16', 'title' => 'Progress Meeting 8 — Brickwork & Roofing Progress', 'timed' => true, 'starts_at' => '2026-06-16 10:00:00', 'ends_at' => '2026-06-16 11:30:00'],
        ['meeting_number' => 9, 'meeting_date' => '2026-07-21', 'title' => 'Progress Meeting 9 — Facade Mobilisation & Pay Less Notice', 'timed' => true, 'starts_at' => '2026-07-21 10:00:00', 'ends_at' => '2026-07-21 12:00:00'],
    ];

    /**
     * Weekly site diaries for the most recent ~3 months (per the approved
     * module coverage plan) rather than the full 9 months — real site
     * diary discipline is usually tightest in the most recent stretch of a
     * project, and 3 months of distinct weekly records demonstrates the
     * module without mechanically padding 39 near-identical rows. Each
     * entry reflects whichever trade packages were genuinely active that
     * week (Brickwork from 2026-04-13, Roofing from 2026-05-18, Facade
     * from 2026-07-06).
     */
    public const SITE_DIARIES = [
        ['diary_date' => '2026-04-27', 'weather' => 'Overcast, dry', 'temperature' => 11.5, 'workers_on_site' => 18, 'works_carried_out' => 'Brickwork gang mobilised to Level 1, north elevation. Scaffold hand-over from concrete frame subcontractor completed.', 'materials_delivered' => 'Facing brick delivery (Ibstock Anglia Weathered Multi), pallets 1-6.', 'visitors' => 'Structural engineer — final frame inspection.'],
        ['diary_date' => '2026-05-04', 'weather' => 'Light rain, clearing by midday', 'temperature' => 12.0, 'workers_on_site' => 20, 'works_carried_out' => 'Brickwork continuing Level 1 north and east elevations. DPC installation to ground floor openings.', 'materials_delivered' => 'Cavity trays and wall ties.', 'visitors' => null],
        ['diary_date' => '2026-05-11', 'weather' => 'Sunny, warm', 'temperature' => 17.0, 'workers_on_site' => 22, 'works_carried_out' => 'Brickwork Level 1 complete on north/east; started south elevation. Roofing subcontractor site induction and welfare set-up.', 'materials_delivered' => 'Roofing membrane and battens (partial delivery).', 'visitors' => 'Employer\'s Agent — monthly site walk.'],
        ['diary_date' => '2026-05-18', 'weather' => 'Overcast, dry', 'temperature' => 15.5, 'workers_on_site' => 24, 'works_carried_out' => 'Roofing works commenced to Block C main roof, west section. Brickwork progressing to Level 2.', 'materials_delivered' => 'Single-ply roofing membrane, remaining rolls.', 'visitors' => null],
        ['diary_date' => '2026-05-25', 'weather' => 'Clear, warm', 'temperature' => 19.0, 'workers_on_site' => 23, 'works_carried_out' => 'Roofing membrane laying continuing west section. Brickwork Level 2 north elevation.', 'materials_delivered' => 'Facing brick, pallets 7-10.', 'visitors' => null],
        ['diary_date' => '2026-06-01', 'weather' => 'Overcast, occasional showers', 'temperature' => 14.0, 'workers_on_site' => 21, 'works_carried_out' => 'Brickwork paused on wettest morning, resumed afternoon. Roofing continuing east section.', 'materials_delivered' => null, 'visitors' => null],
        ['diary_date' => '2026-06-08', 'weather' => 'Sunny', 'temperature' => 20.5, 'workers_on_site' => 25, 'works_carried_out' => 'Brickwork Level 2 complete all elevations, commenced Level 3. Roofing membrane substantially complete, upstands and flashings ongoing.', 'materials_delivered' => 'Roof flashing and trims.', 'visitors' => 'Building Control — brickwork DPC inspection.'],
        ['diary_date' => '2026-06-15', 'weather' => 'Overcast, dry', 'temperature' => 17.5, 'workers_on_site' => 24, 'works_carried_out' => 'Brickwork Level 3 progressing. Roofing upstands and rooflight kerbs complete.', 'materials_delivered' => null, 'visitors' => null],
        ['diary_date' => '2026-06-22', 'weather' => 'Sunny, hot', 'temperature' => 24.0, 'workers_on_site' => 20, 'works_carried_out' => 'Brickwork Level 3 south elevation. Roofing snagging and pre-completion checks.', 'materials_delivered' => null, 'visitors' => null],
        ['diary_date' => '2026-06-29', 'weather' => 'Sunny, hot', 'temperature' => 26.5, 'workers_on_site' => 19, 'works_carried_out' => 'Facade contractor delivered scaffold access design for sign-off. Brickwork Level 3 nearing completion.', 'materials_delivered' => null, 'visitors' => null],
        ['diary_date' => '2026-07-06', 'weather' => 'Overcast, dry', 'temperature' => 20.0, 'workers_on_site' => 26, 'works_carried_out' => 'Facade & Cladding subcontractor mobilised — site induction, welfare, and scaffold access handover. Brickwork Level 4 commenced.', 'materials_delivered' => 'Facade bracket delivery, first batch.', 'visitors' => null],
        ['diary_date' => '2026-07-13', 'weather' => 'Light rain, dry by midday', 'temperature' => 18.0, 'workers_on_site' => 27, 'works_carried_out' => 'Facade setting-out and bracket fixing trial on east elevation. Brickwork Level 4 continuing.', 'materials_delivered' => null, 'visitors' => 'Employer\'s Agent — facade mobilisation inspection.'],
        ['diary_date' => '2026-07-20', 'weather' => 'Sunny, warm', 'temperature' => 21.5, 'workers_on_site' => 26, 'works_carried_out' => 'Facade bracket fixing commenced east elevation, Levels 1-2. Brickwork Level 4 substantially complete.', 'materials_delivered' => 'Facade panels, first delivery.', 'visitors' => null],
    ];

    public const SNAGS = [
        [
            'title' => 'Minor honeycombing to podium soffit, Grid C3',
            'description' => 'Localised honeycombing to the underside of the podium slab at '
                . 'Grid C3, identified during the frame handover inspection.',
            'category' => 'concrete_frame',
            'priority' => 'low',
            'status' => 'closed',
            'due_date' => '2026-04-24',
        ],
        [
            'title' => 'Damp staining to groundworks retaining wall, north elevation',
            'description' => 'Damp staining observed to the below-ground retaining wall on the '
                . 'north elevation — waterproofing membrane lap to be inspected.',
            'category' => 'groundworks',
            'priority' => 'medium',
            'status' => 'ready_for_review',
            'due_date' => '2026-08-01',
        ],
        [
            'title' => 'Steel connection bolt torque markings missing, Grid E/9-E/14',
            'description' => 'Torque-checked bolt markings absent on several connections along '
                . 'Grid E between lines 9 and 14 — re-inspection and marking required before '
                . 'sign-off.',
            'category' => 'structural_steel',
            'priority' => 'medium',
            'status' => 'open',
            'due_date' => '2026-08-15',
        ],
    ];

    public const QA_REPORTS = [
        [
            'report_number' => 1,
            'title' => 'Groundworks — pre-pour reinforcement inspection',
            'inspection_type' => 'reinforcement',
            'area' => 'Podium transfer slab',
            'inspection_date' => '2026-01-09',
            'status' => 'passed',
            'result' => 'Pass',
            'observations' => 'Reinforcement fixed in accordance with revised drawing STR-104 '
                . 'Rev C. Cover and spacing checked and compliant.',
        ],
        [
            'report_number' => 2,
            'title' => 'Concrete frame — cube test results, Level 3 pour',
            'inspection_type' => 'concrete_testing',
            'area' => 'Level 3 slab',
            'inspection_date' => '2026-03-05',
            'status' => 'passed',
            'result' => 'Pass',
            'observations' => '28-day cube strength results exceed the specified C32/40 design '
                . 'strength across all six test cubes.',
        ],
        [
            'report_number' => 3,
            'title' => 'Structural steel — bolted connection torque check',
            'inspection_type' => 'structural',
            'area' => 'Grid E, Levels 4-6',
            'inspection_date' => '2026-05-08',
            'status' => 'failed',
            'result' => 'Fail — re-inspection required',
            'observations' => 'Several connections along Grid E lacked torque-check markings.',
            'corrective_action' => 'Re-inspection and marking instructed — see related snag; '
                . 're-check scheduled ahead of steel sign-off.',
        ],
        [
            'report_number' => 4,
            'title' => 'Brickwork — DPC and cavity tray inspection, Levels 1-2',
            'inspection_type' => 'brickwork',
            'area' => 'Levels 1-2, all elevations',
            'inspection_date' => '2026-06-11',
            'status' => 'passed',
            'result' => 'Pass',
            'observations' => 'DPC and cavity trays correctly installed and lapped at all '
                . 'inspected openings.',
        ],
    ];

    /**
     * project_users pivot roles — matches the values in the model comment
     * (project_manager, contract_admin, site_manager, member, observer).
     */
    public const PROJECT_TEAM = [
        ['email' => 'megan.fairweather@haldengroveconstruction.com', 'role' => 'project_manager'],
        ['email' => 'daniel.okafor@haldengroveconstruction.com', 'role' => 'contract_admin'],
        ['email' => 'sarah.blythe@haldengroveconstruction.com', 'role' => 'site_manager'],
        ['email' => 'james.ridley@haldengroveconstruction.com', 'role' => 'member'],
        ['email' => 'priya.chandra@haldengroveconstruction.com', 'role' => 'observer'],
    ];

    public const PROJECT_CONTACTS = [
        [
            'name' => 'Claire Whitfield',
            'company' => 'Whitfield & Sutton Chartered Surveyors',
            'role' => 'consultant',
            'email' => 'claire.whitfield@whitfieldsutton.co.uk',
            'phone' => '+44 161 496 2210',
            'is_primary' => true,
        ],
        [
            'name' => 'Robert Ainsley',
            'company' => 'Riverside Wharf Developments LLP',
            'role' => 'client',
            'email' => 'r.ainsley@riversidewharfdevelopments.co.uk',
            'phone' => '+44 161 236 8890',
            'is_primary' => true,
        ],
    ];
}
