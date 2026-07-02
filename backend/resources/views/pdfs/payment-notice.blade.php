<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Payment Notice — Application #{{ $paymentNotice->paymentApplication?->application_number ?? '—' }}</title>
    <style>
        @page { size: A4; margin: 12mm; }

        * { margin: 0; padding: 0; }

        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 8.5pt;
            color: #000000;
            background: #ffffff;
        }

        /* ── OUTER BOX ── */
        .notice-wrapper {
            border: 1pt solid #000000;
            padding: 8mm 10mm;
            box-sizing: border-box;
            min-height: 260mm;
        }

        /* ── HEADER ── */
        .notice-header-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 5mm;
        }
        .header-left-cell {
            width: 55%;
            vertical-align: top;
            padding: 0;
        }
        .header-right-cell {
            width: 45%;
            vertical-align: top;
            text-align: right;
            padding: 0;
        }
        .company-logo {
            width: 38mm;
            height: auto;
            display: block;
            margin-left: auto;
        }
        .recipient-email { font-size: 8pt; }
        .recipient-attention {
            font-size: 9pt;
            font-weight: bold;
            text-decoration: underline;
        }
        .recipient-address { font-size: 8.5pt; line-height: 1.4; }
        .payment-notice-title {
            font-size: 12pt;
            font-weight: bold;
            text-decoration: underline;
            margin-top: 4mm;
            display: block;
        }
        .contractor-address {
            font-size: 8.5pt;
            text-align: right;
            line-height: 1.5;
            margin-top: 3mm;
        }
        .contractor-tel-fax {
            font-size: 8.5pt;
            text-align: right;
            margin-top: 2mm;
        }

        /* ── CONTRACT INFO BOX ── */
        .contract-info-box {
            border: 0.75pt solid #000000;
            padding: 3mm 6mm;
            margin: 0 0 3mm 0;
            text-align: center;
            font-size: 9pt;
        }
        .contract-info-row {
            font-weight: bold;
            padding: 2pt 0;
            line-height: 1.6;
        }

        /* ── NOTIFICATION ROW ── */
        .notification-row {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 4mm;
            font-size: 8pt;
        }
        .notification-row td {
            border: 0.75pt solid #000000;
            padding: 2mm;
            vertical-align: top;
        }
        .notification-label-cell { width: 45%; }
        .notification-value-cell { width: 30%; }
        .notification-date-cell  { width: 25%; }

        /* ── BODY TEXT ── */
        .notice-paragraph {
            font-size: 8.5pt;
            margin: 0 0 3mm 0;
            line-height: 1.5;
        }
        .payment-sum-statement {
            font-size: 8.5pt;
            margin: 0 0 1mm 0;
        }
        .payment-sum-amount {
            font-size: 11pt;
            font-weight: bold;
        }
        .payment-sum-note {
            font-size: 8pt;
            font-style: italic;
            margin: 0 0 4mm 0;
        }

        /* ── VALUATION TABLE ── */
        .valuation-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 8.5pt;
            margin-bottom: 4mm;
        }
        .valuation-table td {
            padding: 2pt 3pt;
            border-bottom: 0.5pt solid #000000;
            vertical-align: middle;
        }
        .valuation-table .col-desc  { width: 80%; }
        .valuation-table .col-amount {
            width: 20%;
            text-align: right;
            white-space: nowrap;
        }

        /* ── POST-TABLE TEXT ── */
        .post-table-text {
            font-size: 8.5pt;
            text-align: center;
            margin: 2mm 0;
            line-height: 1.6;
        }

        /* ── SIGNATURE ── */
        .signature-block { font-size: 8.5pt; margin-top: 4mm; }
        .signature-space  { height: 10mm; display: block; }
        .signatory-name   { font-size: 8.5pt; }
        .signatory-title  { font-size: 8.5pt; font-weight: bold; }

        /* ── VAT RECEIPT ── */
        .vat-receipt-block {
            font-size: 8pt;
            text-align: center;
            margin-top: 5mm;
            line-height: 1.6;
        }
        .vat-receipt-title {
            font-weight: bold;
            text-decoration: underline;
            font-size: 9pt;
            display: block;
            margin-bottom: 2pt;
        }

        /* ── PAGE FOOTER (outside box) ── */
        .page-footer-reference {
            position: fixed;
            bottom: 4mm;
            right: 0;
            font-size: 7.5pt;
            color: #444444;
            text-align: right;
        }

        /* ── PREVENT DOMPDF AUTO-LINK STYLING ── */
        a, a:link, a:visited, a:hover, a:active {
            color: #000000;
            text-decoration: none;
        }
    </style>
