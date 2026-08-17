<?php

namespace App\Services\AI;

class ContractAnalysisPrompt
{
    /**
     * The canonical short-summary field for schema v2.0 is
     * executive_summary.commercial_summary — NOT the flat top-level
     * contract_summary key the v1 (pre-v2.0) schema used. Both
     * AnalyseContractWithAiJob and AiController::reparseAnalysis() call this
     * single method rather than each independently guessing the right key
     * (a mismatch here previously left ContractAiAnalysis::summary silently
     * null for every v2.0 analysis — see G4C.1A). The v1 fallback stays
     * only so an old, already-completed analysis re-parsed today still
     * produces a summary; the schema itself is not duplicated.
     */
    public static function extractSummary(array $data): ?string
    {
        $summary = data_get($data, 'executive_summary.commercial_summary')
            ?? data_get($data, 'contract_summary');

        return $summary !== null ? \Illuminate\Support\Str::limit($summary, 1000) ?: null : null;
    }

    public static function system(): string
    {
        return <<<'PROMPT'
You are assisting with UK construction contract administration.

Your task is to extract structured information from a construction contract document and return it as a single valid JSON object matching the v2.0 schema exactly.

Core rules:
- Extract only information explicitly stated in the contract text. Do not guess, infer, or fabricate.
- If a field is not found, return null. Do not omit fields from the schema.
- For dates, return ISO 8601 (YYYY-MM-DD) if determinable, otherwise return the raw text.
- For monetary amounts, return the numeric value as a string with no currency symbols or commas (e.g. "250000.00").
- For payment offset integers (due_date_offset_days, final_date_offset_days, payment_notice_offset_days, pay_less_notice_offset_days), extract the integer number of days only. Return null if not stated.
- For uncertain values, append a note: "30 (unclear — see clause 4.3)".
- Do not provide legal advice. Describe observations only.
- Your response must be valid JSON only — no markdown fences, no code blocks, no commentary outside the JSON.

Schema v2.0 specific rules:
- "deadlines" array: Extract EVERY time-bound obligation expressed as "within X days", "no later than X days before [event]", "before commencement", "after instruction", or any recurring obligation. Be comprehensive — these drive the Calendar module.
- "notices" array: Distinguish HGCRA statutory notices (payment notice, pay less notice, suspension notice — is_statutory: true) from contractual notices (delay, EOT, defect, termination — is_statutory: false).
- "risks" array: Flag every clause departing from standard JCT/NEC form. Mark is_non_standard_amendment: true for clauses that reduce a statutory right, exclude a common law remedy, reduce notice periods below statutory minimums, or introduce unusual risk allocation.
- "obligations" object: Group by responsible party. An obligation is any clause requiring a party to do something, produce something, or achieve something by a specific time or within a period after a trigger.
- "recommended_workflows": Return objects with "recommended" (boolean) and "reason" (string) — not plain booleans.
- "executive_summary.intelligence_score": 0–100. A complete JCT with all schedules = 85+. A short framework with few terms = 20–40.
- "executive_summary.section_confidence": Rate each section high/medium/low based on how clearly those clauses appeared.
- For arrays (deadlines, notices, deliverables, risks, obligations sub-arrays): return all items found. Return [] if none found.
- "contract_overview.project_location": This is the PROJECT/SITE location — where the contracted works themselves are physically located — never a registered office, correspondence address, payment/remittance address, or notice-service address for the Employer, Contractor, Subcontractor, Architect, Quantity Surveyor, Project Manager, or any other party. A contract naming only company addresses and no separate site/works address means this is empty — do not substitute a party's address for it. Extract only the components explicitly stated; leave any component null if not present (e.g. a vague reference like "the Works" with no address at all means every component is null; "North London" with no street/postcode means only city is filled and the rest stay null). Never combine, split, or infer a component from a different one, and never construct a full address from partial information. This field represents a physical location only — never return coordinates, a map link, or a "latitude"/"longitude" value anywhere in this schema.
PROMPT;
    }

