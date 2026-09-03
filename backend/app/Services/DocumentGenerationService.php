<?php

namespace App\Services;

use App\Models\Document;
use App\Models\Organization;
use App\Models\Project;
use App\Models\SuresignSetting;
use App\Models\TradePackage;
use App\Models\User;
use App\Services\BrandingService;
use App\Services\CurrencyService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DocumentGenerationService
{
    /**
     * Generate a PDF from a Blade view, save it, and create a Document record.
     *
     * @param  Project        $project
     * @param  User           $user
     * @param  string         $viewName    Blade view path, e.g. 'pdfs.payment-application'
     * @param  array          $viewData    Data to pass to the view
     * @param  string         $title       Document title
     * @param  string         $type        Document type slug
     * @param  string|null    $category    Folder category / folder path
     * @param  string|null    $reference   Reference number
     * @param  Model|null     $relatedModel  Morphable parent (PaymentApplication, etc.)
     * @param  TradePackage|null $tradePackage  Set when this document belongs to a trade
     *                                          package's subcontract, so it surfaces on
     *                                          that package's Documents tab (Sprint 6C).
     * @param  bool  $includePageNumbers  R1G.1 (Friday Pack schema-2) — additive,
     *   backward-compatible opt-in only. When true, draws a small
     *   "Page {n} of {count}" text line into every page's already-reserved
     *   footer band via the same canvas `page_script()` this method already
     *   uses for the letterhead header/footer images (Dompdf's real
     *   `{PAGE_NUM}`/`{PAGE_COUNT}` substitution requires the canvas API —
     *   confirmed the equivalent plain-HTML-text substitution path is dead
     *   code in the installed Dompdf version). Defaults false — every
     *   existing caller's output is completely unchanged.
     * @param  ?float  $pageNumberYFromBottomPx  R1G.1-VFIX2 — additive,
     *   optional override for where the page-number text is drawn,
     *   measured in CSS px up from the page's bottom edge. Defaults to
     *   null, which preserves the exact original position (14px above
     *   the bottom of the 110px letterhead-footer band) — zero change for
     *   any caller not passing this. Exists because a view may reserve a
     *   LARGER total bottom margin than the base 110px letterhead zone
     *   (e.g. schema-2's own repeating text footer) — see
     *   `pdfs/friday-pack-v2.blade.php`'s own `@page` margin for the
     *   worked example. Positive values move the text UP the page (i.e.
     *   further from the bottom edge, deeper inside a taller margin).
     * @param  ?float  $pageNumberXFromRightPx  R1G.1-VFIX5 — additive,
     *   optional override for the page-number text's horizontal position,
     *   measured in CSS px in from the page's right edge. Defaults to
     *   null, which preserves the exact original position (130pt in from
     *   the right, a hardcoded value pre-dating any content-width
     *   contract). Exists so a view's own safe content column (e.g.
     *   `pdfs/friday-pack-v2.blade.php`'s `.report-content` 15mm inset)
     *   can be matched exactly, rather than the page number drifting to a
     *   different right-hand edge than the body content beside it.
     * @param  ?array  $repeatingChrome  R1G.1-VFIX6 — additive, optional.
     *   Draws a repeating text header/footer via the SAME canvas
     *   `page_script()` mechanism already proven reliable for
     *   `$includePageNumbers` (see that parameter's own docblock — Dompdf's
     *   `{PAGE_NUM}`/`{PAGE_COUNT}` substitution only works through the
     *   canvas API). This exists because the equivalent CSS
     *   `position: fixed` HTML approach was found, via direct PDF
     *   content-stream coordinate extraction, to render CORRECTLY on a
     *   document's first page only — on every subsequent page the fixed
     *   element's computed Y coordinate was offset by exactly one margin-
     *   band's worth in the outward direction (header pushed above the
     *   page top edge, footer pushed below the bottom edge), landing
     *   entirely outside the page's visible area. The text was still
     *   present in the PDF's content stream (so naive text-extraction
     *   proof was misleading) but never actually painted on continuation
     *   pages. The canvas API draws at coordinates this service computes
     *   itself for each page, so it carries no such per-page drift.
     *   Shape: `['header_left' => string, 'header_right' => string,
     *   'header_subline' => ?string, 'footer_left' => string,
     *   'footer_center' => ?string]`. `header_left`/`header_right`/
     *   `footer_left` are required whenever this array is passed;
     *   `header_subline`/`footer_center` are optional. Every string must
     *   already be safe, pre-formatted display text (this method never
     *   escapes/derives anything from it) — HTML is never involved, so
     *   there is no injection surface. Positioned within the SAME 15mm
     *   safe column `pageNumberXFromRightPx` targets, below any
     *   organisation letterhead HEADER image and above any letterhead
     *   FOOTER image (never overlapping either) — an organisation with no
     *   configured letterhead simply gets the chrome band at the very top/
     *   bottom of the page instead. Defaults null — every existing caller
     *   is completely unchanged.
     *   R1G.1-VFIX7: real font-metric measurement (never assumed) found
     *   two spacing defects in the original layout — the header rule sat
     *   INSIDE the header sub-line's own measured text span (a visual
     *   strikethrough), and the footer's centred tertiary line could
     *   horizontally collide with a long left-hand identity line on the
     *   same row. Fixed by (a) moving the header rule strictly below the
     *   sub-line's measured bottom edge, and (b) restructuring the footer
     *   into two genuinely separate vertical rows — identity + page number
     *   on row one, the centred tertiary line on row two — so the two can
     *   never overlap regardless of string length. When `$repeatingChrome`
     *   is present, "Page X of Y" is now drawn as part of THIS block
     *   (using the real per-page `$pageNum`/`$pageCount` the canvas
     *   `page_script()` closure already receives) rather than via the
     *   separate `page_text()` mechanism below — one chrome renderer for
     *   the whole repeating footer, not two. `$includePageNumbers` without
     *   `$repeatingChrome` is completely unaffected — see that branch's
     *   own comment.
     * @return Document
     */
    public static function generatePdf(
        Project $project,
        User $user,
        string $viewName,
        array $viewData,
        string $title,
        string $type,
        ?string $category = null,
        ?string $reference = null,
        ?Model $relatedModel = null,
        bool $skipCanvas = false,
        ?TradePackage $tradePackage = null,
        bool $includePageNumbers = false,
        ?float $pageNumberYFromBottomPx = null,
        ?float $pageNumberXFromRightPx = null,
        ?array $repeatingChrome = null,
    ): Document {
        abort_unless(SuresignSetting::instance()->feature_document_generation, 403, 'Document generation is currently disabled.');

        // Load branding for the organisation
        $branding = BrandingService::forOrganization($project->organization_id);
        $viewData['branding']          = $branding;
        $viewData['branding_logo_uri'] = BrandingService::logoFileUri($branding);
        $viewData['project']           = $project;

        // Inject currency symbol unless the caller already provided one.
        // Priority: project currency → platform currency → £ (never uses contract.currency
        // directly, which may have been populated by AI extraction from contract text).
        if (!isset($viewData['currency'])) {
            $viewData['currency'] = CurrencyService::resolveSymbol($project);
        }

        // R1G.1-VFIX2 — real bug fix, confirmed via non-visual proof (raw
        // JPEG magic-byte search on generated output): ->setOptions()
        // without mergeWithDefaults=true replaces Dompdf's ENTIRE Options
        // object outright, discarding the config-provided `chroot`
        // (this app's own base path) and silently substituting Dompdf's
        // own internal default — the dompdf PACKAGE's own installation
        // directory. Any `file://` <img> tag pointing outside that
        // directory (e.g. a Friday Pack schema-2 optimised photo temp
        // file under storage/app/) then fails Options::validateLocalUri()'s
        // chroot check and silently renders as Dompdf's built-in
        // broken-image SVG placeholder — never an exception, never a
        // warning (this app's own `show_warnings` config is false),
        // which is exactly why this went unnoticed until now: every
        // pre-existing local image use (BrandingService's logo/header/
        // footer) is drawn via the CANVAS API (`$canvas->image()`), a
        // completely separate code path that never goes through
        // Options::validateLocalUri() at all. `mergeWithDefaults: true`
        // preserves every config-file default (chroot, font_dir,
        // font_cache, etc.) and only overrides the three keys below —
        // zero behavior change for every existing caller/output.
        $pdf = Pdf::loadView($viewName, $viewData)
            ->setPaper('a4', 'portrait')
            ->setOptions([
                'isHtml5ParserEnabled' => true,
                'isRemoteEnabled'      => false,
                'defaultFont'          => 'DejaVu Sans',
            ], true);

        // Draw letterhead header/footer images directly onto every page canvas.
        // The @page margins in each view already reserve this space (145px top, 110px bottom).
        $headerAbsPath = BrandingService::headerPath($branding);
        $footerAbsPath = BrandingService::footerPath($branding);

        if (!$skipCanvas && ($headerAbsPath || $footerAbsPath || $includePageNumbers || $repeatingChrome)) {
            $pdf->render();
            $canvas = $pdf->getDomPDF()->getCanvas();
            $pageW  = $canvas->get_width();
            $pageH  = $canvas->get_height();
            $headerH = 145 * (72 / 96); // CSS px → PDF pts
            $footerH = 110 * (72 / 96);
            $pageNumberFont = $includePageNumbers
                ? $pdf->getDomPDF()->getFontMetrics()->getFont('DejaVu Sans', 'normal')
                : null;
            $pageNumberY = $pageNumberYFromBottomPx !== null
                ? $pageH - ($pageNumberYFromBottomPx * (72 / 96))
                : $pageH - $footerH + 14;
            $pageNumberX = $pageNumberXFromRightPx !== null
                ? $pageW - ($pageNumberXFromRightPx * (72 / 96))
                : $pageW - 130;

            // R1G.1-VFIX6 — repeating text chrome, drawn via the canvas API
            // rather than CSS `position: fixed` (see $repeatingChrome's own
            // docblock for the proven root cause this replaces). Computed
            // once here, outside the per-page closure, using the SAME
            // 15mm safe-column convention `pageNumberXFromRightPx` targets.
            $chromeFontNormal = null;
            $chromeFontBold = null;
            $safeLeftX = null;
            $safeRightX = null;
            $chromeHeaderTopY = null;
            $chromeFooterBottomY = null;
            if ($repeatingChrome) {
                $chromeFontNormal = $pdf->getDomPDF()->getFontMetrics()->getFont('DejaVu Sans', 'normal');
                $chromeFontBold = $pdf->getDomPDF()->getFontMetrics()->getFont('DejaVu Sans', 'bold');
                $safeLeftX = 42.52; // 15mm — the same proven safe-column edge as .report-content
                $safeRightX = $pageW - 42.52;
                // The chrome band sits directly below any letterhead HEADER
                // image (or the very top of the page when none is
                // configured), and directly above any letterhead FOOTER
                // image (or the very bottom of the page) — never
                // overlapping either.
                $chromeHeaderTopY = $headerAbsPath ? $headerH : 0;
                $chromeFooterBottomY = $footerAbsPath ? ($pageH - $footerH) : $pageH;
            }

            $canvas->page_script(function (int $pageNum, int $pageCount, $canvas) use (
                $headerAbsPath, $footerAbsPath, $pageW, $pageH, $headerH, $footerH,
                $includePageNumbers, $pageNumberFont, $pageNumberY, $pageNumberX,
                $repeatingChrome, $chromeFontNormal, $chromeFontBold, $safeLeftX, $safeRightX,
                $chromeHeaderTopY, $chromeFooterBottomY, $pdf
            ) {
                if ($headerAbsPath) {
                    $canvas->image($headerAbsPath, 0, 0, $pageW, $headerH);
                }
                if ($footerAbsPath) {
                    $canvas->image($footerAbsPath, 0, $pageH - $footerH, $pageW, $footerH);
                }
                if ($repeatingChrome) {
                    $metrics = $pdf->getDomPDF()->getFontMetrics();

                    // Header: org name left, document type right, an
                    // optional sub-line below the left side, a thin rule
                    // beneath both. PAGE 1 ONLY EXCEPTION: this codebase's
                    // own Page 1 layout (title, status bar, Report
                    // Information table) is separately approved and
                    // explicitly NOT to be redesigned — direct coordinate
                    // measurement of a real generated PDF found this
                    // service's own header band sitting close enough to
                    // Page 1's own title/subtitle line to risk visual
                    // crowding there specifically (continuation pages have
                    // no such competing content at the top). A repeating
                    // header is genuinely optional on Page 1 by design
                    // (the document's own title/Report Information table
                    // already identify it) — continuation pages, which
                    // have no such identification, are the priority this
                    // exists for.
                    if ($pageNum > 1) {
                        $canvas->text($safeLeftX, $chromeHeaderTopY + 17, $repeatingChrome['header_left'], $chromeFontBold, 9, [0.067, 0.067, 0.067]);
                        $rightText = $repeatingChrome['header_right'];
                        $rightTextWidth = $metrics->getTextWidth($rightText, $chromeFontBold, 8);
                        $canvas->text($safeRightX - $rightTextWidth, $chromeHeaderTopY + 17, $rightText, $chromeFontBold, 8, [0.2, 0.2, 0.2]);
                        if (!empty($repeatingChrome['header_subline'])) {
                            $canvas->text($safeLeftX, $chromeHeaderTopY + 30, $repeatingChrome['header_subline'], $chromeFontNormal, 7, [0.4, 0.4, 0.4]);
                        }
                        // R1G.1-VFIX7 — real font-metric measurement (see
                        // this method's own docblock addendum) proved the
                        // subline (drawn at +30, 7pt DejaVu Sans normal,
                        // whose real line height is 8.96pt — never assume
                        // point size ≈ line height) actually occupies
                        // roughly [30, 38.96]. The rule was previously drawn
                        // at +35, landing INSIDE that span and visually
                        // striking through the subline's own text. Moved to
                        // +44 — proven via real generated-PDF content-stream
                        // coordinate extraction (not assumed) to clear the
                        // subline's own descenders with real margin, never
                        // through it.
                        $canvas->line($safeLeftX, $chromeHeaderTopY + 44, $safeRightX, $chromeHeaderTopY + 44, [0.067, 0.067, 0.067], 1.125);
                    }

                    // Footer — R1G.1-VFIX7 restructure to a genuine TWO-LINE
                    // layout (external QA found the previous single-row
                    // layout's centred tertiary line overlapping the
                    // left-hand identity line whenever that identity string
                    // was long, reading as duplicated/overlapping text):
                    // Line 1 = identity (left) + "Page X of Y" (right, same
                    // row); Line 2 = the small centred tertiary brand line,
                    // vertically clear of Line 1 so the two can never
                    // horizontally collide regardless of either string's
                    // length. "Page X of Y" is now drawn HERE, using the
                    // real $pageNum/$pageCount this closure already
                    // receives, rather than via the separate, independently
                    // positioned page_text() mechanism below — ONE chrome
                    // renderer for the whole repeating footer, not two.
                    $footerBandHeight = 38;
                    $footerRuleY = $chromeFooterBottomY - $footerBandHeight;
                    $canvas->line($safeLeftX, $footerRuleY, $safeRightX, $footerRuleY, [0.067, 0.067, 0.067], 1.125);
                    $canvas->text($safeLeftX, $footerRuleY + 10, $repeatingChrome['footer_left'], $chromeFontNormal, 7, [0.267, 0.267, 0.267]);
                    if ($includePageNumbers) {
                        $pageNumText = "Page {$pageNum} of {$pageCount}";
                        $pageNumTextWidth = $metrics->getTextWidth($pageNumText, $chromeFontNormal, 7);
                        $canvas->text($safeRightX - $pageNumTextWidth, $footerRuleY + 10, $pageNumText, $chromeFontNormal, 7, [0.267, 0.267, 0.267]);
                    }
                    if (!empty($repeatingChrome['footer_center'])) {
                        // Line 2 — 7pt normal line height is 8.96pt; +10 + 8.96
                        // + a 3pt clearance gap = +21.96, rounded to +22.
                        $centerText = $repeatingChrome['footer_center'];
                        $centerTextWidth = $metrics->getTextWidth($centerText, $chromeFontNormal, 7);
                        $centerX = $safeLeftX + (($safeRightX - $safeLeftX - $centerTextWidth) / 2);
                        $canvas->text($centerX, $footerRuleY + 22, $centerText, $chromeFontNormal, 7, [0.533, 0.533, 0.533]);
                    }
                }
                if ($includePageNumbers && !$repeatingChrome) {
                    // Small, restrained, bottom-right — position
                    // controlled by $pageNumberX/$pageNumberY (see this
                    // method's own docblock for why a view may need to
                    // override either). R1G.1-VFIX7: when $repeatingChrome
                    // is present, the page number is drawn as part of that
                    // SAME footer block above instead (aligned to its own
                    // identity row) — this branch stays exactly as it
                    // always was for any caller that passes
                    // $includePageNumbers without $repeatingChrome, so it
                    // remains fully backward compatible.
                    $canvas->page_text($pageNumberX, $pageNumberY, 'Page {PAGE_NUM} of {PAGE_COUNT}', $pageNumberFont, 8, [0.4, 0.4, 0.4]);
                }
            });
        }

        $fileName  = Str::slug($title) . '-' . now()->format('Ymd-His') . '.pdf';
        $filePath  = "projects/{$project->id}/generated/{$fileName}";

        Storage::disk('local')->put($filePath, $pdf->output());

        $doc = Document::create([
            'project_id'       => $project->id,
            'organization_id'  => $project->organization_id,
            'created_by'       => $user->id,
            'title'            => $title,
            'type'             => $type,
            'category'         => $category,
            'reference_number' => $reference,
            'status'           => 'issued',
            'file_path'        => $filePath,
            'file_name'        => $fileName,
            'mime_type'        => 'application/pdf',
            'file_size'        => Storage::disk('local')->size($filePath),
            'ai_generated'     => false,
            'template_data'    => $viewData,
            'documentable_type' => $relatedModel ? get_class($relatedModel) : null,
            'documentable_id'   => $relatedModel ? $relatedModel->id : null,
            'trade_package_id'  => $tradePackage?->id,
        ]);

        return $doc;
    }
}