</head>
<body>
@php
    $pa = $paymentNotice->paymentApplication;
    $isTradePackage = (bool) ($pa?->trade_package_id);
    $tp       = $pa?->tradePackage;
    $contract = $pa?->contract;
    $org      = $project->organization;
    $cur      = $currency ?? '£';

    // Valuation figures — sourced from PA; never recalculated here.
    $useBreakdown = (bool) ($pa?->use_breakdown ?? false);
    $measured  = (float) ($pa?->measured_works_total ?? 0);
    $linkedVar = (float) ($pa?->linked_variations_total ?? 0);
    $manualVar = (float) ($pa?->variations_total ?? 0);
    $materials = (float) ($pa?->materials_on_site_total ?? 0);
    $gross     = (float) ($pa?->gross_valuation ?? 0);
    $dayworks  = 0.0;
    $discount  = 0.0;
    $contra    = 0.0;
    $netVal    = $gross - $discount - $contra;
    $retention = (float) ($pa?->less_retention ?? 0);
    $prevNet   = (float) ($pa?->less_previous_payments ?? 0);
    $notified  = (float) ($paymentNotice->notified_sum ?? 0);

    // Notification metadata
    $notifPeriod = $pa?->valuation_period_end
        ? \Carbon\Carbon::parse($pa->valuation_period_end)->format('M-y')
        : ($pa?->application_date ? \Carbon\Carbon::parse($pa->application_date)->format('M-y') : '—');
    $notifNumber = 'Nr ' . ($pa?->application_number ?? '—');
    $appRef      = 'Application ' . ($pa?->application_number ?? '—');
    $finalDate   = $pa?->final_date_for_payment
        ? \Carbon\Carbon::parse($pa->final_date_for_payment)->format('d/m/Y')
        : '—';

    // Issuer (right column)
    $orgName   = $branding->company_display_name ?? $org?->name ?? '';
    $orgAddr1  = $org?->address ?? null;

    // Strip org name from first line of address if stored there
    if ($orgAddr1 && $orgName) {
        $addrLines = preg_split('/\r\n|\r|\n/', trim($orgAddr1));
        if (isset($addrLines[0]) && mb_strtolower(trim($addrLines[0])) === mb_strtolower(trim($orgName))) {
            array_shift($addrLines);
            $orgAddr1 = trim(implode("\n", $addrLines));
        }
    }

    // Skip city/postcode if already embedded in the address string
    $city     = $org?->city ?? null;
    $postcode = $org?->postcode ?? null;
    if ($orgAddr1 && $city     && str_contains($orgAddr1, $city))     { $city     = null; }
    if ($orgAddr1 && $postcode && str_contains($orgAddr1, $postcode)) { $postcode = null; }
    $orgAddr2  = trim(implode(', ', array_filter([$city, $postcode])));

    $orgTel    = $org?->phone ?? null;
    $orgEmail  = $org?->email ?? null;

    // Recipient (left column)
    if ($isTradePackage) {
        $recipEmail   = $tp?->contractor_email ?? null;
        $recipContact = $tp?->contractor_contact_name ?? null;
        $recipName    = $tp?->contractor_name ?? '—';
        $recipAddr    = $tp?->contractor_address ?? null;
        $contractName = $project->name;
        $contractNum  = $tp?->package_reference ?? $tp?->package_code ?? '—';
        $tradeValue   = $tp?->name ?? '—';
        $loiRef       = $tp?->package_reference ?? '—';
        $typeLabel    = 'Subcontractor';
        $agreeWord    = 'Sub-Contract';
    } else {
        $recipEmail   = null;
        $recipContact = null;
        $recipName    = $contract?->party_name ?? '—';
        $recipAddr    = $project->address ?? null;
        $contractName = $contract?->title ?? $project->name;
        $contractNum  = $contract?->reference_number ?? '—';
        $tradeValue   = null;
        $loiRef       = $contract?->reference_number ?? '—';
        $typeLabel    = 'Contracting Party';
        $agreeWord    = 'Contract';
    }

    $signatoryName  = $paymentNotice->issued_by ?: ($issuedBy?->name ?? '—');
    $signatoryTitle = $issuedBy?->role ?? 'Quantity Surveyor';

    $docRef = 'Application #' . ($pa?->application_number ?? '—')
        . ($paymentNotice->reference ? ' — Ref: ' . $paymentNotice->reference : '');

    $fmt = fn($v) => $cur . ' ' . number_format((float) $v, 2);
    $neg = fn($v) => $cur . ' (' . number_format(abs((float) $v), 2) . ')';