    public static function user(string $contractText): string
    {
        $maxChars  = (int) config('ai.anthropic.max_input_chars', 150000);
        $truncated = mb_substr($contractText, 0, $maxChars);

        return <<<PROMPT
Extract structured information from the following construction contract and return a JSON object matching EXACTLY this v2.0 schema. Use null for fields not found. Return [] for arrays with no items.

{
  "meta": {
    "schema_version": "2.0",
    "confidence": "high",
    "truncated": false,
    "extraction_notes": null
  },
  "contract_overview": {
    "contract_title": null,
    "contract_reference": null,
    "contract_type": null,
    "standard_form": null,
    "standard_form_edition": null,
    "procurement_route": null,
    "is_subcontract": false,
    "trade_package_reference": null,
    "design_responsibility": null,
    "currency": "GBP",
    "governing_law": null,
    "project_location": {
      "address_line": null,
      "city": null,
      "region": null,
      "postal_code": null,
      "country": null
    }
  },
  "parties": {
    "employer": { "name": null, "company": null, "address": null, "contact_name": null, "contact_email": null },
    "employers_agent": { "name": null, "company": null, "address": null, "contact_name": null, "contact_email": null },
    "main_contractor": { "name": null, "company": null, "address": null, "contact_name": null, "contact_email": null },
    "subcontractor": { "name": null, "company": null, "address": null, "contact_name": null, "contact_email": null },
    "architect": { "name": null, "company": null, "address": null, "contact_name": null, "contact_email": null },
    "quantity_surveyor": { "name": null, "company": null, "address": null, "contact_name": null, "contact_email": null },
    "project_manager": { "name": null, "company": null, "address": null, "contact_name": null, "contact_email": null },
    "principal_designer": { "name": null, "company": null, "address": null, "contact_name": null, "contact_email": null },
    "principal_contractor": { "name": null, "company": null, "address": null, "contact_name": null, "contact_email": null },
    "structural_engineer": { "name": null, "company": null, "address": null, "contact_name": null, "contact_email": null },
    "mep_engineer": { "name": null, "company": null, "address": null, "contact_name": null, "contact_email": null }
  },
  "commercial": {
    "contract_sum": null,
    "currency": null,
    "interim_payment_frequency": null,
    "valuation_method": null,
    "retention_percent": null,
    "retention_cap_percent": null,
    "retention_half_1_release_event": null,
    "retention_half_2_release_event": null,
    "retention_release_notes": null,
    "due_date_offset_days": null,
    "final_date_offset_days": null,
    "payment_notice_offset_days": null,
    "pay_less_notice_offset_days": null,
    "payment_application_submission_day": null,
    "vat_reverse_charge_applicable": false,
    "provisional_sums_total": null,
    "prime_cost_sums_total": null,
    "fluctuations_clause": null,
    "fluctuations_formula": null,
    "performance_bond_required": false,
    "performance_bond_amount": null,
    "performance_bond_percent": null,
    "performance_bond_type": null,
    "parent_company_guarantee_required": false,
    "collateral_warranties_required": false,
    "collateral_warranty_beneficiaries": [],
    "liquidated_damages_rate": null,
    "liquidated_damages_per": null,
    "liquidated_damages_cap": null,
    "liquidated_damages_cap_percent": null,
    "loss_and_expense_applicable": true,
    "advance_payment": null,
    "advance_payment_bond_required": false
  },
  "dates": {
    "base_date": null,
    "tender_date": null,
    "execution_date": null,
    "commencement_date": null,
    "possession_date": null,
    "completion_date": null,
    "practical_completion_date": null,
    "defects_liability_period": null,
    "defects_liability_period_months": null,
    "defects_liability_end_date": null,
    "rectification_period_months": null,
    "final_certificate_deadline": null,
    "sectional_completions": [],
    "key_milestones": []
  },
  "deadlines": [
    {
      "name": null,
      "category": null,
      "responsible_party": null,
      "time_period_text": null,
      "time_period_days": null,
      "time_direction": null,
      "trigger_event": null,
      "recipient": null,
      "clause_reference": null,
      "consequence_of_non_compliance": null,
      "is_statutory": false,
      "is_recurring": false,
      "recurrence_description": null,
      "notes": null,
      "generates_calendar_event": true,
      "generates_notification": true
    }
  ],
  "programme": {
    "programme_required": null,
    "programme_submission_days_from_commencement": null,
    "programme_type": null,
    "update_frequency": null,
    "update_frequency_days": null,
    "float_requirements": null,
    "contractor_float_protected": null,
    "critical_path_required": null,
    "recovery_programme_trigger": null,
    "recovery_programme_submission_days": null,
    "progress_reporting_required": null,
    "progress_reporting_frequency": null,
    "progress_report_format": null,
    "programme_revisions_procedure": null,
    "master_programme_owner": null,
    "look_ahead_required": null,
    "look_ahead_weeks": null
  },
  "variations": {
    "instruction_required_before_work": null,
    "written_instruction_required": null,
    "verbal_instruction_confirmation_days": null,
    "quotation_required": null,
    "quotation_submission_days": null,
    "quotation_response_days": null,
    "assessment_method": null,
    "daywork_percentage_addition": null,
    "time_limit_for_claiming": null,
    "programme_impact_required_with_quotation": null,
    "disputed_variation_procedure": null,
    "omission_right": null,
    "omission_restrictions": null,
    "contractor_right_to_object": null,
    "pricing_rules_text": null
  },
  "extension_of_time": {
    "notice_obligation": null,
    "notice_period_days": null,
    "notice_period_text": null,
    "notice_trigger": null,
    "particulars_required": null,
    "particulars_submission_days": null,
    "interim_extensions_permitted": null,
    "fixity_period_weeks": null,
    "mitigation_obligation": null,
    "concurrent_delay_wording": null,
    "concurrent_delay_position": null,
    "relevant_events": [],
    "relevant_matters": [],
    "programme_impact_required": null,
    "global_claim_excluded": null
  },
  "design": {
    "design_responsibility": null,
    "contractor_design_portion_description": null,
    "employer_requirements_issued": null,
    "contractor_proposals_required": null,
    "design_programme_required": null,
    "design_submission_procedure": null,
    "design_approval_required": null,
    "design_approval_days": null,
    "no_objection_procedure": null,
    "bim_required": null,
    "bim_level": null,
    "bim_execution_plan_required": null,
    "common_data_environment": null,
    "intellectual_property_ownership": null,
    "licence_to_use": null,
    "copyright_assignment": null,
    "professional_indemnity_required": null,
    "professional_indemnity_amount": null,
    "professional_indemnity_years": null
  },
  "compliance": {
    "cdm_applies": null,
    "cdm_role": null,
    "f10_notification_required": null,
    "construction_phase_plan_required": null,
    "health_safety_file_required": null,
    "building_regulations_approval_reference": null,
    "building_safety_act_applies": null,
    "higher_risk_building": null,
    "gateway_2_required": null,
    "gateway_3_required": null,
    "environmental_obligations": null,
    "noise_restrictions": null,
    "working_hours_restrictions": null,
    "testing_commissioning_requirements": null,
    "inspection_regime": null,
    "quality_management_system_required": null,
    "qms_standard": null,
    "fire_strategy_required": null,
    "sprinkler_certification_required": null
  },
  "deliverables": [
    {
      "name": null,
      "category": null,
      "required": true,
      "responsible_party": null,
      "due_event": null,
      "due_days_before_after_event": null,
      "format": null,
      "copies_required": null,
      "clause_reference": null,
      "recipient": null,
      "consequence_if_late": null,
      "notes": null
    }
  ],
  "notices": [
    {
      "name": null,
      "notice_type": null,
      "is_statutory": false,
      "responsible_party": null,
      "trigger": null,
      "time_limit_days": null,
      "time_direction": null,
      "time_reference_point": null,
      "recipient": null,
      "clause_reference": null,
      "required_content": [],
      "consequence_if_missed": null,
      "can_be_withheld": false,
      "notes": null
    }
  ],
  "risks": [
    {
      "title": null,
      "description": null,
      "severity": null,
      "category": null,
      "clause_reference": null,
      "commercial_impact": null,
      "programme_impact": null,
      "compliance_impact": null,
      "urgency": null,
      "recommended_action": null,
      "risk_owner": null,
      "is_non_standard_amendment": false,
      "notes": null
    }
  ],
  "obligations": {
    "employer": [
      {
        "title": null,
        "description": null,
        "clause_reference": null,
        "time_period_text": null,
        "time_period_days": null,
        "trigger_event": null,
        "consequence_if_missed": null,
        "generates_deadline": false,
        "category": null
      }
    ],
    "main_contractor": [],
    "subcontractor": [],
    "architect_ca": [],
    "consultants": [],
    "both": []
  },
  "executive_summary": {
    "commercial_summary": null,
    "overall_risk_rating": null,
    "biggest_risks": [],
    "critical_dates": [],
    "immediate_actions": [],
    "high_level_recommendations": [],
    "payment_health": null,
    "programme_health": null,
    "compliance_health": null,
    "contract_complexity": null,
    "intelligence_score": null,
    "section_confidence": {
      "commercial": null,
      "programme": null,
      "compliance": null,
      "design": null,
      "insurance": null,
      "payment": null,
      "notices": null,
      "risks": null
    }
  },
  "recommended_workflows": {
    "payment_applications": { "recommended": true, "reason": null },
    "variations": { "recommended": true, "reason": null },
    "notices": { "recommended": true, "reason": null },
    "eot_tracking": { "recommended": false, "reason": null },
    "retention_tracking": { "recommended": false, "reason": null },
    "risk_register": { "recommended": true, "reason": null },
    "programme_tracking": { "recommended": false, "reason": null },
    "deliverables_tracker": { "recommended": false, "reason": null },
    "cdm_register": { "recommended": false, "reason": null },
    "final_account": { "recommended": false, "reason": null },
    "close_out": { "recommended": false, "reason": null }
  },
  "recommended_documents": [
    { "document_type": null, "reason": null }
  ],
  "missing_information": []
}

CONTRACT TEXT:
{$truncated}
PROMPT;
    }
}
