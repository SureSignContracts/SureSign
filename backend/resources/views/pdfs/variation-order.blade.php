<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Variation Order: {{ $variation->variation_number ?? 'Unreferenced' }}</title>
    <style>
        @page { margin-top: 145px; margin-bottom: 110px; margin-left: 0; margin-right: 0; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 10pt; color: #222222; background: #ffffff; line-height: 1.45; }
        .body-content { padding: 24px 48px 28px; }
        .doc-header { display: table; width: 100%; margin-bottom: 20px; }
        .doc-header-left, .doc-header-right { display: table-cell; vertical-align: top; }
        .doc-header-right { text-align: right; }
        .badge { display: inline-block; background: #7c3aed; color: #ffffff; font-size: 7.5pt; font-weight: bold; letter-spacing: 1px; text-transform: uppercase; padding: 3px 10px; border-radius: 3px; margin-bottom: 6px; }
        h1 { font-size: 16pt; font-weight: bold; color: #111111; margin-bottom: 2px; }
        .meta { font-size: 9pt; color: #888888; }
        hr { border: none; border-top: 1.5px solid #e8e8e8; margin: 16px 0; }
        .section-heading { font-size: 9pt; font-weight: bold; color: #b99566; letter-spacing: 0.5px; text-transform: uppercase; margin: 18px 0 8px; }
        .info-table { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        .info-table td { padding: 7px 10px; font-size: 9.5pt; vertical-align: top; border-bottom: 1px solid #f0f0f0; }
        .info-table td:first-child { width: 42%; color: #666666; font-weight: bold; }
        .info-table td:last-child { color: #222222; }
        .status-badge { display: inline-block; font-size: 8pt; font-weight: bold; letter-spacing: 0.5px; text-transform: uppercase; padding: 2px 8px; border-radius: 3px; }
        .status-pending   { background: #fef3c7; color: #92400e; }
        .status-approved  { background: #d1fae5; color: #065f46; }
        .status-rejected  { background: #fee2e2; color: #991b1b; }
        .status-withdrawn { background: #f3f4f6; color: #6b7280; }
        .description-box { background: #fafafa; border-left: 3px solid #b99566; padding: 12px 16px; border-radius: 2px; margin: 12px 0; font-size: 9.5pt; color: #333333; line-height: 1.6; }
        .financial-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        .financial-table td { padding: 8px 10px; font-size: 9.5pt; color: #333333; border-bottom: 1px solid #f0f0f0; }
        .financial-table td.label { color: #555555; }
        .financial-table td.amount { text-align: right; font-weight: bold; }
        .financial-table tr.total-row td { font-size: 11pt; font-weight: bold; color: #111111; background: #f5f0f8; border-top: 2px solid #7c3aed; }
        .signature-table { width: 100%; border-collapse: collapse; margin-top: 24px; }
        .signature-table td { width: 50%; vertical-align: bottom; padding: 0 12px 0 0; }
        .signature-table td:last-child { padding-left: 12px; padding-right: 0; }
        .sig-line { border-top: 1px solid #222222; margin-top: 40px; padding-top: 6px; font-size: 8.5pt; color: #555555; }
        .footer-note { font-size: 8pt; color: #aaaaaa; text-align: center; margin-top: 24px; }
    </style>
</head>
<body>
<div class="body-content">

    {{-- ── Header ── --}}
    <div class="doc-header">
        <div class="doc-header-left">
            <div class="badge">Variation Order</div>
            <h1>{{ $variation->title }}</h1>
            <div class="meta">
                Ref: {{ $variation->variation_number ?? '—' }}
                &nbsp;|&nbsp;
                Date: {{ $variation->variation_date ? \Carbon\Carbon::parse($variation->variation_date)->format('d M Y') : now()->format('d M Y') }}
                &nbsp;|&nbsp;
                <span class="status-badge status-{{ $variation->status ?? 'pending' }}">
                    {{ ucfirst($variation->status ?? 'Pending') }}
                </span>
            </div>
        </div>
        <div class="doc-header-right">
            @if(!empty($branding_logo_uri))
                <img src="{{ $branding_logo_uri }}" style="max-height:48px; max-width:160px;">
            @elseif(!empty($branding->company_display_name))
                <span style="font-size:11pt; font-weight:bold; color:#333;">{{ $branding->company_display_name }}</span>
            @endif
        </div>
    </div>

    <hr>

    {{-- ── Project & Contract Details ── --}}
    <div class="section-heading">Project &amp; Contract Details</div>
    <table class="info-table">
        <tr>
            <td>Project</td>
            <td>{{ $project->name }}@if($project->code) &nbsp;({{ $project->code }})@endif</td>
        </tr>
        <tr>
            <td>Contract</td>
            <td>{{ $variation->contract->title ?? '—' }}@if($variation->contract?->reference_number) &nbsp;(Ref: {{ $variation->contract->reference_number }})@endif</td>
        </tr>
        @if($variation->contract?->party_name)
        <tr>
            <td>Contractor</td>
            <td>{{ $variation->contract->party_name }}</td>
        </tr>
        @endif
        @if($variation->contract?->form_of_contract)
        <tr>
            <td>Form of Contract</td>
            <td>{{ $variation->contract->form_of_contract }}</td>
        </tr>
        @endif
    </table>

    {{-- ── Variation Details ── --}}
    <div class="section-heading">Variation Details</div>
    <table class="info-table">
        <tr>
            <td>Variation Number</td>
            <td>{{ $variation->variation_number ?? '—' }}</td>
        </tr>
        <tr>
            <td>Type</td>
            <td>{{ ucwords(str_replace('_', ' ', $variation->type ?? 'addition')) }}</td>
        </tr>
        <tr>
            <td>Status</td>
            <td>
                <span class="status-badge status-{{ $variation->status ?? 'pending' }}">
                    {{ ucfirst($variation->status ?? 'Pending') }}
                </span>
            </td>
        </tr>
        <tr>
            <td>Instructed By</td>
            <td>{{ $variation->creator->name ?? '—' }}</td>
        </tr>
        <tr>
            <td>Variation Date</td>
            <td>{{ $variation->variation_date ? \Carbon\Carbon::parse($variation->variation_date)->format('d M Y') : '—' }}</td>
        </tr>
    </table>

    {{-- ── Description ── --}}
    @if($variation->description)
    <div class="section-heading">Description of Works</div>
    <div class="description-box">{{ $variation->description }}</div>
    @endif

    {{-- ── Financial Summary ── --}}
    <div class="section-heading">Financial Summary</div>
    <table class="financial-table">
        @if($variation->quoted_amount)
        <tr>
            <td class="label">Quoted Amount</td>
            <td class="amount">{{ $currency }} {{ number_format((float) $variation->quoted_amount, 2) }}</td>
        </tr>
        @endif
        @if($variation->agreed_amount)
        <tr class="total-row">
            <td class="label">Agreed Amount</td>
            <td class="amount">{{ $currency }} {{ number_format((float) $variation->agreed_amount, 2) }}</td>
        </tr>
        @elseif($variation->quoted_amount)
        <tr class="total-row">
            <td class="label">Amount (Pending Agreement)</td>
            <td class="amount">{{ $currency }} {{ number_format((float) $variation->quoted_amount, 2) }}</td>
        </tr>
        @else
        <tr>
            <td class="label" colspan="2" style="color:#888888; font-style:italic;">No amount recorded</td>
        </tr>
        @endif
    </table>

    {{-- ── Signature Blocks ── --}}
    <table class="signature-table">
        <tr>
            <td>
                <div class="sig-line">
                    Instructed by (Contract Administrator)<br>
                    Name: ___________________________<br>
                    Date: ___________________________
                </div>
            </td>
            <td>
                <div class="sig-line">
                    Accepted by (Contractor)<br>
                    Name: ___________________________<br>
                    Date: ___________________________
                </div>
            </td>
        </tr>
    </table>

    <div class="footer-note">
        This Variation Order is issued under the terms of the Contract referenced above.
        Both parties should retain a signed copy for their records.
    </div>

</div>
</body>
</html>
