<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Final Certificate: {{ $finalAccount->reference }}</title>
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
        .title-R   { display: table-cell; vertical-align: bottom; text-align: right; width: 34%; }

        .doc-label {
            font-size: 6.5pt; font-weight: bold; letter-spacing: 2px;
            text-transform: uppercase; color: #888888; margin-bottom: 4px;
        }
        h1 { font-size: 16pt; font-weight: bold; color: #111111; letter-spacing: -0.3px; line-height: 1.15; }
        .title-meta { font-size: 8pt; color: #555555; margin-top: 4px; line-height: 1.6; }
        .title-meta strong { color: #111111; }

        /* ─── Status chip ─────────────────────────────── */
        .chip {
            display: inline-block; padding: 4px 12px; border-radius: 3px;
            font-size: 7pt; font-weight: bold; letter-spacing: 1px; text-transform: uppercase;
            border: 1.5px solid #1a7a3a; color: #1a7a3a; background: #eafaf0;
        }

        /* ─── Dividers ────────────────────────────────── */
        .rule-heavy { border: none; border-top: 2px solid #1a1a1a; margin: 10px 0; }
        .rule       { border: none; border-top: 1px solid #d8d8d8; margin: 8px 0; }

        /* ─── Section heading ─────────────────────────── */
        .sh {
            font-size: 6.5pt; font-weight: bold; letter-spacing: 2px;
            text-transform: uppercase; color: #888888;
            border-bottom: 1px solid #dddddd; padding-bottom: 3px;
            margin-bottom: 6px; margin-top: 10px;
        }
        .sh0 { margin-top: 0; }

        /* ─── Two-column ──────────────────────────────── */
        .two-col { display: table; width: 100%; }
        .col-L   { display: table-cell; vertical-align: top; width: 48%; padding-right: 16px; }
        .col-R   { display: table-cell; vertical-align: top; width: 52%; padding-left: 16px; border-left: 1px solid #e4e4e4; }

        /* ─── Info table ──────────────────────────────── */
        .it { width: 100%; border-collapse: collapse; }
        .it td {
            padding: 3.5px 0; font-size: 8.5pt; vertical-align: top;
            border-bottom: 1px solid #f0f0f0; line-height: 1.4;
        }
        .it td.k { width: 46%; color: #666666; font-size: 8pt; padding-right: 5px; }
        .it td.v { color: #111111; font-weight: 500; }
        .it tr:last-child td { border-bottom: none; }

        /* ─── Financial table ─────────────────────────── */
        .ft { width: 100%; border-collapse: collapse; margin-top: 5px; }
        .ft thead tr { background: #eeeeee; }
        .ft th {
            font-size: 7.5pt; font-weight: bold; color: #444444;
            text-align: left; padding: 6px 9px;
            border-top: 1px solid #cccccc; border-bottom: 2px solid #bbbbbb;
        }
        .ft th.r { text-align: right; }
        .ft td   { padding: 6px 9px; font-size: 8.5pt; color: #111111; border-bottom: 1px solid #ebebeb; }
        .ft td.r { text-align: right; }
        .ft td.k { color: #444444; }
        .ft tr.total td {
            border-top: 2px solid #1a1a1a; border-bottom: 2px solid #1a1a1a;
            font-size: 9.5pt; font-weight: bold; color: #111111; background: #eeeeee;
        }

        /* ─── Statement ───────────────────────────────── */
        .statement {
            font-size: 9pt; color: #222222; line-height: 1.7;
            padding: 12px 14px; background: #fafafa; border: 1px solid #e4e4e4;
            border-radius: 3px; margin-top: 8px;
        }

        /* ─── Signature ───────────────────────────────── */
        .sig-row  { display: table; width: 100%; margin-top: 20px; }
        .sig-cell { display: table-cell; vertical-align: top; padding-right: 20px; }
        .sig-last { display: table-cell; vertical-align: top; padding-left: 20px; }
        .sig-gap  { display: table-cell; width: 8%; }
        .sig-line { border-top: 1px solid #999999; margin-bottom: 6px; }
        .sig-lbl  { font-size: 7pt; color: #777777; margin-bottom: 1px; }
        .sig-val  { font-size: 8.5pt; font-weight: 500; color: #111111; min-height: 13px; }
        .sig-sub  { font-size: 7.5pt; color: #666666; margin-top: 2px; }

        /* ─── Footer note ─────────────────────────────── */
        .doc-footer {
            font-size: 7pt; color: #aaaaaa; text-align: center;
            margin-top: 14px; padding-top: 8px; border-top: 1px solid #eeeeee;
        }
    </style>
</head>
<body>
<div class="page">

    @php
        $sourceType = $contract ? 'Main Contract' : ($tradePackage ? 'Trade Package' : null);
        $contractingParty = $contract->party_name ?? $tradePackage->contractor_name ?? null;
        $sourceName = $contract->title ?? $tradePackage->name ?? '—';
        $sourceRef  = $contract->reference_number ?? $tradePackage->package_code ?? null;

        $certDateFmt = $finalAccount->final_certificate_issued_at
            ? \Carbon\Carbon::parse($finalAccount->final_certificate_issued_at)->format('d M Y')
            : now()->format('d M Y');

        $disputeExpiryFmt = $finalAccount->dispute_window_expires_at
            ? \Carbon\Carbon::parse($finalAccount->dispute_window_expires_at)->format('d M Y')
            : null;
    @endphp

    {{-- Title ─────────────────────────────────────────────────────────── --}}
    <div class="title-row">
        <div class="title-L">
            <div class="doc-label">Final Certificate</div>
            <h1>{{ $certificateNumber }}</h1>
            <div class="title-meta">
                <strong>Final Account Reference:</strong> {{ $finalAccount->reference }}
            </div>
            <div class="title-meta">
                <strong>Certificate Date:</strong> {{ $certDateFmt }}
            </div>
        </div>
        <div class="title-R">
            <span class="chip">Final Certificate Issued</span>
        </div>
    </div>

    <hr class="rule-heavy">

    {{-- Project + Contract ──────────────────────────────────────────────── --}}
    <div class="two-col">
        <div class="col-L">
            <div class="sh sh0">Project Details</div>
            <table class="it">
                <tr><td class="k">Project Name</td><td class="v">{{ $project->name }}</td></tr>
                @if($project->code)
                <tr><td class="k">Project Number</td><td class="v">{{ $project->code }}</td></tr>
                @endif
                @php $addr = implode(', ', array_filter([$project->address, $project->city, $project->postcode])); @endphp
                @if($addr)
                <tr><td class="k">Site Address</td><td class="v">{{ $addr }}</td></tr>
                @endif
            </table>
        </div>
        <div class="col-R">
            <div class="sh sh0">Commercial Source: {{ $sourceType ?? '—' }}</div>
            <table class="it">
                <tr><td class="k">{{ $contract ? 'Contract Title' : 'Package Name' }}</td><td class="v">{{ $sourceName }}</td></tr>
                @if($sourceRef)
                <tr><td class="k">Reference</td><td class="v">{{ $sourceRef }}</td></tr>
                @endif
                @if($contractingParty)
                <tr><td class="k">{{ $contract ? 'Employer' : 'Contractor' }}</td><td class="v">{{ $contractingParty }}</td></tr>
                @endif
            </table>
        </div>
    </div>

    <hr class="rule">

    {{-- Final Figures ───────────────────────────────────────────────────── --}}
    <div class="sh">Final Commercial Position</div>
    <table class="ft">
        <thead>
            <tr>
                <th style="width:63%">Description</th>
                <th class="r" style="width:37%">Amount ({{ $currency }})</th>
            </tr>
        </thead>
        <tbody>
            <tr><td class="k">Final Contract Sum</td><td class="r">{{ number_format((float) $finalAccount->adjusted_contract_sum, 2) }}</td></tr>
            <tr><td class="k">Final Amount Certified</td><td class="r">{{ number_format((float) $finalAccount->certified_to_date, 2) }}</td></tr>
            <tr><td class="k">Retention Released</td><td class="r">{{ number_format((float) $finalAccount->retention_released, 2) }}</td></tr>
            <tr class="total"><td>Final Balance Due</td><td class="r">{{ number_format((float) $finalAccount->final_balance_due, 2) }}</td></tr>
        </tbody>
    </table>

    <hr class="rule">

    {{-- Statement ──────────────────────────────────────────────────────── --}}
    <div class="sh">Certification Statement</div>
    <div class="statement">
        This Final Certificate confirms that the Final Account referenced above,
        {{ $finalAccount->reference }}, for {{ $sourceName }}, has been agreed between the parties
        and represents the final and conclusive commercial position under the contract, save as otherwise
        provided by the terms of the contract regarding conclusivity.
        @if($disputeExpiryFmt)
        The period for either party to commence proceedings challenging the effect of this Final Certificate
        expires on {{ $disputeExpiryFmt }}.
        @endif
    </div>

    {{-- Signature block ─────────────────────────────────────────────────── --}}
    <div class="sig-row">
        <div class="sig-cell">
            <div class="sig-line"></div>
            <div class="sig-lbl">Issued By</div>
            <div class="sig-val">{{ $issuedBy?->name ?: 'Generated electronically by SureSign Contracts' }}</div>
            <div class="sig-sub">Date: {{ $certDateFmt }}</div>
        </div>
        <div class="sig-gap"></div>
        <div class="sig-last">
            <div class="sig-line"></div>
            <div class="sig-lbl">Received By</div>
            <div class="sig-val">&nbsp;</div>
            <div class="sig-sub">Date: &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</div>
        </div>
    </div>

    <div class="doc-footer">
        Generated electronically by SureSign Contracts &nbsp;·&nbsp; {{ now()->format('d M Y, H:i') }}
        &nbsp;·&nbsp; Certificate: {{ $certificateNumber }} &nbsp;·&nbsp; Final Account Ref: {{ $finalAccount->reference }}
    </div>

</div>
</body>
</html>
