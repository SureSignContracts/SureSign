<?php

namespace Database\Seeders\Demo\Data;

/**
 * Authored story for Aldermere Distribution Centre — Phase 1: the
 * "operationally difficult" project in the approved demo portfolio
 * (Phase 4). This project exists specifically to demonstrate SureSign
 * surfacing genuine risk — a live compliance exposure, unresolved RFIs
 * past their window, an escalating open risk, and a disputed variation —
 * while still reading as professionally managed, not chaotic: every
 * problem here has already been logged, notified, or escalated through
 * the platform. Nothing is silently going wrong; it's visibly being
 * tracked.
 *
 * Month 7 of a 14-month, £4.6m JCT Design and Build warehouse contract,
 * commenced 2025-12-15.
 */
class AldermereStory
{
    public const PROJECT = [
        'name' => 'Aldermere Distribution Centre — Phase 1',
        'code' => 'ADC-P1',
        'description' => 'New-build single-storey distribution warehouse with two-storey '
            . 'office pod and external yard, for a third-party logistics operator.',
        'status' => 'active',
        'type' => 'new_build',
        'contract_type' => 'JCT',
        'contract_value' => 4600000.00,
        'currency' => 'GBP',
        'retention_percentage' => 3.00,
        'retention_cap_percentage' => 3.00,
        'payment_terms_days' => 30,
        'start_date' => '2025-12-15',
        'end_date' => '2027-02-15',
        'practical_completion_date' => null,
        'address' => 'Aldermere Distribution Park, Plot 7',
        'city' => 'Wakefield',
        'state' => 'West Yorkshire',
        'postcode' => 'WF3 1LP',
        'country' => 'GB',
    ];

    public const CONTRACT = [
        'type' => 'main_contract',
        'title' => 'Aldermere Distribution Centre — Phase 1: Main Contract',
        'reference_number' => 'HG-ADC-P1-001',
        'form_of_contract' => 'JCT Design and Build Contract',
        'standard_form_edition' => '2016',
        'procurement_route' => 'design_and_build',
        'governing_law' => 'England and Wales',
        'design_responsibility' => 'contractor',
        'party_name' => 'Aldermere Logistics Developments Ltd',
        'employer_name' => 'Aldermere Logistics Developments Ltd',
        'qs_name' => 'Copeland Fenwick Surveyors',
        'principal_designer' => 'Radcliffe Storr Architects',
        'principal_contractor' => 'Halden Grove Construction Ltd.',
        'contract_sum' => 4600000.00,
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
        'execution_date' => '2025-12-01',
        'commencement_date' => '2025-12-15',
        'possession_date' => '2025-12-15',
        'base_date' => '2025-10-01',
        'completion_date' => '2027-02-15',
        'defects_liability_period' => '12 months',
        'defects_liability_period_months' => 12,
        'liquidated_damages' => '£3,200 per week (part weeks pro-rata)',
        'notice_requirements' => 'All notices to be given in writing and served by email with '
            . 'postal confirmation, per clause 1.7 of the JCT Design and Build Conditions.',
        'variation_procedure' => 'Instructed via Architect\'s Instruction or Employer\'s Agent '
            . 'confirmation; contractor to submit a priced quotation within 14 days of instruction.',
        'status' => 'active',
    ];

