<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Contract Intelligence Brief: {{ $contract->title }}</title>
    <style>
        @page {
            margin-top:    145px;
            margin-bottom: 110px;
            margin-left:   0;
            margin-right:  0;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: DejaVu Sans, Arial, sans-serif;
            font-size: 9pt;
            color: #111111;
            background: #ffffff;
            line-height: 1.45;
        }

        .page { padding: 18px 44px 28px; }

        /* ─── Title block ─────────────────────────────── */
        .title-row { display: table; width: 100%; margin-bottom: 10px; }
        .title-L   { display: table-cell; vertical-align: bottom; }
        .title-R   { display: table-cell; vertical-align: bottom; text-align: right; width: 36%; }

        .doc-label {
            font-size: 6.5pt; font-weight: bold; letter-spacing: 2px;
            text-transform: uppercase; color: #888888; margin-bottom: 4px;
        }
        h1 { font-size: 15pt; font-weight: bold; color: #111111; letter-spacing: -0.3px; line-height: 1.2; }
        .title-meta { font-size: 8pt; color: #555555; margin-top: 4px; line-height: 1.6; }
        .title-meta strong { color: #111111; }

        /* ─── Status chip ─────────────────────────────── */
        .chip {
            display: inline-block; padding: 4px 12px; border-radius: 3px;
            font-size: 7pt; font-weight: bold; letter-spacing: 1px; text-transform: uppercase;
        }
        .chip-confirmed { border: 1.5px solid #1a6b3a; color: #1a6b3a; background: #f0faf4; }
        .chip-ai        { border: 1.5px solid #1e3a6e; color: #1e3a6e; background: #f0f4fa; }

        /* ─── Dividers ────────────────────────────────── */
        .rule-heavy { border: none; border-top: 2px solid #1a1a1a; margin: 10px 0; }
        .rule       { border: none; border-top: 1px solid #d8d8d8; margin: 8px 0; }

        /* ─── Section heading ─────────────────────────── */
        .sh {
            font-size: 6.5pt; font-weight: bold; letter-spacing: 2px;
            text-transform: uppercase; color: #888888;
            border-bottom: 1px solid #dddddd; padding-bottom: 3px;
            margin-bottom: 7px; margin-top: 12px;
        }
        .sh0 { margin-top: 0; }

        /* ─── Two-column layout ───────────────────────── */
        .two-col { display: table; width: 100%; }
        .col-L   { display: table-cell; vertical-align: top; width: 48%; padding-right: 16px; }
        .col-R   { display: table-cell; vertical-align: top; width: 52%; padding-left: 16px; border-left: 1px solid #e4e4e4; }

        /* ─── Three-column layout ─────────────────────── */
        .three-col { display: table; width: 100%; }
        .col-t     { display: table-cell; vertical-align: top; width: 33.3%; padding-right: 10px; }
        .col-t:last-child { padding-right: 0; border-right: none; }

        /* ─── Info table ──────────────────────────────── */
        .it { width: 100%; border-collapse: collapse; }
        .it td {
            padding: 3.5px 0; font-size: 8.5pt; vertical-align: top;
            border-bottom: 1px solid #f0f0f0; line-height: 1.4;
        }
        .it td.k { width: 46%; color: #666666; font-size: 8pt; padding-right: 5px; }
        .it td.v { color: #111111; font-weight: 500; }
        .it tr:last-child td { border-bottom: none; }

        /* ─── Summary box ─────────────────────────────── */
        .summary-box {
            background: #f7f8fa;
            border-left: 3px solid #1e3a6e;
            padding: 9px 12px;
            margin-bottom: 10px;
            font-size: 8.5pt;
            color: #333333;
            line-height: 1.55;
        }

        /* ─── Dates/Obligations/Risks list ────────────── */
        .item-list { width: 100%; border-collapse: collapse; margin-top: 4px; }
        .item-list tr { border-bottom: 1px solid #f0f0f0; }
        .item-list tr:last-child { border-bottom: none; }
        .item-list td { padding: 4px 0; font-size: 8pt; vertical-align: top; line-height: 1.4; }
        .item-list td.idx {
            width: 18px; color: #aaaaaa; font-size: 7.5pt;
            padding-right: 5px; padding-top: 5px;
        }
        .item-list td.title { font-weight: 600; color: #111111; width: 36%; padding-right: 8px; }
        .item-list td.detail { color: #444444; }
        .item-list td.date-col { width: 28%; color: #555555; padding-right: 6px; font-size: 7.5pt; }

        /* ─── Risk severity badges ────────────────────── */
        .badge {
            display: inline-block; padding: 1px 7px; border-radius: 2px;
            font-size: 6.5pt; font-weight: bold; letter-spacing: 0.5px;
            text-transform: uppercase; margin-right: 4px;
        }
        .badge-high   { background: #fde8e8; color: #b91c1c; border: 1px solid #f5c6c6; }
        .badge-medium { background: #fef3c7; color: #92400e; border: 1px solid #f6d860; }
        .badge-low    { background: #e8f5e9; color: #166534; border: 1px solid #b7dfb8; }

        /* ─── Payment rules highlight ─────────────────── */
        .payment-grid { display: table; width: 100%; margin-top: 4px; }
        .pg-cell {
            display: table-cell; vertical-align: top;
            padding: 7px 10px; background: #f7f8fa;
            border: 1px solid #e8e8e8; border-radius: 3px;
        }
        .pg-lbl { font-size: 7pt; color: #888888; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 2px; }
        .pg-val { font-size: 10pt; font-weight: bold; color: #111111; }
        .pg-sub { font-size: 7.5pt; color: #666666; margin-top: 1px; }

        /* ─── No-data message ─────────────────────────── */
        .none { font-size: 8pt; color: #aaaaaa; font-style: italic; }

        /* ─── Footer ──────────────────────────────────── */
        .doc-footer {
            font-size: 7pt; color: #aaaaaa; text-align: center;
            margin-top: 14px; padding-top: 8px; border-top: 1px solid #eeeeee;
        }
    </style>
</head>
<body>
<div class="page">

@php
    use Carbon\Carbon;

    $fields    = $analysis->confirmed_data_json['extracted_fields']
               ?? $analysis->confirmed_data_json
               ?? $analysis->raw_response_json['extracted_fields']
               ?? $analysis->raw_response_json
               ?? [];

    $keyDates    = $contract->key_dates    ?? $analysis->confirmed_data_json['key_dates']    ?? $analysis->raw_response_json['key_dates'] ?? [];
    $obligations = $contract->key_obligations ?? $analysis->confirmed_data_json['key_obligations'] ?? $analysis->raw_response_json['key_obligations'] ?? [];
    $risks       = $contract->risks        ?? $analysis->confirmed_data_json['risks']        ?? $analysis->raw_response_json['risks'] ?? [];

    $contractSum = $contract->contract_sum
        ? '£' . number_format((float)$contract->contract_sum, 2)
        : (isset($fields['contract_sum']) ? '£' . number_format((float)$fields['contract_sum'], 2) : null);

    $currency    = $contract->currency ?? $fields['currency'] ?? 'GBP';
    $retPct      = $contract->retention_percentage ?? $fields['retention_percent'] ?? null;
    $retCapPct   = $contract->retention_cap_percentage ?? $fields['retention_cap_percent'] ?? null;
    $party       = $contract->party_name ?? $fields['contractor'] ?? $fields['contracting_party'] ?? null;
    $employer    = $fields['employer'] ?? $fields['client'] ?? null;
    $formOfContract = $contract->form_of_contract ?? $fields['form_of_contract'] ?? null;
    $dlp         = $contract->defects_liability_period ?? $fields['defects_period'] ?? null;
    $ld          = $contract->liquidated_damages ?? $fields['liquidated_damages'] ?? null;
    $noticeReqs  = $contract->notice_requirements ?? $fields['notice_requirements'] ?? null;
    $varProc     = $contract->variation_procedure ?? $fields['variation_procedure'] ?? null;
    $payTermsDays = $contract->payment_terms_days ?? $fields['payment_terms_days'] ?? null;
    $payFreq     = $contract->payment_frequency ?? $fields['payment_frequency'] ?? null;
    $dueOffset   = $contract->due_date_offset_days ?? $fields['due_date_offset_days'] ?? null;
    $finalOffset = $contract->final_date_offset_days ?? $fields['final_date_offset_days'] ?? null;
    $noticeOffset= $contract->payment_notice_offset_days ?? $fields['payment_notice_offset_days'] ?? null;
    $payLessOffset= $contract->pay_less_notice_offset_days ?? $fields['pay_less_notice_offset_days'] ?? null;

    $summary = $analysis->summary
        ?? $analysis->confirmed_data_json['contract_summary']
        ?? $analysis->raw_response_json['contract_summary']
        ?? null;

    $generatedAt = now()->format('d M Y, H:i');
    $analysisRef = 'ANA-' . str_pad($analysis->id, 5, '0', STR_PAD_LEFT);

    $highRisks   = array_filter($risks ?? [], fn($r) => strtolower($r['severity'] ?? '') === 'high');
    $medRisks    = array_filter($risks ?? [], fn($r) => strtolower($r['severity'] ?? '') === 'medium');
@endphp

{{-- Title block ────────────────────────────────────────────────────────── --}}
<div class="title-row">
    <div class="title-L">
        <div class="doc-label">Contract Intelligence Brief</div>
        <h1>{{ $contract->title }}</h1>
        <div class="title-meta">
            @if($contract->reference_number)
            <strong>Contract Ref:</strong> {{ $contract->reference_number }}
            &nbsp;&nbsp;·&nbsp;&nbsp;
            @endif
            <strong>Project:</strong> {{ $project->name }}
            @if($project->code)
                &nbsp;({{ $project->code }})
            @endif
        </div>
        <div class="title-meta">
            <strong>Analysis Ref:</strong> {{ $analysisRef }}
            &nbsp;&nbsp;·&nbsp;&nbsp;
            <strong>Generated:</strong> {{ $generatedAt }}
        </div>
    </div>
    <div class="title-R">
        <span class="chip chip-confirmed">Confirmed</span>
        &nbsp;
        <span class="chip chip-ai">AI&nbsp;Analysis</span>
    </div>
</div>

<hr class="rule-heavy">

{{-- Contract Summary ────────────────────────────────────────────────────── --}}
@if($summary)
<div class="summary-box">{{ $summary }}</div>
@endif

{{-- Contract Details ────────────────────────────────────────────────────── --}}
<div class="two-col">
    <div class="col-L">
        <div class="sh sh0">Contract Details</div>
        <table class="it">
            @if($formOfContract)
            <tr><td class="k">Form of Contract</td><td class="v">{{ $formOfContract }}</td></tr>
            @endif
            @if($party)
            <tr><td class="k">Contractor</td><td class="v">{{ $party }}</td></tr>
            @endif
            @if($employer)
            <tr><td class="k">Employer / Client</td><td class="v">{{ $employer }}</td></tr>
            @endif
            @if($contractSum)
            <tr><td class="k">Contract Sum</td><td class="v">{{ $contractSum }}</td></tr>
            @endif
            @if($currency)
            <tr><td class="k">Currency</td><td class="v">{{ strtoupper($currency) }}</td></tr>
            @endif
            @if($contract->execution_date)
            <tr><td class="k">Execution Date</td><td class="v">{{ Carbon::parse($contract->execution_date)->format('d M Y') }}</td></tr>
            @endif
        </table>
    </div>
    <div class="col-R">
        <div class="sh sh0">Programme</div>
        <table class="it">
            @if($contract->commencement_date)
            <tr><td class="k">Commencement Date</td><td class="v">{{ Carbon::parse($contract->commencement_date)->format('d M Y') }}</td></tr>
            @endif
            @if($contract->completion_date)
            <tr><td class="k">Completion Date</td><td class="v">{{ Carbon::parse($contract->completion_date)->format('d M Y') }}</td></tr>
            @endif
            @if($dlp)
            <tr><td class="k">Defects Liability Period</td><td class="v">{{ $dlp }}</td></tr>
            @endif
            @if($ld)
            <tr><td class="k">Liquidated Damages</td><td class="v">{{ $ld }}</td></tr>
            @endif
            @if($retPct !== null)
            <tr><td class="k">Retention</td><td class="v">{{ $retPct }}%{{ $retCapPct ? ' (cap ' . $retCapPct . '%)' : '' }}</td></tr>
            @endif
        </table>
    </div>
</div>

<hr class="rule">

{{-- Payment Rules ────────────────────────────────────────────────────────── --}}
<div class="sh">Payment Rules</div>

@if($dueOffset || $finalOffset || $noticeOffset || $payLessOffset || $payTermsDays)
<table style="width:100%;border-collapse:collapse;">
    <tr>
        @if($dueOffset !== null)
        <td style="padding:0 6px 0 0;width:25%;">
            <div style="background:#f7f8fa;border:1px solid #e8e8e8;border-radius:3px;padding:7px 10px;">
                <div class="pg-lbl">Due Date</div>
                <div class="pg-val">+{{ $dueOffset }}&thinsp;days</div>
                <div class="pg-sub">from application date</div>
            </div>
        </td>
        @endif
        @if($finalOffset !== null)
        <td style="padding:0 6px;width:25%;">
            <div style="background:#f7f8fa;border:1px solid #e8e8e8;border-radius:3px;padding:7px 10px;">
                <div class="pg-lbl">Final Date</div>
                <div class="pg-val">+{{ $finalOffset }}&thinsp;days</div>
                <div class="pg-sub">from due date</div>
            </div>
        </td>
        @endif
        @if($noticeOffset !== null)
        <td style="padding:0 6px;width:25%;">
            <div style="background:#f7f8fa;border:1px solid #e8e8e8;border-radius:3px;padding:7px 10px;">
                <div class="pg-lbl">Payment Notice</div>
                <div class="pg-val">+{{ $noticeOffset }}&thinsp;days</div>
                <div class="pg-sub">from due date</div>
            </div>
        </td>
        @endif
        @if($payLessOffset !== null)
        <td style="padding:0 0 0 6px;width:25%;">
            <div style="background:#fef9f0;border:1px solid #f0e0c0;border-radius:3px;padding:7px 10px;">
                <div class="pg-lbl">Pay Less Notice</div>
                <div class="pg-val">−{{ $payLessOffset }}&thinsp;days</div>
                <div class="pg-sub">before final date</div>
            </div>
        </td>
        @endif
    </tr>
</table>
@if($payFreq || $payTermsDays)
<div style="margin-top:6px;">
    <table class="it">
        @if($payFreq)
        <tr><td class="k">Payment Frequency</td><td class="v">{{ ucfirst($payFreq) }}</td></tr>
        @endif
        @if($payTermsDays)
        <tr><td class="k">Payment Terms</td><td class="v">{{ $payTermsDays }} days</td></tr>
        @endif
    </table>
</div>
@endif
@else
<p class="none">No structured payment rules extracted. Refer to contract documents.</p>
@endif

@if($noticeReqs || $varProc)
<hr class="rule">
<div class="two-col">
    @if($noticeReqs)
    <div class="col-L">
        <div class="sh sh0">Notice Requirements</div>
        <p style="font-size:8.5pt;color:#333333;line-height:1.55;">{{ $noticeReqs }}</p>
    </div>
    @endif
    @if($varProc)
    <div class="{{ $noticeReqs ? 'col-R' : 'col-L' }}">
        <div class="sh sh0">Variation Procedure</div>
        <p style="font-size:8.5pt;color:#333333;line-height:1.55;">{{ $varProc }}</p>
    </div>
    @endif
</div>
@endif

{{-- Key Dates ────────────────────────────────────────────────────────────── --}}
@if(!empty($keyDates))
<hr class="rule">
<div class="sh">Key Dates ({{ count($keyDates) }})</div>
<table class="item-list">
    @foreach($keyDates as $i => $kd)
    @php
        $kdTitle = $kd['title'] ?? $kd['event'] ?? $kd['description'] ?? 'Key Date';
        $kdDate  = $kd['date'] ?? $kd['due_date'] ?? null;
        $kdDesc  = $kd['description'] ?? $kd['source'] ?? null;
        try { $kdDateFmt = $kdDate ? Carbon::parse($kdDate)->format('d M Y') : null; } catch (\Throwable $e) { $kdDateFmt = $kdDate; }
    @endphp
    <tr>
        <td class="idx">{{ $i + 1 }}</td>
        <td class="title">{{ $kdTitle }}</td>
        <td class="date-col">{{ $kdDateFmt ?? '—' }}</td>
        <td class="detail">{{ $kdDesc ?? '' }}</td>
    </tr>
    @endforeach
</table>
@endif

{{-- Key Obligations ──────────────────────────────────────────────────────── --}}
@if(!empty($obligations))
<hr class="rule">
<div class="sh">Key Obligations ({{ count($obligations) }})</div>
<table class="item-list">
    @foreach($obligations as $i => $ob)
    @php
        $obTitle  = $ob['title'] ?? $ob['obligation'] ?? $ob['description'] ?? 'Obligation';
        $obParty  = $ob['responsible_party'] ?? $ob['party'] ?? null;
        $obDue    = $ob['due_date'] ?? $ob['deadline'] ?? null;
        $obClause = $ob['clause'] ?? $ob['clause_reference'] ?? null;
        try { $obDueFmt = $obDue ? Carbon::parse($obDue)->format('d M Y') : null; } catch (\Throwable $e) { $obDueFmt = $obDue; }
        $obMeta = array_filter([$obParty ? "Party: {$obParty}" : null, $obClause ? "Clause: {$obClause}" : null]);
    @endphp
    <tr>
        <td class="idx">{{ $i + 1 }}</td>
        <td class="title">{{ $obTitle }}</td>
        <td class="date-col">{{ $obDueFmt ?? '—' }}</td>
        <td class="detail">{{ implode(' · ', $obMeta) }}</td>
    </tr>
    @endforeach
</table>
@endif

{{-- Risks ────────────────────────────────────────────────────────────────── --}}
@if(!empty($risks))
<hr class="rule">
<div class="sh">
    Contract Risks ({{ count($risks) }})
    @if(count($highRisks) > 0)
    &nbsp;·&nbsp;<span style="color:#b91c1c;font-weight:bold;">{{ count($highRisks) }} High</span>
    @endif
    @if(count($medRisks) > 0)
    &nbsp;<span style="color:#92400e;">{{ count($medRisks) }} Medium</span>
    @endif
</div>
<table class="item-list">
    @foreach($risks as $i => $risk)
    @php
        $rTitle    = $risk['title'] ?? $risk['risk'] ?? $risk['description'] ?? 'Risk';
        $rSev      = strtolower($risk['severity'] ?? 'low');
        $rDesc     = $risk['description'] ?? null;
        $rAction   = $risk['recommended_action'] ?? $risk['mitigation'] ?? null;
        $rClause   = $risk['clause'] ?? $risk['source'] ?? null;
    @endphp
    <tr>
        <td class="idx">{{ $i + 1 }}</td>
        <td class="title">
            <span class="badge badge-{{ $rSev }}">{{ ucfirst($rSev) }}</span>
            {{ $rTitle }}
        </td>
        <td colspan="2" class="detail">
            @if($rDesc){{ $rDesc }}@endif
            @if($rAction)<br><span style="color:#1e3a6e;font-size:7.5pt;">&#x27a4; {{ $rAction }}</span>@endif
            @if($rClause)<br><span style="color:#999999;font-size:7pt;">Clause: {{ $rClause }}</span>@endif
        </td>
    </tr>
    @endforeach
</table>
@endif

{{-- AI Analysis Metadata ─────────────────────────────────────────────────── --}}
<hr class="rule">
<div class="two-col">
    <div class="col-L">
        <div class="sh sh0">AI Analysis Details</div>
        <table class="it">
            <tr><td class="k">Analysis Reference</td><td class="v">{{ $analysisRef }}</td></tr>
            <tr><td class="k">Provider / Model</td><td class="v">{{ ucfirst($analysis->provider ?? 'Anthropic') }} / {{ $analysis->model ?? 'claude-sonnet' }}</td></tr>
            @if($analysis->completed_at)
            <tr><td class="k">Analysis Date</td><td class="v">{{ Carbon::parse($analysis->completed_at)->format('d M Y, H:i') }}</td></tr>
            @endif
            @if($analysis->estimated_cost)
            <tr><td class="k">Estimated API Cost</td><td class="v">${{ number_format($analysis->estimated_cost, 4) }}</td></tr>
            @endif
        </table>
    </div>
    <div class="col-R">
        <div class="sh sh0">Document Information</div>
        <table class="it">
            <tr><td class="k">Project</td><td class="v">{{ $project->name }}</td></tr>
            @if($project->code)
            <tr><td class="k">Project Number</td><td class="v">{{ $project->code }}</td></tr>
            @endif
            <tr><td class="k">Generated By</td><td class="v">{{ $generatedBy->name ?? 'SureSign Contracts' }}</td></tr>
            <tr><td class="k">Generated At</td><td class="v">{{ $generatedAt }}</td></tr>
        </table>
    </div>
</div>

<div class="doc-footer">
    Contract Intelligence Brief generated electronically by SureSign Contracts
    &nbsp;·&nbsp; {{ $generatedAt }}
    &nbsp;·&nbsp; {{ $analysisRef }}
    &nbsp;·&nbsp; This document reflects AI-extracted intelligence and should be verified against the original contract.
</div>

</div>
</body>
</html>
