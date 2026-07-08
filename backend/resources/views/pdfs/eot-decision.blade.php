<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Extension of Time Decision Notice: {{ $reference }}</title>
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
        .chip.granted  { border-color: #1a7a3a; color: #1a7a3a; background: #eafaf0; }
        .chip.refused  { border-color: #a11a1a; color: #a11a1a; background: #fdecec; }

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

        /* ─── Decision callout ─────────────────────────── */
        .decision {
            margin-top: 10px; padding: 12px 14px; border-radius: 3px;
            border: 1.5px solid #1a1a1a;
        }
        .decision.granted { border-color: #1a7a3a; background: #eafaf0; }
        .decision.refused { border-color: #a11a1a; background: #fdecec; }
        .decision .headline { font-size: 10.5pt; font-weight: bold; margin-bottom: 3px; }
        .decision .sub      { font-size: 8.5pt; color: #333333; }

        /* ─── Particulars ─────────────────────────────── */
        .particulars { font-size: 8.5pt; color: #222222; white-space: pre-wrap; }

        /* ─── Signature ───────────────────────────────── */
        .sig-row  { display: table; width: 100%; margin-top: 18px; }
        .sig-cell { display: table-cell; vertical-align: top; padding-right: 20px; }
        .sig-last { display: table-cell; vertical-align: top; padding-left: 20px; }
        .sig-gap  { display: table-cell; width: 8%; }
        .sig-line { border-top: 1px solid #999999; margin-bottom: 6px; }
        .sig-lbl  { font-size: 7pt; color: #777777; margin-bottom: 1px; }
        .sig-val  { font-size: 8.5pt; font-weight: 500; color: #111111; min-height: 13px; }

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
        $sourceName = $contract->title ?? $tradePackage->name ?? '—';
        $sourceRef  = $contract->reference_number ?? $tradePackage->package_code ?? null;
    @endphp

    {{-- Title ─────────────────────────────────────────────────────────── --}}
    <div class="title-row">
        <div class="title-L">
            <div class="doc-label">Extension of Time Decision Notice</div>
            <h1>{{ $reference }}</h1>
            <div class="title-meta">
                <strong>{{ $sourceType ?? 'Source' }}:</strong> {{ $sourceName }}
                @if($sourceRef) &nbsp;&nbsp;·&nbsp;&nbsp; <strong>Ref:</strong> {{ $sourceRef }} @endif
            </div>
        </div>
        <div class="title-R">
            <span class="chip {{ $eotRequest->status }}">{{ ucfirst($eotRequest->status) }}</span>
        </div>
    </div>

    <hr class="rule-heavy">

    {{-- Decision callout ──────────────────────────────────────────────── --}}
    <div class="decision {{ $eotRequest->status }}">
        @if($eotRequest->status === 'granted')
            <div class="headline">Extension of Time GRANTED: {{ $eotRequest->days_granted }} day{{ $eotRequest->days_granted == 1 ? '' : 's' }}</div>
            <div class="sub">
                Revised Completion Date:
                <strong>{{ optional($eotRequest->revised_completion_date)->format('d M Y') ?? 'Not calculated (no base completion date on record)' }}</strong>
            </div>
        @else
            <div class="headline">Extension of Time REFUSED</div>
            <div class="sub">No adjustment has been made to the completion date.</div>
        @endif
    </div>

    {{-- Key facts ──────────────────────────────────────────────────────── --}}
    <div class="two-col">
        <div class="col-L">
            <div class="sh">Request</div>
            <table class="it">
                <tr><td class="k">Title</td><td class="v">{{ $eotRequest->title }}</td></tr>
                <tr><td class="k">Notice Date</td><td class="v">{{ optional($eotRequest->notice_date)->format('d M Y') ?? '—' }}</td></tr>
                <tr><td class="k">Days Claimed</td><td class="v">{{ $eotRequest->days_claimed !== null ? $eotRequest->days_claimed . ' days' : '—' }}</td></tr>
                @if($eotRequest->delayEvent)
                <tr><td class="k">Related Delay Event</td><td class="v">#{{ $eotRequest->delayEvent->event_number }} ({{ $eotRequest->delayEvent->title }})</td></tr>
                @endif
            </table>
        </div>
        <div class="col-R">
            <div class="sh">Decision</div>
            <table class="it">
                <tr><td class="k">Days Granted</td><td class="v">{{ $eotRequest->days_granted !== null ? $eotRequest->days_granted . ' days' : '0 days' }}</td></tr>
                <tr><td class="k">Revised Completion Date</td><td class="v">{{ optional($eotRequest->revised_completion_date)->format('d M Y') ?? '—' }}</td></tr>
                <tr><td class="k">Decided By</td><td class="v">{{ $issuedBy->name ?? '—' }}</td></tr>
                <tr><td class="k">Decision Date</td><td class="v">{{ now()->format('d M Y') }}</td></tr>
            </table>
        </div>
    </div>

    @if($eotRequest->grounds)
    <div class="sh">Grounds</div>
    <div class="particulars">{{ $eotRequest->grounds }}</div>
    @endif

    {{-- Signature ──────────────────────────────────────────────────────── --}}
    <div class="sig-row">
        <div class="sig-cell">
            <div class="sig-lbl">Decided By</div>
            <div class="sig-line"></div>
            <div class="sig-val">{{ $issuedBy->name ?? '—' }}</div>
        </div>
        <div class="sig-gap"></div>
        <div class="sig-last">
            <div class="sig-lbl">Date Issued</div>
            <div class="sig-line"></div>
            <div class="sig-val">{{ now()->format('d M Y') }}</div>
        </div>
    </div>

    <div class="doc-footer">This Decision Notice was generated by SureSign Contracts on {{ now()->format('d M Y \a\t H:i') }}.</div>

</div>
</body>
</html>