    public const TRADE_PACKAGES = [
        ['name' => 'Groundworks & Piling', 'code' => 'ADC-01-GP', 'status' => 'completed', 'contractor_name' => 'Pennine Groundworks Ltd.', 'contract_value' => 620000.00, 'award_date' => '2025-12-08', 'commencement_date' => '2025-12-29', 'completion_date' => '2026-03-13'],
        ['name' => 'Steel Portal Frame', 'code' => 'ADC-02-SP', 'status' => 'active', 'contractor_name' => 'Kirkstall Steel Fabrications Ltd.', 'contract_value' => 980000.00, 'award_date' => '2026-02-16', 'commencement_date' => '2026-03-16', 'completion_date' => '2026-08-14'],
        ['name' => 'Cladding & Roofing', 'code' => 'ADC-03-CR', 'status' => 'awarded', 'contractor_name' => 'Summit Roofing Systems Ltd.', 'contract_value' => 890000.00, 'award_date' => '2026-06-29', 'commencement_date' => '2026-08-17', 'completion_date' => '2026-11-06'],
        ['name' => 'Internal Fit-Out (Office Pod)', 'code' => 'ADC-04-FO', 'status' => 'tendering', 'contractor_name' => null, 'contract_value' => 540000.00, 'award_date' => null, 'commencement_date' => '2026-11-16', 'completion_date' => '2027-01-15'],
        ['name' => 'Mechanical & Electrical Services', 'code' => 'ADC-05-ME', 'status' => 'tender_returned', 'contractor_name' => null, 'contract_value' => 710000.00, 'award_date' => null, 'commencement_date' => '2026-09-14', 'completion_date' => '2027-01-22'],
        ['name' => 'External Works & Yard', 'code' => 'ADC-06-EW', 'status' => 'tendering', 'contractor_name' => null, 'contract_value' => 460000.00, 'award_date' => null, 'commencement_date' => '2026-12-07', 'completion_date' => '2027-02-08'],
    ];

    /**
     * "Steel Portal Frame Complete" is shown genuinely slipped — planned
     * vs. forecast disagree by 3 weeks — this is the visible programme
     * slippage the project's strain traces back to.
     */
    public const PROGRAMME_MILESTONES = [
        ['name' => 'Site Possession & Mobilisation', 'milestone_type' => 'commencement', 'planned_date' => '2025-12-15', 'forecast_date' => '2025-12-15', 'actual_date' => '2025-12-15', 'status' => 'complete'],
        ['name' => 'Groundworks & Piling Complete', 'milestone_type' => 'obligation', 'planned_date' => '2026-02-20', 'forecast_date' => '2026-03-13', 'actual_date' => '2026-03-13', 'status' => 'complete'],
        ['name' => 'Steel Portal Frame Complete', 'milestone_type' => 'obligation', 'planned_date' => '2026-07-24', 'forecast_date' => '2026-08-14', 'actual_date' => null, 'status' => 'delayed'],
        ['name' => 'Building Watertight', 'milestone_type' => 'obligation', 'planned_date' => '2026-10-16', 'forecast_date' => '2026-11-06', 'actual_date' => null, 'status' => 'at_risk'],
        ['name' => 'Fit-Out & M&E Complete', 'milestone_type' => 'obligation', 'planned_date' => '2027-01-08', 'forecast_date' => '2027-01-22', 'actual_date' => null, 'status' => 'not_started'],
        ['name' => 'Practical Completion', 'milestone_type' => 'completion', 'planned_date' => '2027-02-15', 'forecast_date' => '2027-03-08', 'actual_date' => null, 'status' => 'not_started'],
    ];

