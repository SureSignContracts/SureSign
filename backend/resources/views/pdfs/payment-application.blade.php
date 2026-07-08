<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Payment Application #{{ $paymentApplication->application_number }}</title>
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
            font-size: 6.5pt;
            font-weight: bold;
            letter-spacing: 2px;
            text-transform: uppercase;
            color: #888888;
            margin-bottom: 4px;
        }
        h1 {
            font-size: 16pt;
            font-weight: bold;
            color: #111111;
            letter-spacing: -0.3px;
            line-height: 1.15;
        }
        .title-meta {
            font-size: 8pt;
            color: #555555;
            margin-top: 4px;
            line-height: 1.6;
        }
        .title-meta strong { color: #111111; }

        /* ─── Status chip ─────────────────────────────── */
        .chip {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 3px;
            font-size: 7pt;
            font-weight: bold;
            letter-spacing: 1px;
            text-transform: uppercase;
        }
        .chip-draft         { border: 1.5px solid #aaaaaa; color: #888888; }
        .chip-submitted     { border: 1.5px solid #222222; color: #222222; }
        .chip-pending       { border: 1.5px solid #555555; color: #555555; }
        .chip-certified-app { border: 1.5px solid #222222; color: #222222; background: #f4f4f4; }
        .chip-cancelled     { border: 1.5px solid #aaaaaa; color: #aaaaaa; }

        /* ─── Dividers ────────────────────────────────── */
        .rule-heavy { border: none; border-top: 2px solid #1a1a1a; margin: 10px 0; }
        .rule       { border: none; border-top: 1px solid #d8d8d8; margin: 8px 0;  }

        /* ─── Section heading ─────────────────────────── */
        .sh {
            font-size: 6.5pt;
            font-weight: bold;
            letter-spacing: 2px;
            text-transform: uppercase;
            color: #888888;
            border-bottom: 1px solid #dddddd;
            padding-bottom: 3px;
            margin-bottom: 6px;
            margin-top: 10px;
        }
        .sh0 { margin-top: 0; }

        /* ─── Two-column ──────────────────────────────── */
        .two-col  { display: table; width: 100%; }
        .col-L    { display: table-cell; vertical-align: top; width: 48%; padding-right: 16px; }
        .col-R    { display: table-cell; vertical-align: top; width: 52%; padding-left: 16px; border-left: 1px solid #e4e4e4; }

        /* ─── Info table ──────────────────────────────── */
        .it { width: 100%; border-collapse: collapse; }
        .it td {
            padding: 3.5px 0;
            font-size: 8.5pt;
            vertical-align: top;
            border-bottom: 1px solid #f0f0f0;
            line-height: 1.4;
        }
        .it td.k { width: 46%; color: #666666; font-size: 8pt;  padding-right: 5px; }
        .it td.v { color: #111111; font-weight: 500; }
        .it tr:last-child td { border-bottom: none; }

        /* ─── Financial table ─────────────────────────── */
        .ft { width: 100%; border-collapse: collapse; margin-top: 5px; }
        .ft thead tr { background: #eeeeee; }
        .ft th {
            font-size: 7.5pt; font-weight: bold; color: #444444;
            text-align: left; padding: 6px 9px;
            border-top: 1px solid #cccccc; border-bottom: 2px solid #bbbbbb;
            letter-spacing: 0.3px;
        }
        .ft th.r { text-align: right; }
        .ft td   { padding: 6px 9px; font-size: 8.5pt; color: #111111; border-bottom: 1px solid #ebebeb; }
        .ft td.r { text-align: right; }
        .ft td.k { color: #444444; }
        .ft tr.sub td  { border-top: 1px solid #cccccc; font-weight: 600; background: #f4f4f4; }
        .ft tr.total td{
            border-top: 2px solid #1a1a1a; border-bottom: 2px solid #1a1a1a;
            font-size: 9.5pt; font-weight: bold; color: #111111; background: #eeeeee;
        }
        .ft tr.dim td  { color: #555555; }

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
            margin-top: 16px; padding-top: 8px; border-top: 1px solid #eeeeee;
        }
    </style>
</head>
<body>
<div class="page">

    @php
        /* References */
        $seqPad   = str_pad($paymentApplication->application_number, 3, '0', STR_PAD_LEFT);
        $rawCode  = $project->code ?: preg_replace('/[^A-Z0-9\-]/i', '-', $project->name);
        $projCode = strtoupper(substr(trim($rawCode, '-'), 0, 15));
        $appRef   = $paymentApplication->reference ?: "PA-{$projCode}-{$seqPad}";

        /* Financials */
        $gross     = (float)($paymentApplication->gross_valuation        ?? 0);
        $retention = (float)($paymentApplication->less_retention         ?? 0);
        $prevPmts  = (float)($paymentApplication->less_previous_payments ?? 0);
        $amtDue    = (float)($paymentApplication->amount_due             ?? 0);
        $netVal    = $gross - $retention;

        /* Dates */
        $appDate  = $paymentApplication->application_date;
        $dueDate  = $paymentApplication->due_date;
        $submAt   = $paymentApplication->submitted_at;

        /* Contract / source */
        $contract = $paymentApplication->contract;
        $tradePkg = $paymentApplication->tradePackage;
        $sourceType = $contract ? 'Main Contract' : ($tradePkg ? 'Trade Package' : null);
        $contractingParty = $contract?->party_name ?? $tradePkg?->contractor_name ?? null;

        /* Truncate long free-text fields */
        $formOfContract = null;
        if ($contract?->form_of_contract) {
            $foc = $contract->form_of_contract;
            $formOfContract = mb_strlen($foc) > 80 ? mb_substr($foc, 0, 77) . '…' : $foc;
        }

        /* Status */
        $statusMap = [
            'draft'     => ['label' => 'Draft',                'chip' => 'chip-draft'],
            'submitted' => ['label' => 'Submitted',             'chip' => 'chip-submitted'],
            'certified' => ['label' => 'Certified Application', 'chip' => 'chip-certified-app'],
            'paid'      => ['label' => 'Certified Application', 'chip' => 'chip-certified-app'],
            'disputed'  => ['label' => 'Pending Review',        'chip' => 'chip-pending'],
            'cancelled' => ['label' => 'Cancelled',             'chip' => 'chip-cancelled'],
        ];
        $st = $statusMap[$paymentApplication->status ?? 'draft'] ?? ['label' => 'Pending Review', 'chip' => 'chip-pending'];

        /* Prepared by */
        $creatorName = $paymentApplication->creator?->name ?? null;
        $creatorRole = $paymentApplication->creator?->roles?->first()?->name ?? null;
    @endphp

    {{-- Title ─────────────────────────────────────────────────────────── --}}
    <div class="title-row">
        <div class="title-L">
            <div class="doc-label">Payment Application</div>
            <h1>Application No.&nbsp;{{ $paymentApplication->application_number }}</h1>
            <div class="title-meta">
                <strong>Reference:</strong> {{ $appRef }}
                &nbsp;&nbsp;·&nbsp;&nbsp;
                <strong>Date:</strong> {{ $appDate?->format('d M Y') ?? now()->format('d M Y') }}
                @if($dueDate)&nbsp;&nbsp;·&nbsp;&nbsp;<strong>Due:</strong> {{ $dueDate->format('d M Y') }}@endif
            </div>
        </div>
        <div class="title-R">
            <span class="chip {{ $st['chip'] }}">{{ $st['label'] }}</span>
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
                <tr><td class="k">Application Date</td><td class="v">{{ $appDate?->format('d M Y') ?? '—' }}</td></tr>
                <tr><td class="k">Payment Due Date</td><td class="v">{{ $dueDate?->format('d M Y') ?? '—' }}</td></tr>
                @if($submAt)
                <tr><td class="k">Date Submitted</td><td class="v">{{ $submAt->format('d M Y') }}</td></tr>
                @endif
            </table>
        </div>
        <div class="col-R">
            <div class="sh sh0">Commercial Source: {{ $sourceType ?? '—' }}</div>
            @if($contract)
            <table class="it">
                <tr><td class="k">Contract Title</td><td class="v">{{ $contract->title }}</td></tr>
                @if($contract->reference_number)
                <tr><td class="k">Contract Reference</td><td class="v">{{ $contract->reference_number }}</td></tr>
                @endif
                @if($formOfContract)
                <tr><td class="k">Form of Contract</td><td class="v">{{ $formOfContract }}</td></tr>
                @endif
                @if($contractingParty)
                <tr><td class="k">Contracting Party</td><td class="v">{{ $contractingParty }}</td></tr>
                @endif
                @if($contract->contract_sum)
                <tr><td class="k">Contract Sum</td><td class="v">£{{ number_format($contract->contract_sum, 2) }}</td></tr>
                @endif
                @if($contract->retention_percentage)
                <tr><td class="k">Retention Rate</td><td class="v">{{ $contract->retention_percentage }}%</td></tr>
                @endif
            </table>
            @elseif($tradePkg)
            <table class="it">
                <tr><td class="k">Package</td><td class="v">{{ $tradePkg->name }}</td></tr>
                @if($contractingParty)
                <tr><td class="k">Contractor</td><td class="v">{{ $contractingParty }}</td></tr>
                @endif
            </table>
            @else
            <p style="font-size:8pt;color:#999;font-style:italic;">No contract linked.</p>
            @endif
        </div>
    </div>

    <hr class="rule">

    {{-- Application Details ──────────────────────────────────────────────── --}}
    <div class="sh">Application Details</div>
    <div class="two-col">
        <div class="col-L">
            <table class="it">
                <tr><td class="k">Application Number</td><td class="v">{{ $paymentApplication->application_number }}</td></tr>
                <tr><td class="k">Application Reference</td><td class="v">{{ $appRef }}</td></tr>
                <tr><td class="k">Status</td><td class="v">{{ $st['label'] }}</td></tr>
            </table>
        </div>
        <div class="col-R">
            <table class="it">
                <tr><td class="k">Payment Due Date</td><td class="v">{{ $dueDate?->format('d M Y') ?? '—' }}</td></tr>
                <tr><td class="k">Final Date for Payment</td><td class="v">—</td></tr>
                <tr><td class="k">Prepared By</td><td class="v">{{ $creatorName ?? '—' }}</td></tr>
            </table>
        </div>
    </div>

    <hr class="rule">

    {{-- Financial Summary ────────────────────────────────────────────────── --}}
    <div class="sh">Financial Summary</div>
    <table class="ft">
        <thead>
            <tr>
                <th style="width:63%">Description</th>
                <th class="r" style="width:37%">Amount (£)</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td class="k">Current Gross Valuation</td>
                <td class="r">{{ number_format($gross, 2) }}</td>
            </tr>
            <tr class="dim">
                <td class="k">Less: Retention Deducted</td>
                <td class="r">({{ number_format($retention, 2) }})</td>
            </tr>
            <tr class="sub">
                <td class="k">Net Valuation</td>
                <td class="r">{{ number_format($netVal, 2) }}</td>
            </tr>
            <tr class="dim">
                <td class="k">Less: Previous Payments</td>
                <td class="r">({{ number_format($prevPmts, 2) }})</td>
            </tr>
            <tr class="total">
                <td>Amount Applied For</td>
                <td class="r">{{ number_format($amtDue, 2) }}</td>
            </tr>
        </tbody>
    </table>

    @if($paymentApplication->notes)
    <div class="sh">Notes</div>
    <p style="font-size:8.5pt;color:#444;line-height:1.5;padding:6px 0;">{{ $paymentApplication->notes }}</p>
    @endif

    {{-- Signature ────────────────────────────────────────────────────────── --}}
    <div class="sig-row">
        <div class="sig-cell">
            <div class="sig-line"></div>
            <div class="sig-lbl">Prepared By</div>
            <div class="sig-val">{{ $creatorName ?? '—' }}</div>
            @if($creatorRole)<div class="sig-sub">Role: {{ $creatorRole }}</div>@endif
            <div class="sig-sub">Date: {{ $submAt?->format('d M Y') ?? $appDate?->format('d M Y') ?? now()->format('d M Y') }}</div>
        </div>
        <div class="sig-gap"></div>
        <div class="sig-last">
            <div class="sig-line"></div>
            <div class="sig-lbl">Authorised By</div>
            <div class="sig-val">&nbsp;</div>
            <div class="sig-sub">Role: &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</div>
            <div class="sig-sub">Date: &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</div>
        </div>
    </div>

    <div class="doc-footer">
        Generated electronically by SureSign Contracts &nbsp;·&nbsp; {{ now()->format('d M Y, H:i') }} &nbsp;·&nbsp; Ref: {{ $appRef }}
    </div>

</div>
</body>
</html>
