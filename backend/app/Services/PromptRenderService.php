<?php

namespace App\Services;

use App\Models\AdjudicationCase;
use App\Models\Contract;
use App\Models\Document;
use App\Models\MeetingMinutes;
use App\Models\PaymentApplication;
use App\Models\Project;
use App\Models\PromptTemplate;
use App\Models\QaReport;
use App\Models\Rfi;
use App\Models\Snag;
use App\Models\Variation;

class PromptRenderService
{
    /**
     * Render a prompt template with project + extra context.
     */
    public function render(PromptTemplate $template, ?Project $project = null, array $extraData = []): string
    {
        $context = array_merge(
            $this->buildBaseContext(),
            $project ? $this->buildProjectContext($project) : [],
            $extraData
        );
        return $this->replacePlaceholders($template->prompt_text, $context);
    }

    /**
     * Build base context available to every prompt.
     */
    public function buildBaseContext(): array
    {
        return [
            'current_date' => now()->format('d F Y'),
        ];
    }

    /**
     * Build context from a Project and its organisation/client.
     */
    public function buildProjectContext(Project $project): array
    {
        $project->loadMissing(['organization', 'client']);
        $organization = $project->organization;
        $client       = $project->client;

        return array_filter([
            'project_name'      => $project->name,
            'project_code'      => $project->code,
            'company_name'      => $organization?->name,
            'organization_name' => $organization?->name,
            'client_name'       => $client?->name,
            'contract_value'    => $project->contract_value ? number_format((float) $project->contract_value, 2) : null,
            'contract_type'     => $project->contract_type,
            'currency'          => $project->resolved_currency,
            'start_date'        => $project->start_date?->format('d F Y'),
            'end_date'          => $project->end_date?->format('d F Y'),
            'completion_date'   => $project->end_date?->format('d F Y'),
        ], fn($v) => $v !== null && $v !== '');
    }

    /**
     * Build context for a specific linked record.
     *
     * Supported types: contract, payment_application, variation, rfi, meeting,
     *                  qa_report, snag, adjudication_case, document
     */
    public function buildRecordContext(string $recordType, int $recordId, ?Project $project = null): array
    {
        return match ($recordType) {
            'contract'            => $this->buildContractContext($recordId),
            'payment_application' => $this->buildPaymentApplicationContext($recordId),
            'variation'           => $this->buildVariationContext($recordId),
            'rfi'                 => $this->buildRfiContext($recordId),
            'meeting'             => $this->buildMeetingContext($recordId),
            'qa_report'           => $this->buildQaReportContext($recordId),
            'snag'                => $this->buildSnagContext($recordId),
            'adjudication_case'   => $this->buildAdjudicationContext($recordId),
            'document'            => $this->buildDocumentContext($recordId),
            default               => [],
        };
    }

    // ── Private record context builders ──────────────────────────────────────

    private function buildContractContext(int $id): array
    {
        $c = Contract::find($id);
        if (! $c) return [];
        return array_filter([
            'contract_title'       => $c->title,
            'contract_reference'   => $c->reference_number,
            'contract_sum'         => $c->contract_sum ? number_format((float) $c->contract_sum, 2) : null,
            'contract_value'       => $c->contract_sum ? number_format((float) $c->contract_sum, 2) : null,
            'contract_status'      => $c->status ? ucfirst($c->status) : null,
            'party_name'           => $c->party_name,
            'form_of_contract'     => $c->form_of_contract,
            'payment_terms_days'   => $c->payment_terms_days !== null ? (string) $c->payment_terms_days : null,
            'retention_percentage' => $c->retention_percentage !== null ? $c->retention_percentage . '%' : null,
            'currency'             => $c->currency,
        ], fn($v) => $v !== null && $v !== '');
    }

    private function buildPaymentApplicationContext(int $id): array
    {
        $pa = PaymentApplication::find($id);
        if (! $pa) return [];
        return array_filter([
            'payment_application_number'    => $pa->application_number ? 'PA-' . str_pad($pa->application_number, 3, '0', STR_PAD_LEFT) : null,
            'payment_application_reference' => $pa->reference,
            'application_date'              => $pa->application_date?->format('d F Y'),
            'gross_valuation'               => $pa->gross_valuation ? number_format((float) $pa->gross_valuation, 2) : null,
            'amount_due'                    => $pa->amount_due ? number_format((float) $pa->amount_due, 2) : null,
            'certified_amount'              => $pa->certified_amount ? number_format((float) $pa->certified_amount, 2) : null,
            'payment_status'                => $pa->status ? ucfirst($pa->status) : null,
        ], fn($v) => $v !== null && $v !== '');
    }

    private function buildVariationContext(int $id): array
    {
        $v = Variation::find($id);
        if (! $v) return [];
        return array_filter([
            'variation_number'      => 'VAR-' . str_pad($v->variation_number, 3, '0', STR_PAD_LEFT),
            'variation_title'       => $v->title,
            'variation_type'        => $v->type ? ucwords(str_replace('_', ' ', $v->type)) : null,
            'variation_description' => $v->description,
            'quoted_amount'         => $v->quoted_amount ? number_format((float) $v->quoted_amount, 2) : null,
            'agreed_amount'         => $v->agreed_amount ? number_format((float) $v->agreed_amount, 2) : null,
            'programme_impact_days' => $v->programme_impact_days !== null ? $v->programme_impact_days . ' days' : null,
            'variation_status'      => $v->status ? ucwords(str_replace('_', ' ', $v->status)) : null,
        ], fn($v2) => $v2 !== null && $v2 !== '');
    }

