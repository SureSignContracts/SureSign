<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Payment Certificate: Application #{{ $paymentApplication->application_number }}</title>
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
            border: 1.5px solid #1a1a1a; color: #1a1a1a; background: #f0f0f0;
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

        /* ─── Financial / certification table ────────── */
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
        .ft tr.sub td  { border-top: 1px solid #cccccc; font-weight: 600; background: #f4f4f4; }
        .ft tr.total td{
            border-top: 2px solid #1a1a1a; border-bottom: 2px solid #1a1a1a;
            font-size: 9.5pt; font-weight: bold; color: #111111; background: #eeeeee;
        }
        .ft tr.dim td  { color: #555555; }
        .ft tr.diff td { color: #555555; font-style: italic; background: #fafafa; font-size: 8pt; }

        /* ─── Signature ───────────────────────────────── */
        .sig-row  { display: table; width: 100%; margin-top: 18px; }
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
        /* References */
        $seqPad   = str_pad($paymentApplication->application_number, 3, '0', STR_PAD_LEFT);
        $rawCode  = $project->code ?: preg_replace('/[^A-Z0-9\-]/i', '-', $project->name);
        $projCode = strtoupper(substr(trim($rawCode, '-'), 0, 15));
        $appRef   = $paymentApplication->reference ?: "PA-{$projCode}-{$seqPad}";
        $certRef  = $paymentApplication->certificate_reference ?: "PC-{$projCode}-{$seqPad}";

        /* Financials */
        $gross     = (float)($paymentApplication->gross_valuation        ?? 0);
        $retention = (float)($paymentApplication->less_retention         ?? 0);
        $prevPmts  = (float)($paymentApplication->less_previous_payments ?? 0);
        $amtApp    = (float)($paymentApplication->amount_due             ?? 0);
        $amtCert   = (float)($paymentApplication->certified_amount       ?? 0);
        $netVal    = $gross - $retention;
        $diff      = $amtApp - $amtCert;

        /* Dates */
        $appDate  = $paymentApplication->application_date;
        $certDate = $paymentApplication->certified_at ?? $paymentApplication->certified_date;
        $dueDate  = $paymentApplication->due_date;

        /* Contract / source */
        $contract = $paymentApplication->contract;
        $tradePkg = $paymentApplication->tradePackage;
        $sourceType = $contract ? 'Main Contract' : ($tradePkg ? 'Trade Package' : null);
        $contractingParty = $contract?->party_name ?? $tradePkg?->contractor_name ?? null;

        /* Truncate long form-of-contract text */
        $formOfContract = null;
        if ($contract?->form_of_contract) {
            $foc = $contract->form_of_contract;
            $formOfContract = mb_strlen($foc) > 80 ? mb_substr($foc, 0, 77) . '…' : $foc;
        }

        /* Certifier */
        $certifierName = $certifiedBy?->name ?: null;
        $certifierRole = $certifiedBy?->roles?->first()?->name ?? null;
        $certDateFmt   = $certDate ? \Carbon\Carbon::parse($certDate)->format('d M Y') : now()->format('d M Y');
    @endphp

    {{-- Title ─────────────────────────────────────────────────────────── --}}
    <div class="title-row">
        <div class="title-L">
            <div class="doc-label">Payment Certificate</div>
            <h1>Certificate No.&nbsp;{{ $paymentApplication->application_number }}</h1>
            <div class="title-meta">
                <strong>Certificate Ref:</strong> {{ $certRef }}
                &nbsp;&nbsp;·&nbsp;&nbsp;
                <strong>Related Application:</strong> {{ $appRef }}
            </div>
            <div class="title-meta">
                <strong>Certified:</strong> {{ $certDateFmt }}
            </div>
        </div>
        <div class="title-R">
            <span class="chip">Certified</span>
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
                <tr><td class="k">Final Date for Payment</td><td class="v">—</td></tr>
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

    {{-- Certification Summary ───────────────────────────────────────────── --}}
    <div class="sh">Certification Summary</div>
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
                <td class="k">Less: Retention Held</td>
                <td class="r">({{ number_format($retention, 2) }})</td>
            </tr>
            <tr class="sub">
                <td class="k">Net Valuation</td>
                <td class="r">{{ number_format($netVal, 2) }}</td>
            </tr>
            <tr class="dim">
                <td class="k">Less: Previous Certified Payments</td>
                <td class="r">({{ number_format($prevPmts, 2) }})</td>
            </tr>
            <tr>
                <td class="k">Amount Applied For</td>
                <td class="r">{{ number_format($amtApp, 2) }}</td>
            </tr>
            <tr class="total">
                <td>Amount Certified</td>
                <td class="r">{{ number_format($amtCert, 2) }}</td>
            </tr>
            <tr class="diff">
                <td class="k">
                    Difference
                    @if(abs($diff) < 0.005)(full application certified)
                    @elseif($diff > 0)({{ number_format(abs($diff), 2) }} below amount applied)
                    @else({{ number_format(abs($diff), 2) }} above amount applied)
                    @endif
                </td>
                <td class="r">{{ number_format($diff, 2) }}</td>
            </tr>
        </tbody>
    </table>

    <hr class="rule">

    {{-- Certificate details + Certifier ────────────────────────────────── --}}
    <div class="two-col">
        <div class="col-L">
            <div class="sh sh0">Certificate Details</div>
            <table class="it">
                <tr><td class="k">Certificate Reference</td><td class="v">{{ $certRef }}</td></tr>
                <tr><td class="k">Related Application</td><td class="v">{{ $appRef }}</td></tr>
                <tr><td class="k">Application Number</td><td class="v">{{ $paymentApplication->application_number }}</td></tr>
                <tr><td class="k">Certification Date</td><td class="v">{{ $certDateFmt }}</td></tr>
                @if($paymentApplication->certificate_notes)
                <tr><td class="k">Notes</td><td class="v">{{ $paymentApplication->certificate_notes }}</td></tr>
                @endif
            </table>
        </div>
        <div class="col-R">
            <div class="sh sh0">Certifier Details</div>
            <table class="it">
                <tr>
                    <td class="k">Certified By</td>
                    <td class="v">{{ $certifierName ?: 'Generated electronically by SureSign Contracts' }}</td>
                </tr>
                @if($certifierRole)
                <tr><td class="k">Role</td><td class="v">{{ $certifierRole }}</td></tr>
                @endif
                <tr><td class="k">Certification Date</td><td class="v">{{ $certDateFmt }}</td></tr>
            </table>
        </div>
    </div>

    {{-- Signature block ─────────────────────────────────────────────────── --}}
    <div class="sig-row">
        <div class="sig-cell">
            <div class="sig-line"></div>
            <div class="sig-lbl">Certified By</div>
            <div class="sig-val">{{ $certifierName ?: '—' }}</div>
            @if($certifierRole)<div class="sig-sub">Role: {{ $certifierRole }}</div>@endif
            <div class="sig-sub">Date: {{ $certDateFmt }}</div>
        </div>
        <div class="sig-gap"></div>
        <div class="sig-last">
            <div class="sig-line"></div>
            <div class="sig-lbl">Received By</div>
            <div class="sig-val">&nbsp;</div>
            <div class="sig-sub">Role: &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</div>
            <div class="sig-sub">Date: &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</div>
        </div>
    </div>

    <div class="doc-footer">
        Generated electronically by SureSign Contracts &nbsp;·&nbsp; {{ now()->format('d M Y, H:i') }}
        &nbsp;·&nbsp; Certificate Ref: {{ $certRef }} &nbsp;·&nbsp; Application: {{ $appRef }}
    </div>

</div>
</body>
</html>