    /**
     * Seven monthly applications. The seventh is genuinely overdue: its
     * final_date_for_payment has already passed relative to "today"
     * (2026-07-22) and no Pay Less Notice has been issued — a live
     * compliance exposure, not a resolved one, which is exactly what this
     * project exists to demonstrate.
     */
    public const PAYMENT_APPLICATIONS = [
        ['application_number' => 1, 'application_date' => '2026-01-05', 'valuation_period_start' => '2025-12-15', 'valuation_period_end' => '2025-12-31', 'gross_valuation' => 340000.00, 'status' => 'paid', 'certified_amount' => 329800.00, 'notes' => 'Mobilisation and early piling.'],
        ['application_number' => 2, 'application_date' => '2026-02-05', 'valuation_period_start' => '2026-01-01', 'valuation_period_end' => '2026-01-31', 'gross_valuation' => 610000.00, 'status' => 'paid', 'certified_amount' => 591700.00, 'notes' => 'Piling progressing; productivity below planned rate — see related risk.'],
        ['application_number' => 3, 'application_date' => '2026-03-05', 'valuation_period_start' => '2026-02-01', 'valuation_period_end' => '2026-02-28', 'gross_valuation' => 890000.00, 'status' => 'paid', 'certified_amount' => 863300.00, 'notes' => 'Groundworks and piling substantially complete.'],
        ['application_number' => 4, 'application_date' => '2026-04-06', 'valuation_period_start' => '2026-03-01', 'valuation_period_end' => '2026-03-31', 'gross_valuation' => 1340000.00, 'status' => 'paid', 'certified_amount' => 1299800.00, 'notes' => 'Steel portal frame erection commenced.'],
        ['application_number' => 5, 'application_date' => '2026-05-05', 'valuation_period_start' => '2026-04-01', 'valuation_period_end' => '2026-04-30', 'gross_valuation' => 1780000.00, 'status' => 'paid', 'certified_amount' => 1726600.00, 'notes' => 'Steel frame progressing; delay event notified during this period — see related delay event.'],
        ['application_number' => 6, 'application_date' => '2026-06-05', 'valuation_period_start' => '2026-05-01', 'valuation_period_end' => '2026-05-31', 'gross_valuation' => 2150000.00, 'status' => 'paid', 'certified_amount' => 2085500.00, 'notes' => 'Steel frame continuing behind revised programme; EOT request submitted.'],
        ['application_number' => 7, 'application_date' => '2026-06-08', 'valuation_period_start' => '2026-06-01', 'valuation_period_end' => '2026-06-30', 'gross_valuation' => 2480000.00, 'status' => 'certified', 'certified_amount' => 2405600.00, 'notes' => 'Steel frame nearing completion. Certified amount remains unpaid past the final date for payment — no Pay Less Notice has been issued; formally overdue and flagged for immediate follow-up.'],
    ];

    public const VARIATIONS = [
        [
            'variation_number' => 1,
            'title' => 'Additional yard paving to accommodate revised vehicle turning circle',
            'type' => 'addition',
            'description' => 'The logistics operator\'s revised fleet specification requires a '
                . 'wider vehicle turning circle than originally designed, requiring additional '
                . 'yard paving beyond the original external works scope. Halden Grove\'s '
                . 'subcontractor quotation has been queried by Copeland Fenwick Surveyors as '
                . 'overstated relative to the additional paving area — not yet agreed.',
            'instruction_date' => '2026-07-01',
            'quoted_amount' => 34800.00,
            'agreed_amount' => null,
            'variation_date' => null,
            'status' => 'quoted',
            'programme_impact_days' => 0,
        ],
    ];

    public const RISKS = [
        [
            'title' => 'Piling contractor productivity below planned rate',
            'description' => 'Pennine Groundworks Ltd.\'s piling productivity ran consistently '
                . 'below the planned rate through February and March, contributing to the '
                . 'programme slippage now affecting the steel portal frame package. The issue '
                . 'has continued to a lesser degree on subsequent packages\' groundworks-adjacent '
                . 'works and remains under active review with the subcontractor.',
            'severity' => 'high',
            'probability' => 'high',
            'category' => 'programme',
            'urgency' => 'act_now',
            'mitigation' => 'Weekly productivity review meetings instituted with the '
                . 'subcontractor; additional plant mobilised in April. Productivity has improved '
                . 'but the resulting delay has not been fully recovered — see the related delay '
                . 'event and EOT request.',
            'status' => 'open',
            'trade_package_code' => 'ADC-01-GP',
        ],
        [
            'title' => 'Cladding subcontractor resourcing ahead of Q4 demand',
            'description' => 'Summit Roofing Systems Ltd. has indicated resourcing pressure '
                . 'ahead of its Aldermere mobilisation, given seasonal demand elsewhere in its '
                . 'order book.',
            'severity' => 'medium',
            'probability' => 'medium',
            'category' => 'resource',
            'urgency' => 'monitor',
            'mitigation' => 'Resourcing confirmed in writing for the Aldermere mobilisation date; '
                . 'to be reconfirmed one month ahead of start.',
            'status' => 'open',
            'trade_package_code' => 'ADC-03-CR',
        ],
        [
            'title' => 'Yard paving specification dispute (Variation 1)',
            'description' => 'The Employer\'s quantity surveyor disputes the valuation of the '
                . 'additional yard paving required by the revised vehicle turning circle.',
            'severity' => 'medium',
            'probability' => 'medium',
            'category' => 'commercial',
            'urgency' => 'monitor',
            'mitigation' => 'Detailed measured survey submitted in support of the quotation; '
                . 'awaiting the Employer\'s Agent\'s response.',
            'status' => 'open',
            'trade_package_code' => null,
        ],
    ];

