<?php

namespace Database\Seeders\Demo\Data;

/**
 * Authored story for Northgate Business Units — Phase 2: the "early
 * construction" project in the approved demo portfolio (Phase 4). Month 2
 * of a 12-month, £3.8m JCT Design and Build contract, commenced
 * 2026-05-18 — first payment cycle just submitted, most of the programme
 * still ahead. Demonstrates active contract administration from the very
 * start of a build, not a scenario transplanted mid-programme.
 */
class NorthgateStory
{
    public const PROJECT = [
        'name' => 'Northgate Business Units — Phase 2',
        'code' => 'NBU-P2',
        'description' => 'New-build terrace of six light industrial/business units with '
            . 'shared yard and parking, forming phase two of the Northgate Business Park.',
        'status' => 'active',
        'type' => 'new_build',
        'contract_type' => 'JCT',
        'contract_value' => 3800000.00,
        'currency' => 'GBP',
        'retention_percentage' => 3.00,
        'retention_cap_percentage' => 3.00,
        'payment_terms_days' => 30,
        'start_date' => '2026-05-18',
        'end_date' => '2027-05-18',
        'practical_completion_date' => null,
        'address' => 'Northgate Business Park, Unit A-F, Enterprise Way',
        'city' => 'Leeds',
        'state' => 'West Yorkshire',
        'postcode' => 'LS9 8DA',
        'country' => 'GB',
    ];

    public const CONTRACT = [
        'type' => 'main_contract',
        'title' => 'Northgate Business Units — Phase 2: Main Contract',
        'reference_number' => 'HG-NBU-P2-001',
        'form_of_contract' => 'JCT Design and Build Contract',
        'standard_form_edition' => '2016',
        'procurement_route' => 'design_and_build',
        'governing_law' => 'England and Wales',
        'design_responsibility' => 'contractor',
        'party_name' => 'Northgate Business Park Developments Ltd',
        'employer_name' => 'Northgate Business Park Developments Ltd',
        'qs_name' => 'Ashfield Rowe Chartered Surveyors',
        'principal_designer' => 'Marlow Bennett Architects',
        'principal_contractor' => 'Halden Grove Construction Ltd.',
        'contract_sum' => 3800000.00,
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
        'execution_date' => '2026-05-04',
        'commencement_date' => '2026-05-18',
        'possession_date' => '2026-05-18',
        'base_date' => '2026-03-01',
        'completion_date' => '2027-05-18',
        'defects_liability_period' => '12 months',
        'defects_liability_period_months' => 12,
        'liquidated_damages' => '£2,800 per week (part weeks pro-rata)',
        'notice_requirements' => 'All notices to be given in writing and served by email with '
            . 'postal confirmation, per clause 1.7 of the JCT Design and Build Conditions.',
        'variation_procedure' => 'Instructed via Architect\'s Instruction or Employer\'s Agent '
            . 'confirmation; contractor to submit a priced quotation within 14 days of instruction.',
        'status' => 'active',
    ];

    public const TRADE_PACKAGES = [
        ['name' => 'Groundworks', 'code' => 'NBU-01-GW', 'status' => 'active', 'contractor_name' => 'Pennine Groundworks Ltd.', 'contract_value' => 510000.00, 'award_date' => '2026-05-11', 'commencement_date' => '2026-06-01', 'completion_date' => '2026-08-14'],
        ['name' => 'Steel Portal Frame', 'code' => 'NBU-02-SP', 'status' => 'awarded', 'contractor_name' => 'Kirkstall Steel Fabrications Ltd.', 'contract_value' => 720000.00, 'award_date' => '2026-07-06', 'commencement_date' => '2026-08-24', 'completion_date' => '2026-11-13'],
        ['name' => 'Cladding & Roofing', 'code' => 'NBU-03-CR', 'status' => 'tendering', 'contractor_name' => null, 'contract_value' => 650000.00, 'award_date' => null, 'commencement_date' => '2026-11-23', 'completion_date' => '2027-02-05'],
        ['name' => 'Mechanical & Electrical Services', 'code' => 'NBU-04-ME', 'status' => 'tendering', 'contractor_name' => null, 'contract_value' => 480000.00, 'award_date' => null, 'commencement_date' => '2026-12-14', 'completion_date' => '2027-03-19'],
        ['name' => 'External Works & Yard', 'code' => 'NBU-05-EW', 'status' => 'tendering', 'contractor_name' => null, 'contract_value' => 340000.00, 'award_date' => null, 'commencement_date' => '2027-03-01', 'completion_date' => '2027-05-07'],
    ];

    public const PROGRAMME_MILESTONES = [
        ['name' => 'Site Possession & Mobilisation', 'milestone_type' => 'commencement', 'planned_date' => '2026-05-18', 'actual_date' => '2026-05-18', 'status' => 'complete'],
        ['name' => 'Groundworks Complete', 'milestone_type' => 'obligation', 'planned_date' => '2026-08-14', 'actual_date' => null, 'status' => 'in_progress'],
        ['name' => 'Steel Portal Frame Complete', 'milestone_type' => 'obligation', 'planned_date' => '2026-11-13', 'actual_date' => null, 'status' => 'not_started'],
        ['name' => 'Building Watertight', 'milestone_type' => 'obligation', 'planned_date' => '2027-02-05', 'actual_date' => null, 'status' => 'not_started'],
        ['name' => 'Practical Completion', 'milestone_type' => 'completion', 'planned_date' => '2027-05-18', 'actual_date' => null, 'status' => 'not_started'],
    ];