@endphp

{{-- OUTER NOTICE BOX --}}
<div class="notice-wrapper">

    {{-- HEADER: two-column --}}
    <table class="notice-header-table">
        <tr>
            {{-- Left: recipient + PAYMENT NOTICE title --}}
            <td class="header-left-cell">
                @if($recipEmail)
                    <span class="recipient-email">E-mail: {{ $recipEmail }}</span><br><br>
                @endif
                @if($recipContact)
                    <span class="recipient-attention">For the Attention of — {{ $recipContact }}</span><br>
                @endif
                @if($recipName && $recipName !== '—')
                    <span class="recipient-address">{{ $recipName }}</span><br>
                @endif
                @if($recipAddr)
                    <span class="recipient-address">{{ nl2br(e($recipAddr)) }}</span>
                @endif
                <br>
                <span class="payment-notice-title">PAYMENT NOTICE</span>
            </td>

            {{-- Right: logo + issuer address + tel --}}
            <td class="header-right-cell">
                @if(!empty($branding_logo_uri))
                    <img src="{{ $branding_logo_uri }}" class="company-logo" alt="{{ $orgName }}">
                @endif
                <div class="contractor-address">
                    @if($orgName)<strong>{{ $orgName }}</strong><br>@endif
                    @if($orgAddr1){!! nl2br(e($orgAddr1)) !!}<br>@endif
                    @if($orgAddr2){{ $orgAddr2 }}<br>@endif
                </div>
                @if($orgTel || $orgEmail)
                    <div class="contractor-tel-fax">
                        @if($orgTel)Tel: {{ $orgTel }}<br>@endif
                        @if($orgEmail){{ $orgEmail }}@endif
                    </div>
                @endif
            </td>
        </tr>
    </table>

    {{-- CONTRACT INFO BOX --}}
    <div class="contract-info-box">
        <div class="contract-info-row">Date : {{ $paymentNotice->notice_date?->format('d-M-Y') ?? now()->format('d-M-Y') }}</div>
        <div class="contract-info-row">Contract Name: {{ $contractName }}</div>
        <div class="contract-info-row">Contract Number: {{ $contractNum }}</div>
        <div class="contract-info-row">{{ $typeLabel }}: {{ $recipName }}</div>
        @if($tradeValue)
            <div class="contract-info-row">Trade: {{ $tradeValue }}</div>
        @endif
        <div class="contract-info-row">{{ $isTradePackage ? 'Subcontractor order/LOI ref' : 'Contract ref' }}: {{ $loiRef }}</div>
    </div>

    {{-- NOTIFICATION ROW --}}
    <table class="notification-row">
        <tr>
            <td class="notification-label-cell">
                Notification Period (month end)<br>
                {{ $isTradePackage ? 'Subcontractor Application/Invoice ref:' : 'Application ref:' }}<br>
                Notification Number:
            </td>
            <td class="notification-value-cell">
                {{ $notifPeriod }}<br>
                {{ $appRef }}<br>
                {{ $notifNumber }}
            </td>
            <td class="notification-date-cell">
                Final Date for Payment:<br>
                {{ $finalDate }}
            </td>
        </tr>
    </table>

    {{-- EXPLANATORY TEXT --}}
    <p class="notice-paragraph">
        We enclose our Notice of Valuation of {{ $agreeWord }} Works, together with our detailed
        valuations of your measured works and variations account attached. This is Issued under
        the terms of the {{ $agreeWord }}
    </p>

    <p class="payment-sum-statement">
        We propose to pay the sum of: &nbsp;&nbsp;&nbsp;
        <strong><span class="payment-sum-amount">{{ $cur }} {{ number_format($notified, 2) }}</span></strong>
        &nbsp;&nbsp;this month, which amount we have calculated on the following basis:
    </p>
    <p class="payment-sum-note"><em>NB: the above sum is nett of previous payments, tax and VAT</em></p>

    {{-- VALUATION TABLE --}}
    <table class="valuation-table">
        @if($useBreakdown)
            <tr>
                <td class="col-desc">Lump Sum Works</td>
                <td class="col-amount">{{ $cur }} {{ number_format($measured, 2) }}</td>
            </tr>
            <tr>
                <td class="col-desc">Variation Works</td>
                <td class="col-amount">{{ $cur }} {{ number_format($linkedVar + $manualVar, 2) }}</td>
            </tr>
            <tr>
                <td class="col-desc">Materials</td>
                <td class="col-amount">{{ $cur }} {{ number_format($materials, 2) }}</td>
            </tr>
            <tr>
                <td class="col-desc">Dayworks</td>
                <td class="col-amount">{{ $cur }} {{ number_format($dayworks, 2) }}</td>
            </tr>
        @endif
        <tr>
            <td class="col-desc"><strong>Gross Cumulative Valuation</strong></td>
            <td class="col-amount"><strong>{{ $cur }} {{ number_format($gross, 2) }}</strong></td>
        </tr>
        <tr>
            <td class="col-desc">Less Agreed Discount</td>
            <td class="col-amount">{{ $cur }} {{ number_format($discount, 2) }}</td>
        </tr>
        <tr>
            <td class="col-desc">Less Contra Charges</td>
            <td class="col-amount">{{ $cur }} {{ number_format($contra, 2) }}</td>
        </tr>
        <tr>
            <td class="col-desc"><strong>Net Valuation</strong></td>
            <td class="col-amount"><strong>{{ $cur }} {{ number_format($netVal, 2) }}</strong></td>
        </tr>
        <tr>
            <td class="col-desc">Less Retention</td>
            <td class="col-amount" style="color:#CC5500;">{{ $cur }} ({{ number_format($retention, 2) }})</td>
        </tr>
        <tr>
            <td class="col-desc">Less Previous Net Valuation</td>
            <td class="col-amount" style="color:#CC5500;">{{ $cur }} ({{ number_format($prevNet, 2) }})</td>
        </tr>
        <tr>
            <td class="col-desc" style="font-weight:bold;background-color:#FFFF00;"><strong>Net Payment (Subject to VAT)</strong></td>
            <td class="col-amount" style="font-weight:bold;background-color:#FFFF00;"><strong>{{ $cur }} {{ number_format($notified, 2) }}</strong></td>
        </tr>
    </table>

    @if($paymentNotice->basis_of_assessment)
        <p class="notice-paragraph" style="font-style:italic;">
            <strong>Basis of Assessment:</strong> {{ $paymentNotice->basis_of_assessment }}
        </p>
    @endif

    {{-- POST-TABLE TEXT --}}
    <p class="post-table-text">
        With regards to any alterations on your account, please note that the deductions are made in
        accordance with the terms and conditions of your {{ $agreeWord }}.
    </p>
    <p class="post-table-text">
        Should you have any queries regarding this certificate please contact the undersigned at the above address.
    </p>

    {{-- SIGNATURE BLOCK --}}
    <div class="signature-block">
        Yours faithfully,<br>
        for and on Behalf of <strong>{{ $orgName }}</strong>
        <span class="signature-space"></span>
        <span class="signatory-name">{{ $signatoryName }}</span><br>
        <strong class="signatory-title">{{ $signatoryTitle }}</strong>
    </div>

    {{-- VAT RECEIPTS --}}
    <div class="vat-receipt-block">
        <span class="vat-receipt-title">VAT RECEIPTS:</span>
        Where VAT is applicable, please ensure that a valid VAT Invoice is issued for the agreed amount
        is returned to ourselves within 21 days of the last payment.<br>
        Failure to comply with the above will result in the withholding of further payments until duly received.
    </div>

</div>{{-- end .notice-wrapper --}}

{{-- PAGE FOOTER REFERENCE (outside the box) --}}
<div class="page-footer-reference">{{ $docRef }}</div>

</body>
</html>
