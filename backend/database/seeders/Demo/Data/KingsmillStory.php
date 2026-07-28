<?php

namespace Database\Seeders\Demo\Data;

/**
 * Authored story for Kingsmill Logistics Hub: the "recently awarded"
 * project in the approved demo portfolio (Phase 4) — the very start of a
 * project's life in SureSign. The contract has been drafted following
 * award but is not yet signed; no trade packages, programme, or
 * commercial activity exist because none is genuine yet. This is a
 * deliberately minimal, not padded-out, empty state — the point is to
 * show SureSign looks professional and complete even before work begins,
 * not to manufacture activity that hasn't happened.
 */
class KingsmillStory
{
    public const PROJECT = [
        'name' => 'Kingsmill Logistics Hub',
        'code' => 'KLH-01',
        'description' => 'New-build regional logistics hub comprising a main distribution '
            . 'warehouse, ancillary office accommodation, and HGV yard — awarded following '
            . 'competitive tender, contract currently in execution.',
        'status' => 'on_hold',
        'type' => 'new_build',
        'contract_type' => 'JCT',
        'contract_value' => 5400000.00,
        'currency' => 'GBP',
        'retention_percentage' => 3.00,
        'retention_cap_percentage' => 3.00,
        'payment_terms_days' => 30,
        'start_date' => '2026-09-15',
        'end_date' => null,
        'practical_completion_date' => null,
        'address' => 'Kingsmill Logistics Park, Plot 3',
        'city' => 'Northampton',
        'state' => 'Northamptonshire',
        'postcode' => 'NN4 8ZX',
        'country' => 'GB',
    ];

    /**
     * status stays 'draft' and execution_date stays null throughout this
     * phase — the whole point of this project is "awarded, not yet
     * signed." completion_date is set as the tendered programme duration
     * from the anticipated (not yet contractual) commencement date.
     */
    public const CONTRACT = [
        'type' => 'main_contract',
        'title' => 'Kingsmill Logistics Hub: Main Contract',
        'reference_number' => 'HG-KLH-01-001',
        'form_of_contract' => 'JCT Design and Build Contract',
        'standard_form_edition' => '2016',
        'procurement_route' => 'design_and_build',
        'governing_law' => 'England and Wales',
        'design_responsibility' => 'contractor',
        'party_name' => 'Kingsmill Logistics Developments Ltd',
        'employer_name' => 'Kingsmill Logistics Developments Ltd',
        'qs_name' => 'Harcourt Pemberton Surveyors',
        'principal_designer' => 'Radcliffe Storr Architects',
        'principal_contractor' => 'Halden Grove Construction Ltd.',
        'contract_sum' => 5400000.00,
        'currency' => 'GBP',
        'retention_percentage' => 3.00,
        'retention_cap_percentage' => 3.00,
        'retention_half1_release' => 'Practical Completion',
        'retention_half2_release' => 'End of Defects Liability Period',
        'payment_terms_days' => 30,
        'payment_frequency' => 'monthly',
        'due_date_offset_days' => 17,
        'final_date_offset_days' => 31,
        'payment_notice_offset_days' => 5,
        'pay_less_notice_offset_days' => 24,
        'manual_date_override_allowed' => true,
        'valuation_method' => 'interim_valuation',
        'vat_reverse_charge' => true,
        'performance_bond_required' => true,
        'execution_date' => null,
        'commencement_date' => '2026-09-15',
        'completion_date' => '2027-11-15',
        'defects_liability_period' => '12 months',
        'defects_liability_period_months' => 12,
        'liquidated_damages' => '£3,800 per week (part weeks pro-rata)',
        'notice_requirements' => 'All notices to be given in writing and served by email with '
            . 'postal confirmation, per clause 1.7 of the JCT Design and Build Conditions.',
        'variation_procedure' => 'Instructed via Architect\'s Instruction or Employer\'s Agent '
            . 'confirmation; contractor to submit a priced quotation within 14 days of instruction.',
        'status' => 'draft',
        'notes' => 'Contract drafted following award of the tender on 2026-06-24. Awaiting '
            . 'countersignature from Kingsmill Logistics Developments Ltd before execution.',
    ];

    public const MEETINGS = [
        [
            'meeting_number' => 1,
            'meeting_date' => '2026-07-10',
            'title' => 'Contract Award & Negotiation Meeting',
            'type' => 'commercial',
        ],
    ];

    public const DOCUMENTS = [
        ['title' => 'Kingsmill Logistics Hub: Draft Main Contract', 'type' => 'contract', 'category' => 'Contracts', 'reference_number' => 'HG-KLH-01-001-DRAFT', 'status' => 'draft', 'documentable' => 'contract'],
        ['title' => 'Letter of Award — Kingsmill Logistics Hub', 'type' => 'other', 'category' => 'Commercial Documents', 'reference_number' => 'LOA-KLH-01', 'status' => 'issued', 'documentable' => null],
    ];

    public const PROJECT_TEAM = [
        ['email' => 'priya.chandra@haldengroveconstruction.com', 'role' => 'project_manager'],
    ];

    public const PROJECT_CONTACTS = [
        ['name' => 'Oliver Harcourt', 'company' => 'Harcourt Pemberton Surveyors', 'role' => 'consultant', 'email' => 'o.harcourt@harcourtpemberton.co.uk', 'phone' => '+44 1604 233 190', 'is_primary' => true],
        ['name' => 'Victoria Kingsmill', 'company' => 'Kingsmill Logistics Developments Ltd', 'role' => 'client', 'email' => 'v.kingsmill@kingsmilllogistics.co.uk', 'phone' => '+44 1604 233 512', 'is_primary' => true],
    ];
}