    public const RFIS = [
        [
            'rfi_number' => 1,
            'subject' => 'Steel connection detail at grid line E/12 — clash with services zone',
            'description' => 'The steel connection detail at grid line E/12 appears to clash '
                . 'with the mechanical riser zone shown on the M&E coordination drawing.',
            'priority' => 'high',
            'status' => 'pending_response',
            'raised_date' => '2026-06-24',
            'response_due_date' => '2026-07-01',
            'responded_at' => null,
            'response' => null,
        ],
        [
            'rfi_number' => 2,
            'subject' => 'Drainage fall levels to warehouse floor slab',
            'description' => 'Requesting confirmation of finished fall levels to the warehouse '
                . 'floor slab drainage, which appear shallower than the minimum fall shown on the '
                . 'civil engineer\'s drawing.',
            'priority' => 'high',
            'status' => 'open',
            'raised_date' => '2026-07-03',
            'response_due_date' => '2026-07-10',
            'responded_at' => null,
            'response' => null,
        ],
        [
            'rfi_number' => 3,
            'subject' => 'Office pod first floor structural opening size',
            'description' => 'Requesting confirmation of the structural opening size for the '
                . 'office pod first floor stair, which differs between the architectural and '
                . 'structural drawings.',
            'priority' => 'normal',
            'status' => 'closed',
            'raised_date' => '2026-04-14',
            'response_due_date' => '2026-04-21',
            'responded_at' => '2026-04-18',
            'response' => 'Opening size confirmed per structural drawing STR-ADC-042 Rev B — '
                . 'architectural drawing to be updated to match.',
        ],
    ];

    public const DELAY_EVENT = [
        'title' => 'Piling productivity shortfall affecting steel frame start',
        'description' => 'Sustained below-planned piling productivity through February and '
            . 'March delayed the handover of a fully piled platform to the steel frame '
            . 'subcontractor, pushing back its planned start and knock-on completion date.',
        'cause_category' => 'other',
        'date_occurred' => '2026-03-02',
        'date_notified' => '2026-04-14',
        'notified_by' => 'Megan Fairweather (Project Manager)',
        'estimated_delay_days' => 21,
        'status' => 'under_assessment',
    ];

    public const EOT_REQUEST = [
        'eot_number' => 1,
        'title' => 'EOT — piling productivity shortfall affecting steel frame start',
        'grounds' => 'Clause 2.26 — delay caused by circumstances beyond the contractor\'s '
            . 'control affecting the groundworks subcontractor\'s productivity; supporting '
            . 'productivity records and site diaries submitted.',
        'days_claimed' => 21,
        'notice_date' => '2026-05-01',
        'status' => 'under_review',
    ];

