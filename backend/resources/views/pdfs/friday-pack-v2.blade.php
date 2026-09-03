<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>{{ $pack['title'] }}</title>
    @php
        // R1G.1-VFIX2 — the repeating header/footer band sits ABOVE/BELOW
        // the existing 145px/110px letterhead-image reservation only when
        // an organisation has actually configured a custom header/footer
        // image; otherwise the page margin stays compact, matching the
        // density of a real site-issued document rather than wasting
        // space reserved for an asset that doesn't exist.
        // DocumentGenerationService draws the org's own image (if any)
        // into the FIRST 145/110px exactly as before, completely
        // unchanged. R1G.1-VFIX6 — the chrome band itself (org name/
        // document type/identity line/page number) is now painted by
        // that same service's canvas mechanism, not this template's own
        // CSS — see this file's docblock further down for why. These two
        // margin values are the one thing both sides still share: they
        // reserve the same vertical space `DocumentGenerationService`
        // draws the canvas chrome band into.
        $orgHasHeaderImage = !empty($branding?->header_template_path);
        $orgHasFooterImage = !empty($branding?->footer_template_path);
        $topMargin = $orgHasHeaderImage ? 203 : 68;
        $bottomMargin = $orgHasFooterImage ? 172 : 62;
    @endphp
    <style>
        {{--
            Friday Pack Realignment, R1G.1-VFIX5 — real, verified page
            margins. External QA (V4) correctly found the primary content
            reading edge-to-edge despite `@page margin-left/right: 15mm`
            being declared. Root-caused (not guessed) by decompressing a
            real generated PDF's own content stream and extracting its
            "re" (rectangle) drawing operators directly: the Report
            Information table's own cells were found at x≈0.8pt, not the
            expected ≈42.5pt (15mm) — proving `@page margin-left`/
            `margin-right` is NOT reliably honoured for ordinary body
            content by this Dompdf configuration, even though
            `margin-top`/`margin-bottom` demonstrably ARE (the existing
            letterhead-canvas positioning has depended on them working
            correctly for years). `@page` left/right margins are
            therefore set to 0 here and NEVER relied on again — the ONE
            real content-width contract is `.report-content`'s own
            `padding-left`/`padding-right` (ordinary CSS box-model
            padding, confirmed reliable by the same coordinate-extraction
            method — see project-context.md's VFIX5 entry for the
            before/after numbers). EVERY piece of ordinary content
            (Report Information, every section, every table, Sign Off)
            renders inside that one wrapper; the repeating chrome
            header/footer use the SAME 15mm inset directly on their own
            `left`/`right` (not `@page` margins either), so the visible
            band and the body content share one honest left/right edge —
            this consistent alignment is what actually makes the margin
            visible, not the @page declaration alone.
        --}}
        @page {
            margin-top:    {{ $topMargin }}px;
            margin-bottom: {{ $bottomMargin }}px;
            margin-left:   0;
            margin-right:  0;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'DejaVu Sans', Arial, sans-serif;
            font-size: 8.75pt;
            color: #1a1a1a;
            background: #ffffff;
            line-height: 1.45;
        }

        /* ─── The ONE real content-width contract — verified reliable ─── */
        .report-content {
            padding-left: 15mm;
            padding-right: 15mm;
        }

        {{--
            R1G.1-VFIX6 — the repeating text header/footer that used to
            live here as CSS `position: fixed` HTML was removed. Real PDF
            content-stream coordinate extraction proved it rendered
            correctly on PAGE 1 ONLY: on every continuation page the fixed
            element's computed Y coordinate was offset outward by exactly
            one margin-band's worth (the header pushed above the physical
            page's top edge, the footer pushed below its bottom edge),
            landing entirely outside the visible page area — present in
            the PDF's raw content stream (so naive text-extraction checks
            were misleading) but never actually painted. "Page X of Y"
            was never affected by this because it was already drawn via
            Dompdf's canvas `page_script()` API, which computes its own
            per-page coordinates rather than relying on Dompdf's
            fixed-position frame repetition. The repeating header/footer
            now use that SAME proven canvas mechanism instead — see
            `App\Services\DocumentGenerationService::generatePdf()`'s
            `$repeatingChrome` parameter and
            `App\Services\FridayPack\FridayPackPdfService`, which builds
            it. `@page margin-top`/`margin-bottom` above still reserve the
            same vertical space for this canvas-drawn band; only the
            drawing mechanism changed.
        --}}

        /* ─── Report identity ─────────────────────────────────── */
        h1.doc-title {
            font-size: 18pt;
            font-weight: bold;
            color: #111111;
            text-align: center;
            letter-spacing: 0.8px;
            margin-bottom: 3px;
        }
        .doc-subtitle { font-size: 9.5pt; color: #444444; text-align: center; margin-bottom: 10px; }

        .status-bar {
            display: inline-block;
            font-size: 8pt;
            font-weight: bold;
            letter-spacing: 0.6px;
            color: #ffffff;
            background: #5a5a5a;
            padding: 3px 10px;
            margin-bottom: 14px;
        }
        .status-bar.approved, .status-bar.sent { background: #111111; }
        .status-bar.failed { background: #6e1414; }
        .status-wrap { text-align: center; margin-bottom: 14px; }

        /* ─── Report Information form grid ────────────────────── */
        .info-grid { width: 100%; border-collapse: collapse; margin-bottom: 16px; border: 1px solid #999999; }
        .info-grid td { border: 1px solid #cccccc; padding: 6px 9px; vertical-align: top; }
        .info-grid td.lbl { background: #eeeeee; width: 15%; font-size: 6.5pt; text-transform: uppercase; letter-spacing: 0.3px; color: #555555; }
        .info-grid td.val { width: 35%; font-size: 8.75pt; font-weight: normal; color: #111111; }

        /* ─── Section headings ─────────────────────────────────── */
        .section-heading {
            font-size: 10.5pt;
            font-weight: bold;
            color: #111111;
            text-transform: uppercase;
            border-bottom: 2px solid #111111;
            padding-bottom: 4px;
            margin: 16px 0 8px;
        }
        .subsection-heading {
            font-size: 9pt;
            font-weight: bold;
            color: #222222;
            text-transform: uppercase;
            margin: 10px 0 5px;
        }
        .helper-text { font-size: 7.75pt; font-style: italic; color: #777777; margin-bottom: 7px; }

        .narrative {
            font-size: 9pt;
            color: #222222;
            white-space: pre-wrap;
            line-height: 1.5;
            padding: 2px 0 7px;
        }

        /* ─── Tables — dark controlled header (construction-form language) ─── */
        table.data-table { width: 100%; border-collapse: collapse; margin-bottom: 8px; table-layout: fixed; }
        table.data-table th {
            background: #33393f;
            color: #ffffff;
            font-size: 7.5pt;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            text-align: left;
            padding: 5px 7px;
            border: 1px solid #33393f;
        }
        table.data-table td {
            font-size: 8.25pt;
            padding: 5px 7px;
            border: 1px solid #cccccc;
            vertical-align: top;
            background: #ffffff;
            line-height: 1.35;
            /* R1G.1-VFIX5: the previous forced-mid-word-break rule (no
               natural space/hyphen boundary required) was splitting short
               atomic values like dates/status/outcome character-by-
               character in a narrow column. Normal word-breaking (only at
               whitespace) is correct for the WIDE free-text columns
               (Document/Subject/Findings, which have real word
               boundaries) and safe for narrow columns too, since
               `.col-narrow`/`.col-medium` are now sized generously enough
               (see below) for their actual short content. */
            word-break: normal;
            overflow-wrap: normal;
        }
        table.data-table td.amount, table.data-table th.amount { text-align: center; }
        /* Intentional column widths — never let a short field (Date/Status/
           Current/Outcome) stretch to the same width as a long one
           (Document/Subject/Findings). Applied via inline th width where a
           table's own column set needs it — see _section-body.blade.php
           and this file's Permits/Statutory Inspections markup.
           col-narrow widened 10%→12% (R1G.1-VFIX5) so a UK-style date
           ("18/08/2026", 10 characters) comfortably fits on one line at
           8.25pt without wrapping or overflowing. */
        table.data-table th.col-narrow { width: 12%; }
        table.data-table th.col-medium { width: 15%; }
        table.data-table th.col-wide { width: 24%; }

        {{--
            R1G.1-VFIX7 — Statutory Inspections is the one data-table with
            EIGHT columns; its previous markup combined the shared
            col-narrow(12%)/col-medium(15%)/col-wide(24%) classes in a set
            that summed to 126% of the table width (12+24+24+15+15+12+12+12
            — confirmed by direct addition, not assumed). Dompdf's fixed
            table layout proportionally rescales an over-100% column set
            down to fit, which is what silently compressed the already-tight
            12%-wide Date/Outcome/Status/Next Due columns further still —
            the exact, confirmed root cause of external QA's reported
            character-level clipping ("18/08/2026" rendering as
            "18/08/202", "Satisfactory" as "Satisfactor"). Fixed with a
            table-SPECIFIC column set (never touching the shared
            col-narrow/col-medium/col-wide classes every other, already
            frozen/approved data-table in this template still uses) whose
            eight widths sum to exactly 100%, each sized from REAL DejaVu
            Sans 8.25pt text-width measurement of this model's actual
            possible values (App\Models\StatutoryInspection::OUTCOMES/
            STATUSES, never a guess) plus this table's own 5px/7px cell
            padding: Date 13% (fits "18/08/2026", 47.5pt), Inspection 15%,
            Subject 15%, Plant 10%, Reference 10% (all four narrative,
            free to wrap per this checkpoint's own instruction), Outcome
            14% (fits "Issues Found", the longer of the two real outcome
            labels, 53.75pt), Status 10% (fits "Closed"/"Open" — both
            comfortably short), Next Due 13% (same date format as Date).
            `white-space: nowrap` on the four atomic value cells ensures a
            short, complete value is never wrapped onto a second line
            either, only ever fully visible on one.
        --}}
        table.statutory-table th.stat-col-date { width: 13%; }
        table.statutory-table th.stat-col-narrative-lg { width: 15%; }
        table.statutory-table th.stat-col-narrative-sm { width: 10%; }
        table.statutory-table th.stat-col-outcome { width: 14%; }
        table.statutory-table th.stat-col-status { width: 10%; }
        table.statutory-table td.stat-atomic { white-space: nowrap; }

        .fact-row { margin: 4px 0; }
        .fact-label { font-size: 7pt; text-transform: uppercase; letter-spacing: 0.5px; color: #666666; display: inline-block; min-width: 70px; font-weight: bold; }
        .fact-value { font-size: 8.5pt; color: #111111; }

        .neutral { color: #777777; font-style: italic; padding: 5px 0; font-size: 8.5pt; }

        .declaration {
            font-size: 8.5pt;
            font-style: italic;
            color: #333333;
            border-left: 3px solid #888888;
            background: #f5f5f5;
            padding: 7px 11px;
            margin-bottom: 7px;
        }
        .declaration .declaration-note { font-style: normal; margin-top: 4px; }
        .declaration .attribution { display: block; font-style: normal; font-size: 7pt; color: #888888; margin-top: 5px; }

        /* ─── Workforce — ONE primary construction table ──────── */
        table.workforce-table th, table.workforce-table td.amount { text-align: center; }
        table.workforce-table th .day-date { display: block; font-weight: normal; font-size: 6.5pt; text-transform: none; color: #cccccc; margin-top: 2px; }
        table.workforce-table tr.daily-total-row td { font-weight: bold; background: #f0f0f0; }

        /* ─── Site Photographs — heading travels WITH the content (no orphan); content-adaptive rows ─── */
        {{--
            R1G.1-VFIX6 — `padding-top` (never `margin-top`, which can
            collapse at the very start of a page fragment) on every
            forced-page-break wrapper. Real PDF coordinate measurement
            found a page beginning via `page-break-before: always` starts
            its own content far closer to the physical page top than
            `@page margin-top` alone would suggest (the same class of
            "declared CSS value isn't reliable proof" this checkpoint's
            own margin/photo work already established) — close enough
            that this wrapper's own heading was landing ABOVE (not below)
            the repeating canvas header's rule line, genuinely
            overlapping it. This padding is what creates real, measured
            clearance below that header band on every continuation page
            it applies to; see this file's own docblock further up for
            where the canvas header itself is drawn.
        --}}
        .site-photographs-block { page-break-before: always; padding-top: 40px; }
        {{--
            R1G.1-VFIX5 — controlled two-column evidence system. An
            ORDINARY photo (any orientation except genuinely panoramic)
            is sized to fit ONE evidence column — roughly half the safe
            content width — so two pair naturally per row and Dompdf's
            own automatic pagination fills pages efficiently, rather than
            one oversized landscape photo consuming most of a page. Only
            a genuinely panoramic photo (aspect ratio >= 2.0) spans the
            full evidence width. `max-width`/`max-height` only, paired
            with `height: auto` — width and height are never both fixed,
            so the real aspect ratio is always preserved.
        --}}
        .photo-row-pair { width: 100%; margin-bottom: 10px; page-break-inside: avoid; }
        .photo-row-pair td { width: 50%; padding: 0 6px 0 0; border: none; vertical-align: top; }
        .photo-row-pair td:last-child { padding: 0 0 0 6px; }
        .photo-block-column img.photo-img { max-width: 100%; height: auto; max-height: 320pt; }
        .photo-row-panoramic { margin-bottom: 10px; page-break-inside: avoid; }
        .photo-row-panoramic img.photo-img-panoramic { max-width: 100%; height: auto; max-height: 380pt; }
        .photo-row-lone { margin-bottom: 10px; page-break-inside: avoid; text-align: center; }
        .photo-row-lone img.photo-img-lone { max-width: 80%; height: auto; max-height: 420pt; }
        .photo-row-lone .photo-caption { text-align: center; }
        .photo-img { border: 1px solid #cccccc; }
        .photo-unavailable {
            width: 100%;
            height: 140px;
            border: 1px dashed #bbbbbb;
            color: #999999;
            font-size: 8pt;
            font-style: italic;
            text-align: center;
            line-height: 140px;
        }
        .photo-caption { font-size: 7.75pt; color: #444444; margin-top: 4px; }

        /* ─── Health & Safety — one coherent module, own page ──── */
        .health-and-safety-block { page-break-before: always; padding-top: 40px; }
        .hs-group .subsection-block { margin-bottom: 12px; page-break-inside: avoid; }
        .hs-group .subsection-block:last-child { margin-bottom: 0; }

        {{--
            R1G.1-VFIX6 — a `.final-sections-block` class had existed here
            since R1G.1-VFIX3 with no element ever carrying it: Sections
            5-10's own page break is applied directly as an INLINE style
            on the first of those sections' own `<h2>` heading (see
            `$newPageStyle`, built in the `@foreach` below), never via a
            wrapping div. Removed as genuinely dead CSS, found while
            adding this same padding-top fix everywhere it WAS reachable
            — `$newPageStyle` itself now carries the equivalent inline
            `padding-top`.
        --}}

        /* ─── Sign Off — controlled form boxes, no signature claim ─── */
        table.signoff-table { width: 100%; border-collapse: collapse; margin-top: 4px; }
        table.signoff-table th {
            background: #33393f; color: #ffffff; font-size: 7.5pt; text-transform: uppercase;
            letter-spacing: 0.3px; text-align: left; padding: 6px 9px; border: 1px solid #33393f;
        }
        table.signoff-table td { border: 1px solid #cccccc; padding: 7px 9px; vertical-align: top; background: #ffffff; }
        table.signoff-table .field-label { font-size: 6.5pt; text-transform: uppercase; color: #777777; }
        table.signoff-table .field-value { font-size: 8.75pt; font-weight: normal; color: #111111; }
    </style>
</head>
<body>

{{-- R1G.1-VFIX6 — the repeating header/footer is now painted by
     DocumentGenerationService's canvas mechanism (see the docblock
     above this file's own <style> block) — there is deliberately no HTML
     chrome element here any more. --}}

<div class="report-content">
<h1 class="doc-title">FRIDAY PACK</h1>
<p class="doc-subtitle">Site Progress, Workforce &amp; Health &amp; Safety Report</p>
<div class="status-wrap">
    <div class="status-bar {{ strtolower($pack['status']) }}">{{ $pack['status_label'] }}</div>
</div>

@if($pack['report_information'])
    @php
        $ri = $pack['report_information'];
    @endphp
    <table class="info-grid">
        <tr>
            <td class="lbl">Project</td><td class="val">{{ $ri['project_name'] }}</td>
            <td class="lbl">Site Address</td><td class="val">{{ $ri['site_address'] ?? '—' }}</td>
        </tr>
        <tr>
            <td class="lbl">Principal Contractor</td><td class="val">{{ $ri['principal_contractor'] ?? '—' }}</td>
            <td class="lbl">Sub-Contract Order No.</td><td class="val">{{ $ri['sub_contract_order_no'] ?? '—' }}</td>
        </tr>
        <tr>
            <td class="lbl">Reporting Organisation</td><td class="val">{{ $ri['reporting_organisation'] ?? '—' }}</td>
            <td class="lbl">Scope of Works</td><td class="val">{{ $ri['scope_of_works'] ?? '—' }}</td>
        </tr>
        <tr>
            <td class="lbl">Week Commencing</td><td class="val">{{ $ri['week_commencing'] }}</td>
            <td class="lbl">Week Ending</td><td class="val">{{ $ri['week_ending'] }}</td>
        </tr>
        <tr>
            <td class="lbl">Prepared By</td><td class="val">{{ $ri['prepared_by'] ?? '—' }}</td>
            <td class="lbl">Date Issued</td><td class="val">{{ $ri['date_issued'] }}</td>
        </tr>
        <tr>
            <td class="lbl">Distributed To</td><td class="val">{{ $ri['distributed_to'] ?? '—' }}</td>
            <td class="lbl">Report No.</td><td class="val">{{ $ri['report_number'] ?? '—' }}</td>
        </tr>
    </table>
@endif

@php
    // R1G.1-VFIX3 — Section 5 (or, if excluded, whichever section is
    // first among 5-10) always begins on a NEW page after Health & Safety
    // — computed once here rather than hardcoding a specific key, so
    // exclusion of permits_inspections doesn't leave the rhythm broken.
    $finalGroupKeys = ['permits_inspections', 'plant_equipment', 'materials_delivered', 'site_issues', 'look_ahead', 'sign_off'];
    $firstFinalGroupKey = null;
    foreach ($pack['sections'] as $s) {
        if (in_array($s['key'], $finalGroupKeys, true)) {
            $firstFinalGroupKey = $s['key'];
            break;
        }
    }
@endphp
@foreach($pack['sections'] as $section)
    @php
        // R1G.1-VFIX6 — `padding-top` travels alongside `page-break-before`
        // here (this heading itself is the page-break trigger — there is
        // no separate wrapping block element to attach it to, unlike
        // `.site-photographs-block`/`.health-and-safety-block`) for the
        // same reason those two gained it: real PDF coordinate
        // measurement found a forced page break's own first line landing
        // far closer to the physical top than expected, close enough to
        // genuinely overlap the repeating canvas header's rule line.
        $newPageStyle = $section['key'] === $firstFinalGroupKey ? ' style="page-break-before: always; padding-top: 40px;"' : '';
    @endphp
    @if($section['kind'] === 'sign_off')
        {!! '<h2 class="section-heading"' . $newPageStyle . '>' . e($section['number']) . '. Sign Off</h2>' !!}
        @php
            $s = $section['sign_off'];
        @endphp
        <table class="signoff-table">
            <tr>
                <th>Prepared By</th>
                <th>Reviewed By</th>
                <th>Approved By</th>
                @if($s['sent_by'])<th>Sent By</th>@endif
            </tr>
            <tr>
                <td>
                    <div class="field-label">Name</div><div class="field-value">{{ $s['prepared_by'] ?? '—' }}</div>
                    <div class="field-label" style="margin-top:4px;">Date</div><div class="field-value">{{ $s['prepared_at'] ?? '—' }}</div>
                </td>
                <td>
                    @if($s['reviewed_by'])
                        <div class="field-label">Name</div><div class="field-value">{{ $s['reviewed_by'] }}</div>
                        <div class="field-label" style="margin-top:4px;">Date</div><div class="field-value">{{ $s['reviewed_at'] }}</div>
                    @else
                        <span class="neutral">Not yet reviewed</span>
                    @endif
                </td>
                <td>
                    @if($s['approved_by'])
                        <div class="field-label">Name</div><div class="field-value">{{ $s['approved_by'] }}</div>
                        <div class="field-label" style="margin-top:4px;">Date</div><div class="field-value">{{ $s['approved_at'] }}</div>
                    @else
                        <span class="neutral">Not yet approved</span>
                    @endif
                </td>
                @if($s['sent_by'])
                    <td>
                        <div class="field-label">Name</div><div class="field-value">{{ $s['sent_by'] }}</div>
                        <div class="field-label" style="margin-top:4px;">Date</div><div class="field-value">{{ $s['sent_at'] }}</div>
                    </td>
                @endif
            </tr>
        </table>

    @elseif($section['kind'] === 'health_and_safety')
        {{-- Section 4 is fixed and always begins on its own clean page,
             right after the photograph evidence page(s) — never
             conditional, unlike the $newPageStyle final-group logic
             above. --}}
        <div class="health-and-safety-block">
            <h2 class="section-heading">{{ $section['number'] }}. {{ $section['label'] }}</h2>
            <div class="hs-group">
                @foreach($section['children'] as $child)
                    <div class="subsection-block">
                        <h3 class="subsection-heading">{{ $child['number'] }} {{ $child['label'] }}</h3>
                        @include('pdfs.friday-pack-v2._section-body', ['section' => $child])
                    </div>
                @endforeach
            </div>
        </div>

    @elseif($section['kind'] === 'permits_inspections')
        {!! '<h2 class="section-heading"' . $newPageStyle . '>' . e($section['number']) . '. ' . e($section['label']) . '</h2>' !!}

        <h3 class="subsection-heading">Permits</h3>
        @if($section['permits']['render_mode'] === 'source')
            <table class="data-table">
                <tr><th class="col-wide">Document</th><th class="col-narrow">Revision</th><th class="col-narrow">Status</th><th class="col-narrow amount">Current</th><th class="col-narrow">Expiry</th></tr>
                @foreach($section['permits']['items'] as $row)
                    <tr>
                        <td>{{ $row['title'] }}</td>
                        <td>{{ $row['revision'] }}</td>
                        <td>{{ $row['status'] }}</td>
                        <td class="amount">{{ $row['current'] }}</td>
                        <td>{{ $row['expiry_date'] ?? '—' }}</td>
                    </tr>
                @endforeach
            </table>
        @else
            @include('pdfs.friday-pack-v2._declaration', ['state' => $section['permits']])
        @endif

        <h3 class="subsection-heading">Statutory Inspections</h3>
        @if($section['inspections']['render_mode'] === 'source')
            {{-- R1G.1-VFIX7 — dedicated statutory-table column classes;
                 see this file's own <style> docblock above for why the
                 shared col-narrow/col-medium/col-wide classes (which this
                 markup used before) are never used here. --}}
            <table class="data-table statutory-table">
                <tr>
                    <th class="stat-col-date">Date</th>
                    <th class="stat-col-narrative-lg">Inspection</th>
                    <th class="stat-col-narrative-lg">Subject</th>
                    <th class="stat-col-narrative-sm">Plant</th>
                    <th class="stat-col-narrative-sm">Reference</th>
                    <th class="stat-col-outcome">Outcome</th>
                    <th class="stat-col-status">Status</th>
                    <th class="stat-col-date">Next Due</th>
                </tr>
                @foreach($section['inspections']['items'] as $row)
                    <tr>
                        <td class="stat-atomic">{{ $row['date'] }}</td>
                        <td>{{ $row['inspection_type'] }}</td>
                        <td>{{ $row['subject'] }}</td>
                        <td>{{ $row['plant'] }}</td>
                        <td>{{ $row['reference'] }}</td>
                        <td class="stat-atomic">{{ $row['outcome'] }}</td>
                        <td class="stat-atomic">{{ $row['status'] }}</td>
                        <td class="stat-atomic">{{ $row['next_due'] ?? '—' }}</td>
                    </tr>
                @endforeach
            </table>
        @else
            @include('pdfs.friday-pack-v2._declaration', ['state' => $section['inspections']])
        @endif

    @elseif($section['key'] === 'site_photographs')
        {{-- Heading travels WITH the photo content — page-break-before is
             on this OUTER block (heading + content together), never
             orphaning the heading at the bottom of the previous page. --}}
        <div class="site-photographs-block">
            <h2 class="section-heading">{{ $section['number'] }}. {{ $section['label'] }}</h2>
            @if($section['helper_text'])<p class="helper-text">{{ $section['helper_text'] }}</p>@endif
            @include('pdfs.friday-pack-v2._section-body', ['section' => $section])
        </div>

    @else
        {!! '<h2 class="section-heading"' . $newPageStyle . '>' . e($section['number']) . '. ' . e($section['label']) . '</h2>' !!}
        @if($section['helper_text'])<p class="helper-text">{{ $section['helper_text'] }}</p>@endif
        @include('pdfs.friday-pack-v2._section-body', ['section' => $section])
    @endif
@endforeach
</div>

</body>
</html>
