<?php

namespace Database\Seeders\Demo\Data;

/**
 * Authored story for Elmsworth Care Home Extension: the "pre-construction"
 * project in the approved demo portfolio (Phase 4). Contract executed
 * 2026-07-08; site commencement is planned for 2026-08-12 — three weeks
 * after the demo's "today" of 2026-07-22. No payment cycle exists yet
 * because none is due: trade packages are still in procurement, the
 * programme holds only planned dates, and the Contract AI Analysis is
 * still processing (not confirmed) — a genuine mid-flight state, distinct
 * from every other project's already-confirmed analysis.
 */
class ElmsworthStory
{
    public const PROJECT = [
        'name' => 'Elmsworth Care Home Extension',
        'code' => 'ECH-EXT',
        'description' => 'Single-storey extension to Elmsworth Care Home providing 12 '
            . 'additional en-suite bedrooms and an enlarged communal lounge, built while the '
            . 'existing home remains fully operational.',
        'status' => 'active',
        'type' => 'refurbishment',
        'contract_type' => 'JCT',
        'contract_value' => 2100000.00,
        'currency' => 'GBP',
        'retention_percentage' => 5.00,
        'retention_cap_percentage' => 5.00,
        'payment_terms_days' => 30,
        'start_date' => '2026-08-12',
        'end_date' => '2027-05-12',
        'practical_completion_date' => null,
        'address' => 'Elmsworth Care Home, Meadowcroft Lane',
        'city' => 'Shrewsbury',
        'state' => 'Shropshire',
        'postcode' => 'SY2 6LR',
        'country' => 'GB',
    ];

    public const CONTRACT = [
        'type' => 'main_contract',
        'title' => 'Elmsworth Care Home Extension: Main Contract',
        'reference_number' => 'HG-ECH-EXT-001',
        'form_of_contract' => 'JCT Intermediate Building Contract',
        'standard_form_edition' => '2016',
        'procurement_route' => 'traditional',
        'governing_law' => 'England and Wales',
        'design_responsibility' => 'employer',
        'party_name' => 'Elmsworth Care Group Ltd',
        'employer_name' => 'Elmsworth Care Group Ltd',
        'qs_name' => 'Tresham Ostler Surveyors',
        'principal_designer' => 'Bellcourt Design Associates',
        'principal_contractor' => 'Halden Grove Construction Ltd.',
        'contract_sum' => 2100000.00,
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
        'execution_date' => '2026-07-08',
        'commencement_date' => '2026-08-12',
        'possession_date' => '2026-08-12',
        'base_date' => '2026-05-15',
        'completion_date' => '2027-05-12',
        'defects_liability_period' => '6 months',
        'defects_liability_period_months' => 6,
        'liquidated_damages' => '£1,500 per week (part weeks pro-rata)',
        'notice_requirements' => 'All notices to be given in writing and served by email with '
            . 'postal confirmation, per clause 1.7 of the JCT Intermediate Conditions.',
        'variation_procedure' => 'Instructed via Architect\'s Instruction; contractor to submit '
            . 'a priced quotation within 10 days of instruction.',
        'status' => 'active',
    ];

    /**
     * Deliberately mid-flight — status stays 'processing' with no
     * confirmed_data_json, unlike every other project's contract analysis.
     * The point is to demonstrate what the AI Analysis screen looks like
     * before an admin has confirmed the extraction, not just after.
     */
    public const CONTRACT_AI_ANALYSIS = [
        'provider' => 'anthropic',
        'model' => 'claude-sonnet-4-5',
        'status' => 'processing',
        'started_at' => '2026-07-20 14:32:00',
    ];

    /** All still in procurement — none awarded, no commencement dates yet. */
    public const TRADE_PACKAGES = [
        ['name' => 'Groundworks', 'code' => 'ECH-01-GW', 'status' => 'tender_returned', 'contractor_name' => null, 'contract_value' => 240000.00],
        ['name' => 'Structural Extension Frame', 'code' => 'ECH-02-SF', 'status' => 'under_review', 'contractor_name' => null, 'contract_value' => 480000.00],
        ['name' => 'Mechanical & Electrical Services', 'code' => 'ECH-03-ME', 'status' => 'tendering', 'contractor_name' => null, 'contract_value' => 390000.00],
        ['name' => 'Internal Fit-Out', 'code' => 'ECH-04-FO', 'status' => 'tendering', 'contractor_name' => null, 'contract_value' => 520000.00],
    ];

