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
            font-size: 10pt;
            color: #222222;
            background: #ffffff;
        }

        .body-content { padding: 24px 48px 28px; }

        /* Header row */
        .doc-header {
            display: table;
            width: 100%;
            margin-bottom: 20px;
        }
        .doc-header-left, .doc-header-right {
            display: table-cell;
            vertical-align: top;
        }
        .doc-header-right {
            text-align: right;
        }

        .badge {
            display: inline-block;
            background: #b99566;
            color: #ffffff;
            font-size: 7.5pt;
            font-weight: bold;
            letter-spacing: 1px;
            text-transform: uppercase;
            padding: 3px 10px;
            border-radius: 3px;
            margin-bottom: 6px;
        }

        h1 { font-size: 16pt; font-weight: bold; color: #111111; margin-bottom: 2px; }
        .meta { font-size: 9pt; color: #888888; }

        hr { border: none; border-top: 1.5px solid #e8e8e8; margin: 16px 0; }

        /* Section heading */
        .section-heading {
            font-size: 9pt;
            font-weight: bold;
            color: #b99566;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            margin: 18px 0 8px;
        }

        /* Two-column info table */
        .info-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 14px;
        }
        .info-table td {
            padding: 7px 10px;
            font-size: 9.5pt;
            vertical-align: top;
            border-bottom: 1px solid #f0f0f0;
        }
        .info-table td:first-child {
            width: 42%;
            color: #666666;
            font-weight: bold;
        }
        .info-table td:last-child { color: #222222; }

        /* Financial summary table */
        .financial-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        .financial-table th {
            background: #f5f5f5;
            font-size: 8.5pt;
            font-weight: bold;
            color: #555555;
            text-align: left;
            padding: 8px 10px;
            border-bottom: 2px solid #e0e0e0;
        }
        .financial-table td {
            padding: 8px 10px;
            font-size: 9.5pt;
            color: #333333;
            border-bottom: 1px solid #f0f0f0;
        }
        .financial-table td.label { color: #555555; }
        .financial-table td.amount { text-align: right; font-weight: bold; }
        .financial-table tr.total-row td {
            font-size: 11pt;
            font-weight: bold;
            color: #111111;
            background: #f9f9f9;
            border-top: 2px solid #d0d0d0;
        }

        /* Status badge */
        .status-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 8.5pt;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .status-draft            { background: #f0ede8; color: #888888; }
        .status-submitted        { background: #fef9e7; color: #b7950b; }
        .status-certified        { background: #eafaf1; color: #1e8449; }
        .status-paid             { background: #e8f4fd; color: #1a5276; }
        .status-disputed         { background: #fef5e7; color: #d35400; }
        .status-pay_less_notice_issued { background: #fdecea; color: #c0392b; }

        /* Signature area */
        .signature-area {
            display: table;
            width: 100%;
            margin-top: 40px;
        }
        .signature-col {
            display: table-cell;
            width: 45%;
            padding: 0 10px;
            text-align: center;
        }
        .signature-line {
            border-top: 1px solid #aaaaaa;
            padding-top: 6px;
            font-size: 8.5pt;
            color: #888888;
        }

        .footer-note {
            font-size: 8pt;
            color: #aaaaaa;
            text-align: center;
            margin-top: 20px;
        }
    </style>
</head>
<body>
<div class="body-content">

    {{-- Document header --}}
    <div class="doc-header">
        <div class="doc-header-left">
            <div class="badge">Payment Application</div>
            <h1>Application #{{ $paymentApplication->application_number }}</h1>
            <div class="meta">
                Ref: {{ $paymentApplication->reference ?? 'N/A' }}
                &nbsp;|&nbsp;
                Date: {{ $paymentApplication->application_date?->format('d M Y') ?? now()->format('d M Y') }}
            </div>
        </div>
        <div class="doc-header-right">
            @php
                $statusClass = 'status-' . str_replace(' ', '_', $paymentApplication->status ?? 'draft');
            @endphp
            <span class="status-badge {{ $statusClass }}">
                {{ ucwords(str_replace('_', ' ', $paymentApplication->status ?? 'draft')) }}
            </span>
        </div>
    </div>

    <hr>

    {{-- Project & Contract info --}}
    <div class="section-heading">Project Information</div>
    <table class="info-table">
        <tr>
            <td>Project Name</td>
            <td>{{ $project->name }}</td>
        </tr>
        <tr>
            <td>Project Number</td>
            <td>{{ $project->code ?? '—' }}</td>
        </tr>
        @if($paymentApplication->contract)
        <tr>
            <td>Contract</td>
            <td>{{ $paymentApplication->contract->title }}</td>
        </tr>
        <tr>
            <td>Contract Reference</td>
            <td>{{ $paymentApplication->contract->reference_number ?? '—' }}</td>
        </tr>
        <tr>
            <td>Contracting Party</td>
            <td>{{ $paymentApplication->contract->party_name ?? '—' }}</td>
        </tr>
        @endif
        <tr>
            <td>Project Address</td>
            <td>{{ collect([$project->address, $project->city, $project->state, $project->postcode])->filter()->implode(', ') ?: '—' }}</td>
        </tr>
    </table>

    {{-- Application details --}}
    <div class="section-heading">Application Details</div>
    <table class="info-table">
        <tr>
            <td>Application Number</td>
            <td>#{{ $paymentApplication->application_number }}</td>
        </tr>
        <tr>
            <td>Application Date</td>
            <td>{{ $paymentApplication->application_date?->format('d M Y') ?? '—' }}</td>
        </tr>
        <tr>
            <td>Due Date</td>
            <td>{{ $paymentApplication->due_date ? \Carbon\Carbon::parse($paymentApplication->due_date)->format('d M Y') : '—' }}</td>
        </tr>
        <tr>
            <td>Prepared By</td>
            <td>{{ $paymentApplication->creator?->name ?? '—' }}</td>
        </tr>
    </table>

    {{-- Financial summary --}}
    <div class="section-heading">Financial Summary</div>
    <table class="financial-table">
        <thead>
            <tr>
                <th style="width:60%">Description</th>
                <th style="width:40%; text-align:right">Amount</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td class="label">Gross Valuation</td>
                <td class="amount">£{{ number_format($paymentApplication->gross_valuation ?? 0, 2) }}</td>
            </tr>
            <tr>
                <td class="label">Less: Retention</td>
                <td class="amount">— £{{ number_format($paymentApplication->less_retention ?? 0, 2) }}</td>
            </tr>
            <tr>
                <td class="label">Less: Previous Payments</td>
                <td class="amount">— £{{ number_format($paymentApplication->less_previous_payments ?? 0, 2) }}</td>
            </tr>
            <tr class="total-row">
                <td class="label">Amount Due This Application</td>
                <td class="amount">£{{ number_format($paymentApplication->amount_due ?? 0, 2) }}</td>
            </tr>
            @if($paymentApplication->certified_amount !== null)
            <tr>
                <td class="label">Certified Amount</td>
                <td class="amount" style="color: #1e8449;">£{{ number_format($paymentApplication->certified_amount, 2) }}</td>
            </tr>
            @endif
            @if($paymentApplication->paid_amount !== null)
            <tr>
                <td class="label">Paid Amount</td>
                <td class="amount" style="color: #1a5276;">£{{ number_format($paymentApplication->paid_amount, 2) }}</td>
            </tr>
            @endif
        </tbody>
    </table>

    {{-- Notes --}}
    @if($paymentApplication->notes)
    <div class="section-heading">Notes</div>
    <p style="font-size:9.5pt; color:#444; line-height:1.6;">{{ $paymentApplication->notes }}</p>
    @endif

    {{-- Signature area --}}
    <div class="signature-area">
        <div class="signature-col">
            <div style="height: 40px;"></div>
            <div class="signature-line">Prepared by</div>
        </div>
        <div class="signature-col"></div>
        <div class="signature-col">
            <div style="height: 40px;"></div>
            <div class="signature-line">Authorised by</div>
        </div>
    </div>

    <div class="footer-note">
        Generated by SureSign &nbsp;|&nbsp; {{ now()->format('d M Y H:i') }}
    </div>

</div>
</body>
</html>