    /** First payment cycle only — still under assessment, nothing certified yet. */
    public const PAYMENT_APPLICATIONS = [
        [
            'application_number' => 1,
            'application_date' => '2026-07-05',
            'valuation_period_start' => '2026-05-18',
            'valuation_period_end' => '2026-06-30',
            'gross_valuation' => 210000.00,
            'status' => 'submitted',
            'notes' => 'First interim valuation — site set-up, hoarding, and groundworks mobilisation. Under assessment by the Employer\'s Agent.',
        ],
    ];

    public const RISKS = [
        [
            'title' => 'Shared site access coordination with adjacent Phase 1 occupiers',
            'description' => 'Site access for groundworks plant and deliveries must be '
                . 'coordinated with existing tenants occupying the completed Phase 1 units '
                . 'immediately adjacent to the site.',
            'severity' => 'medium',
            'probability' => 'medium',
            'category' => 'access',
            'urgency' => 'monitor',
            'mitigation' => 'Delivery schedule agreed with the Phase 1 site manager; a '
                . 'dedicated access route sign-posted to avoid tenant car parking.',
            'status' => 'open',
        ],
        [
            'title' => 'Groundworks programme sensitive to summer weather',
            'description' => 'The groundworks package sits within a summer weather window '
                . 'currently favourable, but any sustained wet weather would carry limited float '
                . 'this early in the programme.',
            'severity' => 'low',
            'probability' => 'low',
            'category' => 'weather',
            'urgency' => 'monitor',
            'mitigation' => 'Programme being tracked weekly against forecast; no action needed '
                . 'while conditions remain favourable.',
            'status' => 'open',
        ],
    ];

    public const RFIS = [
        [
            'rfi_number' => 1,
            'subject' => 'Confirmation of shared yard drainage connection point',
            'description' => 'Requesting confirmation of the connection point for the shared '
                . 'yard drainage into the existing Phase 1 attenuation system.',
            'priority' => 'high',
            'status' => 'closed',
            'raised_date' => '2026-06-05',
            'response_due_date' => '2026-06-12',
            'responded_at' => '2026-06-10',
            'response' => 'Connection point confirmed at the existing Phase 1 manhole MH-14, '
                . 'subject to a capacity check by the civil engineer — check completed, no issue.',
        ],
        [
            'rfi_number' => 2,
            'subject' => 'Ground bearing capacity query, Unit D footprint',
            'description' => 'Trial pit results under the Unit D footprint show slightly lower '
                . 'bearing capacity than assumed at tender — requesting confirmation of the '
                . 'foundation design response.',
            'priority' => 'high',
            'status' => 'pending_response',
            'raised_date' => '2026-07-15',
            'response_due_date' => '2026-07-22',
            'responded_at' => null,
            'response' => null,
        ],
    ];

    public const MEETINGS = [
        ['meeting_number' => 1, 'meeting_date' => '2026-05-27', 'title' => 'Progress Meeting 1 — Mobilisation', 'type' => 'progress'],
        ['meeting_number' => 2, 'meeting_date' => '2026-06-24', 'title' => 'Progress Meeting 2 — Groundworks Set-Up', 'type' => 'progress'],
        ['meeting_number' => 3, 'meeting_date' => '2026-07-15', 'title' => 'Progress Meeting 3 — First Payment Application & Steel Award', 'type' => 'progress'],
    ];

    public const SITE_DIARIES = [
        ['diary_date' => '2026-07-06', 'weather' => 'Sunny, warm', 'temperature' => 22.0, 'workers_on_site' => 10, 'works_carried_out' => 'Site hoarding and welfare set-up complete. Groundworks plant mobilised.', 'materials_delivered' => 'Site hoarding panels.', 'visitors' => 'Employer\'s Agent — mobilisation site walk.'],
        ['diary_date' => '2026-07-13', 'weather' => 'Overcast, dry', 'temperature' => 19.5, 'workers_on_site' => 14, 'works_carried_out' => 'Topsoil strip commenced across Units A-C footprint.', 'materials_delivered' => null, 'visitors' => null],
        ['diary_date' => '2026-07-20', 'weather' => 'Sunny', 'temperature' => 21.5, 'workers_on_site' => 16, 'works_carried_out' => 'Topsoil strip complete; trial pits excavated at Unit D following RFI 2.', 'materials_delivered' => null, 'visitors' => null],
    ];

    public const PROJECT_TEAM = [
        ['email' => 'daniel.okafor@haldengroveconstruction.com', 'role' => 'project_manager'],
        ['email' => 'sarah.blythe@haldengroveconstruction.com', 'role' => 'site_manager'],
        ['email' => 'james.ridley@haldengroveconstruction.com', 'role' => 'member'],
    ];

    public const PROJECT_CONTACTS = [
        ['name' => 'Imogen Ashfield', 'company' => 'Ashfield Rowe Chartered Surveyors', 'role' => 'consultant', 'email' => 'i.ashfield@ashfieldrowe.co.uk', 'phone' => '+44 113 345 6721', 'is_primary' => true],
        ['name' => 'Patrick Vance', 'company' => 'Northgate Business Park Developments Ltd', 'role' => 'client', 'email' => 'p.vance@northgatebusinesspark.co.uk', 'phone' => '+44 113 345 0087', 'is_primary' => true],
    ];
}