    /** Every milestone is a plan, not yet a fact — no forecast/actual dates. */
    public const PROGRAMME_MILESTONES = [
        ['name' => 'Site Possession & Mobilisation', 'milestone_type' => 'commencement', 'planned_date' => '2026-08-12', 'status' => 'not_started'],
        ['name' => 'Groundworks & Foundations Complete', 'milestone_type' => 'obligation', 'planned_date' => '2026-10-09', 'status' => 'not_started'],
        ['name' => 'Structural Frame Complete', 'milestone_type' => 'obligation', 'planned_date' => '2026-12-04', 'status' => 'not_started'],
        ['name' => 'Building Watertight', 'milestone_type' => 'obligation', 'planned_date' => '2027-01-29', 'status' => 'not_started'],
        ['name' => 'Fit-Out & M&E Complete', 'milestone_type' => 'obligation', 'planned_date' => '2027-04-16', 'status' => 'not_started'],
        ['name' => 'Practical Completion', 'milestone_type' => 'completion', 'planned_date' => '2027-05-12', 'status' => 'not_started'],
    ];

    /**
     * Pre-construction coordination, not site progress meetings — design
     * team review and a tender review, both before commencement.
     */
    public const MEETINGS = [
        ['meeting_number' => 1, 'meeting_date' => '2026-06-16', 'title' => 'Design Team Meeting — Infection Control & Phasing Review', 'type' => 'design'],
        ['meeting_number' => 2, 'meeting_date' => '2026-07-14', 'title' => 'Tender Review Meeting — Groundworks & Structural Frame', 'type' => 'commercial'],
        ['meeting_number' => 3, 'meeting_date' => '2026-07-21', 'title' => 'Pre-Start Meeting — Mobilisation Planning', 'type' => 'commercial'],
    ];

    public const APPOINTMENTS = [
        [
            'reference' => 'APT-DEMO-ECH-001',
            'type_slug' => 'account-review',
            'attendee_email' => 'priya.chandra@haldengroveconstruction.com',
            'starts_at' => '2026-07-08 10:00:00',
            'ends_at' => '2026-07-08 10:30:00',
            'status' => 'completed',
            'completion_notes' => 'Contract execution review — commercial set-up in SureSign confirmed ahead of mobilisation.',
        ],
        [
            'reference' => 'APT-DEMO-ECH-002',
            'type_slug' => 'support-consultation',
            'attendee_email' => 'james.ridley@haldengroveconstruction.com',
            'starts_at' => '2026-08-05 11:00:00',
            'ends_at' => '2026-08-05 11:30:00',
            'status' => 'confirmed',
        ],
    ];

    public const DOCUMENTS = [
        ['title' => 'Elmsworth Care Home Extension: Main Contract (Executed)', 'type' => 'contract', 'category' => 'Contracts', 'reference_number' => 'HG-ECH-EXT-001', 'status' => 'approved', 'documentable' => 'contract'],
        ['title' => 'Infection Control Plan — Live Care Home Environment', 'type' => 'other', 'category' => 'Specifications', 'reference_number' => 'ICP-ECH-EXT', 'status' => 'approved', 'documentable' => null],
        ['title' => 'Groundworks & Structural Frame — Tender Documents', 'type' => 'other', 'category' => 'Commercial Documents', 'reference_number' => 'TENDER-ECH-EXT-01', 'status' => 'issued', 'documentable' => null],
    ];

    public const PROJECT_TEAM = [
        ['email' => 'priya.chandra@haldengroveconstruction.com', 'role' => 'project_manager'],
        ['email' => 'daniel.okafor@haldengroveconstruction.com', 'role' => 'contract_admin'],
        ['email' => 'james.ridley@haldengroveconstruction.com', 'role' => 'member'],
    ];

    public const PROJECT_CONTACTS = [
        ['name' => 'Felicity Tresham', 'company' => 'Tresham Ostler Surveyors', 'role' => 'consultant', 'email' => 'f.tresham@treshamostler.co.uk', 'phone' => '+44 1743 288 410', 'is_primary' => true],
        ['name' => 'Anthony Merrow', 'company' => 'Elmsworth Care Group Ltd', 'role' => 'client', 'email' => 'a.merrow@elmsworthcaregroup.co.uk', 'phone' => '+44 1743 288 902', 'is_primary' => true],
    ];
}
