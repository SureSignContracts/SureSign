<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Final Account Statement: {{ $finalAccount->reference }}</title>
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

        .page { padding: 18px 44px 28px; position: relative; }

        /* ─── Draft watermark ─────────────────────────── */
        .watermark {
            position: fixed;
            top: 320px;
            left: 90px;
            font-size: 64pt;
            font-weight: bold;
            color: #e5b800;
            opacity: 0.16;
            transform: rotate(-28deg);
            letter-spacing: 4px;
            z-index: 0;
        }

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
        .chip.draft { border-color: #e5b800; color: #8a6d00; background: #fff8e1; }
        .chip.agreed { border-color: #1a7a3a; color: #1a7a3a; background: #eafaf0; }

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
        .ft tr.sub td   { border-top: 1px solid #cccccc; font-weight: 600; background: #f4f4f4; }
        .ft tr.total td {
            border-top: 2px solid #1a1a1a; border-bottom: 2px solid #1a1a1a;
            font-size: 9.5pt; font-weight: bold; color: #111111; background: #eeeeee;
        }
        .ft tr.dim td   { color: #555555; }
        .ft tr.neg td.r { color: #a11a1a; }

        /* ─── Line items ──────────────────────────────── */
        .li-cat { font-size: 7.5pt; font-weight: bold; color: #555555; text-transform: uppercase; letter-spacing: 0.5px; padding-top: 8px; }

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

    @if($isDraft)
    <div class="watermark">DRAFT</div>
    @endif

    @php
        $sourceType = $contract ? 'Main Contract' : ($tradePackage ? 'Trade Package' : null);
        $contractingParty = $contract->party_name ?? $tradePackage->contractor_name ?? null;
        $sourceName = $contract->title ?? $tradePackage->name ?? '—';
        $sourceRef  = $contract->reference_number ?? $tradePackage->package_code ?? null;

        $agreedDateFmt = $finalAccount->agreed_at ? \Carbon\Carbon::parse($finalAccount->agreed_at)->format('d M Y') : null;

        $categoryLabels = [
            'contract_sum'       => 'Contract Sum',
            'approved_variation' => 'Approved Variations',
            'loss_and_expense'   => 'Loss & Expense',
            'daywork'            => 'Dayworks',
            'provisional_sum'    => 'Provisional Sums',
            'prime_cost_sum'     => 'Prime Cost Sums',
            'contra_charge'      => 'Contra Charges',
            'deduction'          => 'Deductions',
            'other'              => 'Other Adjustments',
        ];
        $negativeCategories = ['contra_charge', 'deduction'];
        $itemsByCategory = $items->groupBy('category');
    @endphp

    {{-- Title ─────────────────────────────────────────────────────────── --}}
    <div class="title-row">
        <div class="title-L">
            <div class="doc-label">{{ $isDraft ? 'Draft Final Account Statement' : 'Final Account Statement' }}</div>
            <h1>{{ $finalAccount->reference }}</h1>
            <div class="title-meta">
                <strong>{{ $sourceType ?? 'Source' }}:</strong> {{ $sourceName }}
                @if($sourceRef) &nbsp;&nbsp;·&nbsp;&nbsp; <strong>Ref:</strong> {{ $sourceRef }} @endif
            </div>
            @if($agreedDateFmt)
            <div class="title-meta"><strong>Agreed:</strong> {{ $agreedDateFmt }}</div>
            @endif
        </div>
        <div class="title-R">
            <span class="chip {{ $isDraft ? 'draft' : 'agreed' }}">
                {{ $isDraft ? 'Draft' : str_replace('_', ' ', $finalAccount->status) }}
            </span>
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
                <tr><td class="k">{{ $contract ? 'Contracting Party' : 'Contractor' }}</td><td class="v">{{ $contractingParty }}</td></tr>
                @endif
            </table>
        </div>
    </div>

    <hr class="rule">

    {{-- Commercial Summary ─────────────────────────────────────────────── --}}
    <div class="sh">Commercial Summary</div>
    <table class="ft">
        <thead>
            <tr>
                <th style="width:63%">Description</th>
                <th class="r" style="width:37%">Amount ({{ $currency }})</th>
            </tr>
        </thead>
        <tbody>
            <tr><td class="k">Original Contract Sum</td><td class="r">{{ number_format($totals['original_contract_sum'], 2) }}</td></tr>
            <tr class="dim"><td class="k">Approved Variations</td><td class="r">{{ number_format($totals['approved_variations_total'], 2) }}</td></tr>
            <tr class="dim"><td class="k">Loss &amp; Expense</td><td class="r">{{ number_format($totals['loss_and_expense_total'], 2) }}</td></tr>
            <tr class="dim"><td class="k">Dayworks</td><td class="r">{{ number_format($totals['dayworks_total'], 2) }}</td></tr>
            <tr class="dim"><td class="k">Provisional Sum Adjustments</td><td class="r">{{ number_format($totals['provisional_sum_adjustment'], 2) }}</td></tr>
            <tr class="dim"><td class="k">Prime Cost Sum Adjustments</td><td class="r">{{ number_format($totals['prime_cost_sum_adjustment'], 2) }}</td></tr>
            <tr class="dim neg"><td class="k">Contra Charges</td><td class="r">({{ number_format($totals['contra_charges_total'], 2) }})</td></tr>
            <tr class="dim"><td class="k">Other Adjustments</td><td class="r">{{ number_format($totals['other_adjustments_total'], 2) }}</td></tr>
            <tr class="sub"><td>Adjusted Contract Sum</td><td class="r">{{ number_format($totals['adjusted_contract_sum'], 2) }}</td></tr>
            <tr><td class="k">Certified To Date</td><td class="r">{{ number_format($totals['certified_to_date'], 2) }}</td></tr>
            <tr><td class="k">Paid To Date</td><td class="r">{{ number_format($totals['paid_to_date'], 2) }}</td></tr>
            <tr><td class="k">Retention Held</td><td class="r">{{ number_format($totals['retention_held'], 2) }}</td></tr>
            <tr><td class="k">Retention Released</td><td class="r">{{ number_format($totals['retention_released'], 2) }}</td></tr>
            <tr><td class="k">Retention Outstanding</td><td class="r">{{ number_format($totals['retention_outstanding'], 2) }}</td></tr>
            <tr class="total"><td>Final Balance Due</td><td class="r">{{ number_format($totals['final_balance_due'], 2) }}</td></tr>
        </tbody>
    </table>

    <hr class="rule">

    {{-- Line Items ──────────────────────────────────────────────────────── --}}
    <div class="sh">Financial Breakdown: Line Items</div>
    @forelse($itemsByCategory as $category => $categoryItems)
    <div class="li-cat">{{ $categoryLabels[$category] ?? ucfirst(str_replace('_', ' ', $category)) }}</div>
    <table class="ft">
        <tbody>
            @foreach($categoryItems as $item)
            <tr class="{{ in_array($category, $negativeCategories) ? 'neg' : '' }}">
                <td class="k">{{ $item->description }}</td>
                <td class="r">
                    @if(in_array($category, $negativeCategories))
                        ({{ number_format(abs((float) $item->amount), 2) }})
                    @else
                        {{ number_format((float) $item->amount, 2) }}
                    @endif
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @empty
    <p style="font-size:8pt;color:#999;font-style:italic;">No line items recorded.</p>
    @endforelse

    @if($finalAccount->notes)
    <hr class="rule">
    <div class="sh">Notes</div>
    <p style="font-size:8.5pt;color:#333333;">{{ $finalAccount->notes }}</p>
    @endif

    {{-- Signature block ─────────────────────────────────────────────────── --}}
    <div class="sig-row">
        <div class="sig-cell">
            <div class="sig-line"></div>
            <div class="sig-lbl">Employer / Contract Administrator</div>
            <div class="sig-val">&nbsp;</div>
            <div class="sig-sub">Date: &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</div>
        </div>
        <div class="sig-gap"></div>
        <div class="sig-last">
            <div class="sig-line"></div>
            <div class="sig-lbl">Contractor / Subcontractor</div>
            <div class="sig-val">&nbsp;</div>
            <div class="sig-sub">Date: &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</div>
        </div>
    </div>

    <div class="doc-footer">
        Generated electronically by SureSign Contracts &nbsp;·&nbsp; {{ now()->format('d M Y, H:i') }}
        &nbsp;·&nbsp; Final Account Ref: {{ $finalAccount->reference }}
        @if($isDraft) &nbsp;·&nbsp; This is a draft document and does not represent an agreed commercial position. @endif
    </div>

</div>
</body>
</html>
