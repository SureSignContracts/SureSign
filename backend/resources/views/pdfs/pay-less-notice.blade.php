<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Pay Less Notice: Application #{{ $payLessNotice->paymentApplication?->application_number ?? 'Unreferenced' }}</title>
    <style>
        {{-- Mirrors payment-notice.blade.php's layout/CSS exactly (the
             verified reference template) so both notices share one visual
             design — only the title and the deduction-specific content
             below differ, per the real Payment Notice / Pay Less Notice
             legal distinction (grounds for withholding is unique to PLN). --}}
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
        .notification-label-cell { width: 40%; }
        .notification-value-cell { width: 30%; }
        .notification-date-cell  { width: 15%; }

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

        /* ── VALUATION / DEDUCTION TABLE ── */
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

        /* ── GROUNDS FOR WITHHOLDING (statutory — must stand out) ── */
        .grounds-heading {
            font-size: 9pt;
            font-weight: bold;
            text-decoration: underline;
            margin: 3mm 0 1mm 0;
            display: block;
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
    $pa = $payLessNotice->paymentApplication;
    $isTradePackage = (bool) ($pa?->trade_package_id);
    $tp       = $pa?->tradePackage;
    $contract = $pa?->contract;
    $org      = $project->organization;
    $cur      = $currency ?? '£';

    $originalDue = (float) ($payLessNotice->original_amount_due ?? $pa?->certified_amount ?? $pa?->amount_due ?? 0);
    $deductions  = (float) ($payLessNotice->total_deductions ?? $payLessNotice->amount ?? 0);
    $revised     = (float) ($payLessNotice->revised_amount_payable ?? $payLessNotice->notified_sum ?? 0);

    // Notification metadata
    $notifPeriod = $pa?->valuation_period_end
        ? \Carbon\Carbon::parse($pa->valuation_period_end)->format('M-y')
        : ($pa?->application_date ? \Carbon\Carbon::parse($pa->application_date)->format('M-y') : '—');
    $notifNumber = 'Nr ' . ($pa?->application_number ?? '—');
    $appRef      = 'Application ' . ($pa?->application_number ?? '—');
    $finalDate   = $pa?->final_date_for_payment
        ? \Carbon\Carbon::parse($pa->final_date_for_payment)->format('d/m/Y')
        : '—';

    // Pay Less Notice deadline compliance — is_late is computed and
    // persisted once at creation (PaymentApplicationController::createPayLessNotice),
    // so the document always reflects what was actually checked at issue time.
    $plnDeadlineObj = $pa?->pay_less_notice_deadline ? \Carbon\Carbon::parse($pa->pay_less_notice_deadline) : null;
    $plnDeadline    = $plnDeadlineObj?->format('d/m/Y') ?? '—';
    $plnOverdue     = (bool) $payLessNotice->is_late;

    // Issuer (right column)
    $orgName   = $branding->company_display_name ?? $org?->name ?? '';
    $orgAddr1  = $org?->address ?? null;

    if ($orgAddr1 && $orgName) {
        $addrLines = preg_split('/\r\n|\r|\n/', trim($orgAddr1));
        if (isset($addrLines[0]) && mb_strtolower(trim($addrLines[0])) === mb_strtolower(trim($orgName))) {
            array_shift($addrLines);
            $orgAddr1 = trim(implode("\n", $addrLines));
        }
    }

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

    $signatoryName  = $payLessNotice->issued_by ?: ($issuedBy?->name ?? '—');
    $signatoryTitle = $issuedBy?->role ?? 'Quantity Surveyor';

    $docRef = 'Application #' . ($pa?->application_number ?? '—')
        . ($payLessNotice->reference ? ' (Ref: ' . $payLessNotice->reference . ')' : '');
@endphp

{{-- OUTER NOTICE BOX --}}
<div class="notice-wrapper">

    {{-- HEADER: two-column --}}
    <table class="notice-header-table">
        <tr>
            {{-- Left: recipient + PAY LESS NOTICE title --}}
            <td class="header-left-cell">
                @if($recipEmail)
                    <span class="recipient-email">E-mail: {{ $recipEmail }}</span><br><br>
                @endif
                @if($recipContact)
                    <span class="recipient-attention">For the Attention of: {{ $recipContact }}</span><br>
                @endif
                @if($recipName && $recipName !== '—')
                    <span class="recipient-address">{{ $recipName }}</span><br>
                @endif
                @if($recipAddr)
                    <span class="recipient-address">{{ nl2br(e($recipAddr)) }}</span>
                @endif
                <br>
                <span class="payment-notice-title">PAY LESS NOTICE</span>
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
        <div class="contract-info-row">Date : {{ $payLessNotice->notice_date?->format('d-M-Y') ?? now()->format('d-M-Y') }}</div>
        <div class="contract-info-row">Contract Name: {{ $contractName }}</div>
        <div class="contract-info-row">Contract Number: {{ $contractNum }}</div>
        <div class="contract-info-row">{{ $typeLabel }}: {{ $recipName }}</div>
        @if($tradeValue)
            <div class="contract-info-row">Trade: {{ $tradeValue }}</div>
        @endif
        <div class="contract-info-row">{{ $isTradePackage ? 'Subcontractor order/LOI ref' : 'Contract ref' }}: {{ $loiRef }}</div>
        @if($payLessNotice->paymentNotice?->reference)
            <div class="contract-info-row">Supersedes Payment Notice: {{ $payLessNotice->paymentNotice->reference }}</div>
        @endif
    </div>

    {{-- NOTIFICATION ROW — Final Date + PLN deadline compliance check --}}
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
            <td class="notification-date-cell">
                Pay Less Notice Deadline:<br>
                {{ $plnDeadline }}
                @if($plnOverdue)
                    <br><span style="color:#CC5500; font-weight:bold;">(issued after deadline)</span>
                @endif
            </td>
        </tr>
    </table>

    {{-- EXPLANATORY TEXT --}}
    <p class="notice-paragraph">
        We hereby give notice, in accordance with the terms of the {{ $agreeWord }} and section 111 of the
        Housing Grants, Construction and Regeneration Act 1996 (as amended), that we intend to pay less than
        the notified sum. The sum we consider to be due on the date this notice is given, after taking into
        account the deduction(s) set out below, is:
    </p>

    <p class="payment-sum-statement">
        Revised Amount Payable: &nbsp;&nbsp;&nbsp;
        <strong><span class="payment-sum-amount">{{ $cur }} {{ number_format($revised, 2) }}</span></strong>
        &nbsp;&nbsp;payable by the Final Date for Payment.
    </p>
    <p class="payment-sum-note"><em>NB: the above sum is nett of previous payments, tax and VAT</em></p>

    {{-- DEDUCTION TABLE --}}
    <table class="valuation-table">
        <tr>
            <td class="col-desc">Original Notified Sum / Amount Due</td>
            <td class="col-amount">{{ $cur }} {{ number_format($originalDue, 2) }}</td>
        </tr>
        <tr>
            <td class="col-desc">Less: Total Deductions</td>
            <td class="col-amount" style="color:#CC5500;">{{ $cur }} ({{ number_format($deductions, 2) }})</td>
        </tr>
        <tr>
            <td class="col-desc" style="font-weight:bold;background-color:#FFFF00;"><strong>Revised Amount Payable</strong></td>
            <td class="col-amount" style="font-weight:bold;background-color:#FFFF00;"><strong>{{ $cur }} {{ number_format($revised, 2) }}</strong></td>
        </tr>
    </table>

    {{-- GROUNDS FOR WITHHOLDING — the statutory core of a Pay Less Notice;
         a Payment Notice has no equivalent, so this section only exists here. --}}
    <span class="grounds-heading">Grounds for Withholding Payment (s.111 basis of calculation):</span>
    <p class="notice-paragraph">{{ $payLessNotice->deduction_reason ?? $payLessNotice->reason ?? '—' }}</p>
    @if($payLessNotice->detailed_deduction_notes ?? $payLessNotice->basis_of_difference)
        <p class="notice-paragraph">{{ $payLessNotice->detailed_deduction_notes ?? $payLessNotice->basis_of_difference }}</p>
    @endif

    {{-- POST-TABLE TEXT --}}
    <p class="post-table-text">
        With regards to the deduction(s) set out above, please note that they are made in accordance with the
        terms and conditions of your {{ $agreeWord }}.
    </p>
    <p class="post-table-text">
        Should you have any queries regarding this notice please contact the undersigned at the above address.
    </p>

    {{-- SIGNATURE BLOCK --}}
    <div class="signature-block">
        Yours faithfully,<br>
        for and on Behalf of <strong>{{ $orgName }}</strong>
        <span class="signature-space"></span>
        <span class="signatory-name">{{ $signatoryName }}</span><br>
        <strong class="signatory-title">{{ $signatoryTitle }}</strong>
    </div>

</div>{{-- end .notice-wrapper --}}

{{-- PAGE FOOTER REFERENCE (outside the box) --}}
<div class="page-footer-reference">{{ $docRef }}</div>

</body>
</html>