    private function buildRfiContext(int $id): array
    {
        $r = Rfi::find($id);
        if (! $r) return [];
        return array_filter([
            'rfi_number'           => 'RFI-' . str_pad($r->rfi_number, 3, '0', STR_PAD_LEFT),
            'rfi_subject'          => $r->subject,
            'rfi_query'            => $r->description,
            'rfi_response'         => $r->response,
            'rfi_priority'         => $r->priority ? ucfirst($r->priority) : null,
            'rfi_status'           => $r->status ? ucfirst($r->status) : null,
            'response_required_by' => $r->response_due_date?->format('d F Y'),
        ], fn($v) => $v !== null && $v !== '');
    }

    private function buildMeetingContext(int $id): array
    {
        $m = MeetingMinutes::find($id);
        if (! $m) return [];
        $attendeesList   = is_array($m->attendees) ? implode(', ', $m->attendees) : ($m->attendees ?? '');
        $actionItemsList = is_array($m->action_items)
            ? implode("\n- ", array_map(fn($i) => is_array($i) ? ($i['description'] ?? json_encode($i)) : $i, $m->action_items))
            : ($m->action_items ?? '');
        return array_filter([
            'meeting_title'    => $m->title,
            'meeting_type'     => $m->type ? ucfirst($m->type) : null,
            'meeting_date'     => $m->meeting_date?->format('d F Y'),
            'meeting_location' => $m->location,
            'attendees'        => $attendeesList,
            'agenda'           => $m->agenda,
            'minutes'          => $m->minutes,
            'action_items'     => $actionItemsList ? "- {$actionItemsList}" : null,
        ], fn($v) => $v !== null && $v !== '');
    }

    private function buildQaReportContext(int $id): array
    {
        $q = QaReport::find($id);
        if (! $q) return [];
        return array_filter([
            'qa_report_number'  => 'QA-' . str_pad($q->report_number, 3, '0', STR_PAD_LEFT),
            'qa_title'          => $q->title,
            'inspection_type'   => $q->inspection_type,
            'inspection_area'   => $q->area,
            'inspector_name'    => $q->inspected_by,
            'inspection_date'   => $q->inspection_date?->format('d F Y'),
            'qa_status'         => $q->status ? ucfirst($q->status) : null,
            'qa_result'         => $q->result,
            'observations'      => $q->observations,
            'corrective_action' => $q->corrective_action,
        ], fn($v) => $v !== null && $v !== '');
    }

    private function buildSnagContext(int $id): array
    {
        $s = Snag::find($id);
        if (! $s) return [];
        return array_filter([
            'snag_number'   => 'SNAG-' . str_pad($s->snag_number, 3, '0', STR_PAD_LEFT),
            'snag_title'    => $s->title,
            'snag_category' => $s->category,
            'snag_priority' => $s->priority ? ucfirst($s->priority) : null,
            'snag_status'   => $s->status ? ucwords(str_replace('_', ' ', $s->status)) : null,
            'snag_location' => $s->location,
            'snag_due_date' => $s->due_date?->format('d F Y'),
            'snag_notes'    => $s->notes,
        ], fn($v) => $v !== null && $v !== '');
    }

    private function buildAdjudicationContext(int $id): array
    {
        $a = AdjudicationCase::find($id);
        if (! $a) return [];
        return array_filter([
            'adjudication_case_number'    => 'ADJ-' . str_pad($a->case_number, 3, '0', STR_PAD_LEFT),
            'adjudication_case_title'     => $a->title,
            'dispute_type'               => $a->dispute_type ? ucwords(str_replace('_', ' ', $a->dispute_type)) : null,
            'claimant_name'              => $a->claimant_name,
            'respondent_name'            => $a->respondent_name,
            'claim_amount'               => $a->claim_amount ? number_format((float) $a->claim_amount, 2) : null,
            'current_step'               => $a->current_step ? ucwords(str_replace('_', ' ', $a->current_step)) : null,
            'notice_of_dispute_date'     => $a->notice_of_dispute_date?->format('d F Y'),
            'notice_of_adjudication_date'=> $a->notice_of_adjudication_date?->format('d F Y'),
            'referral_due_date'          => $a->referral_due_date?->format('d F Y'),
            'response_due_date'          => $a->response_due_date?->format('d F Y'),
            'decision_due_date'          => $a->decision_due_date?->format('d F Y'),
        ], fn($v) => $v !== null && $v !== '');
    }

    private function buildDocumentContext(int $id): array
    {
        $d = Document::find($id);
        if (! $d) return [];
        return array_filter([
            'document_title'     => $d->title,
            'document_type'      => $d->type ? ucwords(str_replace('_', ' ', $d->type)) : null,
            'document_category'  => $d->category,
            'document_reference' => $d->reference_number,
        ], fn($v) => $v !== null && $v !== '');
    }

    // ── Core placeholder utilities ────────────────────────────────────────────

    /**
     * Extract all {{placeholder}} variable names from a prompt string.
     */
    public function extractVariables(string $promptText): array
    {
        preg_match_all('/\{\{(\w+)\}\}/', $promptText, $matches);
        return array_unique($matches[1] ?? []);
    }

    /**
     * Replace {{placeholder}} tokens in prompt text with context values.
     * Missing values become [INSERT READABLE_KEY].
     */
    public function replacePlaceholders(string $promptText, array $context): string
    {
        return preg_replace_callback('/\{\{(\w+)\}\}/', function ($matches) use ($context) {
            $key = $matches[1];
            if (isset($context[$key]) && $context[$key] !== null && $context[$key] !== '') {
                return $context[$key];
            }
            $readable = strtoupper(str_replace('_', ' ', $key));
            return "[INSERT {$readable}]";
        }, $promptText);
    }
}
