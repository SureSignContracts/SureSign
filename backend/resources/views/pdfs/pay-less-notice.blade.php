<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Pay Less Notice — Application #{{ $payLessNotice->paymentApplication?->application_number ?? '—' }}</title>
    <style>
        @page { margin-top: 145px; margin-bottom: 110px; margin-left: 0; margin-right: 0; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 10pt; color: #222222; background: #ffffff; line-height: 1.45; }
        .body-content { padding: 24px 48px 28px; }
        .doc-header { display: table; width: 100%; margin-bottom: 20px; }
        .doc-header-left, .doc-header-right { display: table-cell; vertical-align: top; }
        .doc-header-right { text-align: right; }
        .badge { display: inline-block; background: #c0392b; color: #ffffff; font-size: 7.5pt; font-weight: bold; letter-spacing: 1px; text-transform: uppercase; padding: 3px 10px; border-radius: 3px; margin-bottom: 6px; }
        h1 { font-size: 16pt; font-weight: bold; color: #111111; margin-bottom: 2px; }
        .meta { font-size: 9pt; color: #888888; }
        hr { border: none; border-top: 1.5px solid #e8e8e8; margin: 16px 0; }
        .section-heading { font-size: 9pt; font-weight: bold; color: #b99566; letter-spacing: 0.5px; text-transform: uppercase; margin: 18px 0 8px; }
        .info-table { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        .info-table td { padding: 7px 10px; font-size: 9.5pt; vertical-align: top; border-bottom: 1px solid #f0f0f0; }
        .info-table td:first-child { width: 42%; color: #666666; font-weight: bold; }
        .info-table td:last-child { color: #222222; }
        .financial-table { width: 100%; border-collapse: collapse; margin-top: 10px; page-break-inside: avoid; }
        .financial-table td { padding: 8px 10px; font-size: 9.5pt; color: #333333; border-bottom: 1px solid #f0f0f0; }
        .financial-table td.label { color: #555555; }
        .financial-table td.amount { text-align: right; font-weight: bold; }
        .financial-table tr.total-row td { font-size: 11pt; font-weight: bold; color: #111111; background: #fdecea; border-top: 2px solid #c0392b; }
        .notice-box { background: #fdecea; border: 2px solid #c0392b; border-radius: 4px; padding: 14px 16px; margin: 16px 0; page-break-inside: avoid; }
        .notice-box .notice-amount { font-size: 22pt; font-weight: bold; color: #c0392b; }
        .notice-box .notice-label { font-size: 9pt; color: #555555; margin-bottom: 4px; }
        .two-col { display: table; width: 100%; }
        .two-col-cell { display: table-cell; width: 50%; vertical-align: top; padding-right: 16px; }
        .two-col-cell:last-child { padding-right: 0; padding-left: 16px; }
        .footer-note { font-size: 8pt; color: #aaaaaa; text-align: center; margin-top: 20px; }
        .warning-note { font-size: 9pt; color: #444444; background: #fdecea; border-left: 4px solid #c0392b; padding: 10px 14px; margin: 14px 0; border-radius: 2px; }
        .dates-row { display: table; width: 100%; margin: 14px 0; border: 1px solid #f0f0f0; border-radius: 3px; }
        .dates-cell { display: table-cell; padding: 9px 14px; font-size: 8.5pt; vertical-align: top; border-right: 1px solid #f0f0f0; }
        .dates-cell:last-child { border-right: none; }
        .dates-cell .d-label { color: #888888; margin-bottom: 2px; }
        .dates-cell .d-value { font-weight: bold; color: #222222; }
        .dates-cell .d-value.overdue { color: #c0392b; }
    </style>
</head>
<body>
<div class="body-content">

    <div class="doc-header">
        <div class="doc-header-left">
            <div class="badge">Pay Less Notice</div>
            <h1>Pay Less Notice — Application #{{ $payLessNotice->paymentApplication?->application_number ?? '—' }}</h1>
            <div class="meta">
                @if($payLessNotice->reference)
                    Ref: {{ $payLessNotice->reference }} &nbsp;|&nbsp;
                @endif
                Notice Date: {{ $payLessNotice->notice_date?->format('d M Y') ?? now()->format('d M Y') }}
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

    <div class="warning-note">
        This is a formal Pay Less Notice issued in accordance with the contract terms. The Revised Amount Payable
        stated below supersedes any previously notified sum and represents the amount that will be paid on the
        Final Date for Payment.
    </div>

    @php
        $pa  = $payLessNotice->paymentApplication;
        $cur = $currency ?? '£';

        $finalDate   = $pa?->final_date_for_payment   ? \Carbon\Carbon::parse($pa->final_date_for_payment)   : null;
        $plnDeadline = $pa?->pay_less_notice_deadline  ? \Carbon\Carbon::parse($pa->pay_less_notice_deadline) : null;
        $noticeDate  = $payLessNotice->notice_date;

        // Contractor / party name
        $partyName = null;
        if ($pa?->tradePackage?->contractor_name) {
            $partyName = $pa->tradePackage->contractor_name;
        } elseif ($pa?->contract?->party_name) {
            $partyName = $pa->contract->party_name;
        }
    @endphp

    {{-- Statutory date summary bar --}}
    @if($noticeDate || $plnDeadline || $finalDate)
    <div class="dates-row">
        @if($noticeDate)
        <div class="dates-cell">
            <div class="d-label">Notice Date</div>
            <div class="d-value">{{ $noticeDate->format('d M Y') }}</div>
        </div>
        @endif
        @if($plnDeadline)
        <div class="dates-cell">
            <div class="d-label">PLN Deadline</div>
            <div class="d-value {{ $noticeDate && $noticeDate->gt($plnDeadline) ? 'overdue' : '' }}">
                {{ $plnDeadline->format('d M Y') }}
            </div>
        </div>
        @endif
        @if($finalDate)
        <div class="dates-cell">
            <div class="d-label">Final Date for Payment</div>
            <div class="d-value">{{ $finalDate->format('d M Y') }}</div>
        </div>
        @endif
    </div>
    @endif

    <div class="two-col">
        <div class="two-col-cell">
            <div class="section-heading">Project Details</div>
            <table class="info-table">
                <tr><td>Project</td><td>{{ $project->name }}</td></tr>
                @if($project->code)<tr><td>Project Number</td><td>{{ $project->code }}</td></tr>@endif
                @if($project->address)<tr><td>Address</td><td>{{ $project->address }}</td></tr>@endif
                @if($partyName)<tr><td>{{ $pa?->tradePackage ? 'Contractor' : 'Counterparty' }}</td><td>{{ $partyName }}</td></tr>@endif
            </table>
        </div>
        <div class="two-col-cell">
            <div class="section-heading">Commercial Source</div>
            <table class="info-table">
                @if($pa?->contract)
                    <tr><td>Type</td><td>Main Contract</td></tr>
                    <tr><td>Contract</td><td>{{ $pa->contract->title }}</td></tr>
                    @if($pa->contract->reference_number)
                        <tr><td>Contract Ref</td><td>{{ $pa->contract->reference_number }}</td></tr>
                    @endif
                @elseif($pa?->tradePackage)
                    <tr><td>Type</td><td>Trade Package</td></tr>
                    <tr><td>Package</td><td>{{ $pa->tradePackage->name }}</td></tr>
                    @if($pa->tradePackage->package_reference)
                        <tr><td>Package Ref</td><td>{{ $pa->tradePackage->package_reference }}</td></tr>
                    @endif
                @endif
                <tr><td>Application #</td><td>#{{ $pa?->application_number ?? '—' }}</td></tr>
                @if($pa?->application_date)
                    <tr><td>Application Date</td><td>{{ \Carbon\Carbon::parse($pa->application_date)->format('d M Y') }}</td></tr>
                @endif
                @if($payLessNotice->paymentNotice?->reference)
                    <tr><td>Payment Notice Ref</td><td>{{ $payLessNotice->paymentNotice->reference }}</td></tr>
                @endif
                @if($payLessNotice->paymentNotice?->notified_sum !== null)
                    <tr><td>Payment Notice Sum</td><td>{{ $cur }}{{ number_format((float)$payLessNotice->paymentNotice->notified_sum, 2) }}</td></tr>
                @endif
            </table>
        </div>
    </div>

    <div class="section-heading">Financial Adjustment</div>
    <table class="financial-table">
        <tr>
            <td class="label">Original Notified Sum / Amount Due</td>
            <td class="amount">
                {{ $cur }}{{ number_format((float)($payLessNotice->original_amount_due ?? $pa?->certified_amount ?? $pa?->amount_due ?? 0), 2) }}
            </td>
        </tr>
        <tr>
            <td class="label">Less: Total Deductions</td>
            <td class="amount" style="color:#c0392b;">
                ({{ $cur }}{{ number_format((float)($payLessNotice->total_deductions ?? $payLessNotice->amount ?? 0), 2) }})
            </td>
        </tr>
        <tr class="total-row">
            <td class="label">Revised Amount Payable</td>
            <td class="amount">{{ $cur }}{{ number_format((float)($payLessNotice->revised_amount_payable ?? $payLessNotice->notified_sum ?? 0), 2) }}</td>
        </tr>
    </table>

    <div class="notice-box">
        <div class="notice-label">Revised Amount Payable (payable by Final Date for Payment{{ $finalDate ? ' — ' . $finalDate->format('d M Y') : '' }})</div>
        <div class="notice-amount">{{ $cur }}{{ number_format((float)($payLessNotice->revised_amount_payable ?? $payLessNotice->notified_sum ?? 0), 2) }}</div>
    </div>

    <div class="section-heading">Basis of Calculation</div>
    <table class="info-table">
        <tr>
            <td>Basis of Calculation</td>
            <td>{{ $payLessNotice->deduction_reason ?? $payLessNotice->reason ?? '—' }}</td>
        </tr>
        @if($payLessNotice->detailed_deduction_notes ?? $payLessNotice->basis_of_difference)
            <tr>
                <td>Detailed Notes</td>
                <td>{{ $payLessNotice->detailed_deduction_notes ?? $payLessNotice->basis_of_difference }}</td>
            </tr>
        @endif
    </table>

    <div class="section-heading">Authorisation</div>
    <table class="info-table">
        <tr><td>Issued By</td><td>{{ $payLessNotice->issued_by ?? $issuedBy?->name ?? '—' }}</td></tr>
        <tr><td>Notice Date</td><td>{{ $noticeDate?->format('d M Y') ?? '—' }}</td></tr>
        @if($payLessNotice->reference)<tr><td>Notice Reference</td><td>{{ $payLessNotice->reference }}</td></tr>@endif
        @if($finalDate)<tr><td>Final Date for Payment</td><td>{{ $finalDate->format('d M Y') }}</td></tr>@endif
    </table>

    <div class="footer-note">
        This Pay Less Notice was generated electronically by SureSign.
        Application #{{ $pa?->application_number ?? '—' }}
        @if($payLessNotice->reference) — Ref: {{ $payLessNotice->reference }}@endif
    </div>

</div>
</body>
</html>