    /**
     * Deliberately overdue: notice_date is over 11 weeks before "today"
     * with no decision recorded — this, alongside the unpaid Application 7
     * and the two open past-due RFIs, is one of the concrete things that
     * should surface as "requires attention" once notifications/upcoming
     * actions are wired up to read from seeded data rather than being
     * hand-authored (see config/demo.php's notifications: false).
     */
    public const MEETINGS = [
        ['meeting_number' => 1, 'meeting_date' => '2026-01-08', 'title' => 'Progress Meeting 1 — Mobilisation', 'type' => 'progress'],
        ['meeting_number' => 2, 'meeting_date' => '2026-02-12', 'title' => 'Progress Meeting 2 — Piling Progress', 'type' => 'progress'],
        ['meeting_number' => 3, 'meeting_date' => '2026-04-16', 'title' => 'Progress Meeting 3 — Delay Event & Recovery Planning', 'type' => 'progress'],
        ['meeting_number' => 4, 'meeting_date' => '2026-05-21', 'title' => 'Progress Meeting 4 — Steel Frame & EOT Submission', 'type' => 'progress'],
        ['meeting_number' => 5, 'meeting_date' => '2026-06-18', 'title' => 'Progress Meeting 5 — Recovery Measures Review', 'type' => 'progress'],
        ['meeting_number' => 6, 'meeting_date' => '2026-07-02', 'title' => 'Progress Meeting 6 — Recovery Programme & Commercial Update', 'type' => 'progress'],
    ];

    /** The most recent meeting explicitly discusses recovery — the project is managed, not adrift. */
    public const RECOVERY_MEETING_MINUTES = 'Recovery measures reviewed: additional plant now on '
        . 'site since April has partially recovered lost time on the steel frame package; '
        . 'forecast completion has moved from 12 to 3 weeks behind the original programme and '
        . 'continues to trend in the right direction. Contractor to provide an updated recovery '
        . 'programme within 5 working days. Payment Application 7 discussed — Employer\'s Agent '
        . 'confirmed certification but payment remains outstanding past the final date for '
        . 'payment; Halden Grove to issue formal written notice reserving its right to suspend '
        . 'performance if payment is not received within 7 days. RFIs 1 and 2 remain open past '
        . 'their response window and were escalated verbally in this meeting in addition to the '
        . 'written register.';

    public const SITE_DIARIES = [
        ['diary_date' => '2026-06-29', 'weather' => 'Overcast, dry', 'temperature' => 19.0, 'workers_on_site' => 22, 'works_carried_out' => 'Steel frame erection continuing, Bay 4-6. Yard paving survey for Variation 1 quotation.', 'materials_delivered' => 'Steel purlins, final delivery.', 'visitors' => 'Employer\'s Agent — monthly site walk.'],
        ['diary_date' => '2026-07-06', 'weather' => 'Sunny, warm', 'temperature' => 22.5, 'workers_on_site' => 24, 'works_carried_out' => 'Steel frame erection Bay 6-8. Cladding subcontractor pre-mobilisation site visit.', 'materials_delivered' => null, 'visitors' => null],
        ['diary_date' => '2026-07-13', 'weather' => 'Overcast, occasional showers', 'temperature' => 17.5, 'workers_on_site' => 23, 'works_carried_out' => 'Steel frame erection substantially complete, final bracing works ongoing.', 'materials_delivered' => null, 'visitors' => null],
        ['diary_date' => '2026-07-20', 'weather' => 'Sunny', 'temperature' => 21.0, 'workers_on_site' => 20, 'works_carried_out' => 'Steel frame snagging and bolt torque checks ahead of hand-over to cladding package.', 'materials_delivered' => null, 'visitors' => null],
    ];

    public const PROJECT_TEAM = [
        ['email' => 'megan.fairweather@haldengroveconstruction.com', 'role' => 'project_manager'],
        ['email' => 'daniel.okafor@haldengroveconstruction.com', 'role' => 'contract_admin'],
        ['email' => 'sarah.blythe@haldengroveconstruction.com', 'role' => 'site_manager'],
        ['email' => 'james.ridley@haldengroveconstruction.com', 'role' => 'member'],
    ];

    public const PROJECT_CONTACTS = [
        ['name' => 'Rosalind Copeland', 'company' => 'Copeland Fenwick Surveyors', 'role' => 'consultant', 'email' => 'r.copeland@copelandfenwick.co.uk', 'phone' => '+44 113 234 5678', 'is_primary' => true],
        ['name' => 'Gareth Aldous', 'company' => 'Aldermere Logistics Developments Ltd', 'role' => 'client', 'email' => 'g.aldous@aldermerelogistics.co.uk', 'phone' => '+44 113 234 1290', 'is_primary' => true],
    ];
}
