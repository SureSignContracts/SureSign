<?php

namespace App\Services;

use App\Models\Document;
use App\Models\PaymentApplication;
use App\Services\BrandingService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use Illuminate\Support\Facades\Storage;
use App\Models\BrandingSetting;
use App\Models\User;

class ExcelGenerationService
{
    // Construction QS blue palette
    private const DARK_BLUE   = 'FF1F3864';  // main title bars
    private const MID_BLUE    = 'FF2E75B6';  // section / column headers
    private const LIGHT_BLUE  = 'FFDCE6F1';  // label rows, alternating tint
    private const TOTAL_BLUE  = 'FFBDD7EE';  // total rows
    private const ALT_ROW     = 'FFEEF3FA';  // odd data rows
    private const WHITE       = 'FFFFFFFF';
    private const BORDER_COL  = 'FFB8CCE4';
    private const MUTED_TEXT  = 'FF595959';

    private const MONEY_FMT = '£#,##0.00';
    private const PCT_FMT   = '0.00%';

    // Consistent letterhead image widths — portrait A4 vs landscape A4
    private const IMG_WIDTH           = 670;
    private const IMG_WIDTH_LANDSCAPE = 950;

    private const VALID_PAYMENT_TERMS = [7, 14, 21, 28, 30, 45, 60];

    private ?BrandingSetting $branding = null;

    // ─── Public entry point ───────────────────────────────────────────────────

    public static function generatePaymentApplicationWorkbook(
        PaymentApplication $pa,
        User               $user
    ): Document {
        return (new self())->generate($pa, $user);
    }

    // ─── Main generator ──────────────────────────────────────────────────────

    private function generate(PaymentApplication $pa, User $user): Document
    {
        $pa->load(['contract', 'tradePackage', 'project', 'creator', 'linkedVariations']);
        $project = $pa->project;

        $this->branding = BrandingService::forOrganization($project->organization_id);

        $spreadsheet = new Spreadsheet();
        $spreadsheet->getDefaultStyle()->getFont()->setName('Calibri')->setSize(10);
        $spreadsheet->removeSheetByIndex(0);

        // Build breakdown sheets first — we need their total cell references
        $this->buildCoveringLetter($spreadsheet, $pa);
        $mwRef  = $this->buildMeasuredWorks($spreadsheet, $pa);
        $varRef = $this->buildVariations($spreadsheet, $pa);
        $matRef = $this->buildMaterialsOnSite($spreadsheet, $pa);

        // Insert Application Summary at position 1 (after Covering Letter)
        $ws = new Worksheet($spreadsheet, 'Application Summary');
        $spreadsheet->addSheet($ws, 1);
        $this->writeApplicationSummary($ws, $pa, $mwRef, $varRef, $matRef);

        $spreadsheet->setActiveSheetIndex(1);

        // ─── Save ─────────────────────────────────────────────────────────────

        $appNum   = $pa->application_number;
        $fileName = "payment-application-{$appNum}-" . now()->format('Ymd-His') . '.xlsx';
        $filePath = "projects/{$project->id}/generated/{$fileName}";

        $tmp = tempnam(sys_get_temp_dir(), 'pa_xls_');
        (new Xlsx($spreadsheet))->save($tmp);
        Storage::disk('local')->put($filePath, file_get_contents($tmp));
        @unlink($tmp);

        // ─── Document record ──────────────────────────────────────────────────

        $doc = Document::create([
            'project_id'        => $project->id,
            'organization_id'   => $project->organization_id,
            'created_by'        => $user->id,
            'title'             => "Payment Application #{$appNum} — Workbook",
            'type'              => 'payment-application-excel',
            'category'          => 'Payment Applications',
            'reference_number'  => $pa->reference,
            'status'            => 'issued',
            'file_path'         => $filePath,
            'file_name'         => $fileName,
            'mime_type'         => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'file_size'         => Storage::disk('local')->size($filePath),
            'ai_generated'      => false,
            'documentable_type' => PaymentApplication::class,
            'documentable_id'   => $pa->id,
        ]);

        return $doc;
    }

    // ─── A4 print setup ──────────────────────────────────────────────────────

    private function setupPageForPrint(Worksheet $ws, bool $landscape = false): void
    {
        $setup = $ws->getPageSetup();
        $setup->setPaperSize(PageSetup::PAPERSIZE_A4);
        $setup->setOrientation(
            $landscape ? PageSetup::ORIENTATION_LANDSCAPE : PageSetup::ORIENTATION_PORTRAIT
        );
        $setup->setFitToPage(true);
        $setup->setFitToWidth(1);
        $setup->setFitToHeight(0);

        $ws->getPageMargins()
            ->setTop(0.5)->setBottom(0.5)
            ->setLeft(0.5)->setRight(0.5)
            ->setHeader(0.2)->setFooter(0.2);
    }

    // ─── Branding header ─────────────────────────────────────────────────────
    // Places the company letterhead image across the full printable width.
    // Returns the first usable content row after the header zone.

    private function insertBrandingHeader(
        Worksheet $ws,
        string    $firstCol  = 'A',
        string    $lastCol   = 'I',
        int       $imgWidth  = self::IMG_WIDTH
    ): int {
        $headerPath = BrandingService::headerPath($this->branding);
        $logoPath   = BrandingService::logoPath($this->branding);
        $usePath    = $headerPath ?? $logoPath;

        if ($usePath) {
            try {
                $drawing = new Drawing();
                $drawing->setName('Company Header');
                $drawing->setDescription('Letterhead header');
                $drawing->setPath($usePath);
                $drawing->setWidth($imgWidth);
                $drawing->setResizeProportional(true);
                $drawing->setCoordinates("{$firstCol}1");
                $drawing->setOffsetX(0);
                $drawing->setOffsetY(0);
                $drawing->setWorksheet($ws);

                // Calculate the scaled height so we reserve the right number of rows.
                // Each row is ~15pt ≈ 15px at default Excel zoom.
                $scaledHeight = 80; // safe fallback
                $info = @getimagesize($usePath);
                if ($info && $info[0] > 0) {
                    $scaledHeight = (int) round($imgWidth * $info[1] / $info[0]);
                }

                $rowsNeeded = max(3, (int) ceil(($scaledHeight + 2) / 15));
                for ($i = 1; $i <= $rowsNeeded; $i++) {
                    $ws->getRowDimension($i)->setRowHeight(15);
                }

                // Thin accent strip below image
                $accentRow = $rowsNeeded + 1;
                $this->fillColor($ws, "{$firstCol}{$accentRow}:{$lastCol}{$accentRow}", self::MID_BLUE);
                $ws->getRowDimension($accentRow)->setRowHeight(3);

                return $accentRow + 1;
            } catch (\Throwable) {
                // Image unreadable — fall through to text fallback
            }
        }

        // Text-only fallback
        $name = $this->branding?->company_display_name ?? $this->branding?->company_name ?? '';
        if ($name) {
            $ws->setCellValue("{$firstCol}1", $name);
            $ws->mergeCells("{$firstCol}1:{$lastCol}1");
            $this->applyHeaderStyle($ws, "{$firstCol}1:{$lastCol}1", self::DARK_BLUE, 13, false);
            $ws->getRowDimension(1)->setRowHeight(26);
            return 2;
        }

        return 1;
    }

    // ─── Sheet 1: Covering Letter ─────────────────────────────────────────────

    private function buildCoveringLetter(Spreadsheet $ss, PaymentApplication $pa): void
    {
        $ws = new Worksheet($ss, 'Covering Letter');
        $ss->addSheet($ws);

        $this->setupPageForPrint($ws, false); // A4 portrait

        $project     = $pa->project;
        $contract    = $pa->contract;
        $tp          = $pa->tradePackage;
        $companyName = $this->branding?->company_display_name ?? $this->branding?->company_name ?? 'Our Company';
        $employer    = $contract?->party_name ?? $tp?->contractor_name ?? '—';
        $contractRef = $contract?->reference_number ?? $contract?->title ?? $tp?->name ?? '—';
        $projectName = $project->name ?? '—';

        // Column layout — A/G are narrow margins; B=labels, C=values (wide), F=amounts
        foreach ([
            'A' => 2, 'B' => 24, 'C' => 52,
            'D' => 8, 'E' => 6,  'F' => 20, 'G' => 2,
        ] as $col => $w) {
            $ws->getColumnDimension($col)->setWidth($w);
        }

        // ─── Letterhead header image ──────────────────────────────────────────
        $r = $this->insertBrandingHeader($ws, 'A', 'G');

        // ─── Date ─────────────────────────────────────────────────────────────
        $ws->setCellValue("B{$r}", now()->format('d F Y'));
        $this->font($ws, "B{$r}", color: self::MUTED_TEXT);
        $ws->getRowDimension($r)->setRowHeight(14);
        $r++;

        // ─── Addressee ────────────────────────────────────────────────────────
        $ws->setCellValue("B{$r}", 'To:');
        $ws->setCellValue("C{$r}", $employer);
        $this->font($ws, "B{$r}", bold: true);
        $ws->getRowDimension($r)->setRowHeight(14);
        $r++;

        if ($project->address) {
            $ws->setCellValue("C{$r}", $project->address);
            $ws->getRowDimension($r)->setRowHeight(14);
            $r++;
        }

        // ─── Reference fields ─────────────────────────────────────────────────
        foreach ([
            'Project:'        => $projectName,
            'Contract Ref:'   => $contractRef,
            'Application No:' => $pa->application_number,
        ] as $label => $val) {
            $ws->setCellValue("B{$r}", $label);
            $ws->setCellValue("C{$r}", $val);
            $this->font($ws, "B{$r}", bold: true);
            $ws->getStyle("C{$r}")->getAlignment()->setWrapText(false);
            $ws->getRowDimension($r)->setRowHeight(14);
            $r++;
        }
        $r++;

        // ─── Subject line ─────────────────────────────────────────────────────
        $ws->setCellValue("B{$r}", "Re: Interim Application for Payment No. {$pa->application_number} — {$projectName}");
        $ws->mergeCells("B{$r}:F{$r}");
        $this->font($ws, "B{$r}", bold: true, size: 11);
        $this->fillColor($ws, "B{$r}:F{$r}", self::LIGHT_BLUE);
        $ws->getRowDimension($r)->setRowHeight(16);
        $r++;

        // ─── Salutation ───────────────────────────────────────────────────────
        $ws->setCellValue("B{$r}", 'Dear Sirs,');
        $ws->getRowDimension($r)->setRowHeight(14);
        $r++;

        // ─── Body paragraphs ──────────────────────────────────────────────────
        $dueDate   = $pa->due_date?->format('d F Y') ?? '—';
        $finalDate = $pa->final_date_for_payment?->format('d F Y') ?? '—';
        $noticeDeadline = $pa->payment_notice_deadline?->format('d F Y') ?? null;
        $payLessDeadline = $pa->pay_less_notice_deadline?->format('d F Y') ?? null;

        $para2 = "The application covers the period to the Due Date of {$dueDate}. "
               . "In accordance with the terms of the contract, the Final Date for Payment is {$finalDate}.";
        if ($noticeDeadline) {
            $para2 .= " Your Payment Notice should be issued by {$noticeDeadline}.";
        }
        if ($payLessDeadline) {
            $para2 .= " Any Pay Less Notice must be served no later than {$payLessDeadline}.";
        }

        $bodies = [
            "Please find enclosed our Interim Application for Payment No. {$pa->application_number} in respect of the above-named project at {$projectName}, under contract reference {$contractRef}.",
            $para2,
            "We would be grateful if you could review the enclosed application and issue your Payment Notice in accordance with the contract.",
        ];

        foreach ($bodies as $body) {
            $ws->setCellValue("B{$r}", $body);
            $ws->mergeCells("B{$r}:F{$r}");
            $ws->getStyle("B{$r}")->getAlignment()->setWrapText(true);
            $ws->getStyle("B{$r}")->getAlignment()->setVertical(Alignment::VERTICAL_TOP);
            $ws->getRowDimension($r)->setRowHeight(42);
            $r++;
        }
        $r++;

        // ─── Financial summary table ──────────────────────────────────────────
        $ws->setCellValue("B{$r}", 'SUMMARY OF THIS APPLICATION');
        $ws->mergeCells("B{$r}:F{$r}");
        $this->applyHeaderStyle($ws, "B{$r}:F{$r}", self::MID_BLUE, 10, false);
        $ws->getRowDimension($r)->setRowHeight(18);
        $r++;

        $ws->setCellValue("B{$r}", 'Item');
        $ws->setCellValue("F{$r}", 'Amount (£)');
        $this->font($ws, "B{$r}:F{$r}", bold: true);
        $this->fillColor($ws, "B{$r}:F{$r}", self::LIGHT_BLUE);
        $ws->getStyle("F{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $ws->getRowDimension($r)->setRowHeight(14);
        $r++;

        $summaryStart = $r;
        $gross        = (float) $pa->gross_valuation;
        $retention    = (float) $pa->less_retention;
        $prevCert     = (float) $pa->less_previous_payments;
        $amountDue    = (float) $pa->amount_due;

        $summaryLines = [
            ['Gross value of works to date',                   $gross,      false],
            ['Less: Retention',                                -$retention,  false],
            ['Less: Previously Certified / Paid',             -$prevCert,   false],
            ['Net amount due this application (excl. VAT)',    $amountDue,   true],
        ];

        foreach ($summaryLines as [$label, $amount, $bold]) {
            $ws->setCellValue("B{$r}", $label);
            $ws->setCellValue("F{$r}", $amount);
            $ws->getStyle("F{$r}")->getNumberFormat()->setFormatCode(self::MONEY_FMT);
            $ws->getStyle("F{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            if ($bold) {
                $this->font($ws, "B{$r}:F{$r}", bold: true);
                $this->fillColor($ws, "B{$r}:F{$r}", self::TOTAL_BLUE);
            }
            $ws->getRowDimension($r)->setRowHeight(14);
            $r++;
        }

        $vatAmount = (float) ($pa->vat_amount ?? 0);
        $vatRate   = (float) ($pa->vat_rate ?? 20);
        if ($vatAmount > 0) {
            $ws->setCellValue("B{$r}", "VAT @ {$vatRate}%");
            $ws->setCellValue("F{$r}", $vatAmount);
            $ws->getStyle("F{$r}")->getNumberFormat()->setFormatCode(self::MONEY_FMT);
            $ws->getStyle("F{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $ws->getRowDimension($r)->setRowHeight(14);
            $r++;

            $ws->setCellValue("B{$r}", 'TOTAL DUE (incl. VAT)');
            $ws->setCellValue("F{$r}", (float)($pa->total_due_including_vat ?? 0));
            $this->font($ws, "B{$r}:F{$r}", bold: true);
            $this->fillColor($ws, "B{$r}:F{$r}", self::TOTAL_BLUE);
            $ws->getStyle("F{$r}")->getNumberFormat()->setFormatCode(self::MONEY_FMT);
            $ws->getStyle("F{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $ws->getRowDimension($r)->setRowHeight(14);
            $r++;
        }

        $this->boxBorder($ws, "B{$summaryStart}:F" . ($r - 1));
        $this->innerBorders($ws, "B{$summaryStart}:F" . ($r - 1));

        $r++;

        // ─── Closing ──────────────────────────────────────────────────────────
        $ws->setCellValue("B{$r}", 'We trust the above is in order and look forward to receiving your Payment Notice in due course.');
        $ws->mergeCells("B{$r}:F{$r}");
        $ws->getStyle("B{$r}")->getAlignment()->setWrapText(true);
        $ws->getRowDimension($r)->setRowHeight(-1);
        $r += 2;

        $ws->setCellValue("B{$r}", 'Yours faithfully,');
        $ws->getRowDimension($r)->setRowHeight(14);
        $r += 3;

        $ws->setCellValue("B{$r}", '________________________');
        $ws->getRowDimension($r)->setRowHeight(14);
        $r++;

        $ws->setCellValue("B{$r}", "For and on behalf of {$companyName}");
        $this->font($ws, "B{$r}", bold: true);
        $ws->getRowDimension($r)->setRowHeight(14);
        $r++;

        // ─── Footer image (if available) ──────────────────────────────────────
        $footerPath = BrandingService::footerPath($this->branding);
        if ($footerPath) {
            try {
                $footer = new Drawing();
                $footer->setName('Company Footer');
                $footer->setPath($footerPath);
                $footer->setWidth(self::IMG_WIDTH);
                $footer->setResizeProportional(true);
                $footer->setCoordinates("B{$r}");
                $footer->setWorksheet($ws);

                $fInfo = @getimagesize($footerPath);
                $fHeight = ($fInfo && $fInfo[0] > 0)
                    ? (int) round(self::IMG_WIDTH * $fInfo[1] / $fInfo[0])
                    : 50;
                $ws->getRowDimension($r)->setRowHeight(max(18, $fHeight));
            } catch (\Throwable) {
                // footer image unreadable — skip
            }
        }
    }

    // ─── Sheet 2: Application Summary ────────────────────────────────────────

    private function writeApplicationSummary(
        Worksheet $ws,
        PaymentApplication $pa,
        array $mwRef,
        array $varRef,
        array $matRef
    ): void {
        $this->setupPageForPrint($ws, false); // A4 portrait

        $project     = $pa->project;
        $contract    = $pa->contract;
        $tp          = $pa->tradePackage;
        $companyName = $this->branding?->company_display_name ?? $this->branding?->company_name ?? 'Our Company';
        $employer    = $contract?->party_name ?? $tp?->contractor_name ?? '—';
        $contractRef = $contract?->reference_number ?? $contract?->title ?? $tp?->name ?? '—';
        $contractSum = (float) ($contract?->contract_sum ?? 0);
        $retPct      = (float) ($contract?->retention_percentage ?? 0);
        $payTerms = $this->sanitisePaymentTerms($contract?->payment_frequency ?? $contract?->payment_terms_days);

        // A=margin, B=margin, C=left-label, D=left-value, E=gap, F=right-label, G=right-value, H=amount
        foreach ([
            'A' => 2, 'B' => 2, 'C' => 26, 'D' => 30,
            'E' => 2, 'F' => 28, 'G' => 26, 'H' => 20,
        ] as $col => $w) {
            $ws->getColumnDimension($col)->setWidth($w);
        }

        // ─── Letterhead header image ──────────────────────────────────────────
        $r = $this->insertBrandingHeader($ws, 'A', 'H');

        // Application title bar
        $ws->setCellValue("C{$r}", 'INTERIM APPLICATION FOR PAYMENT');
        $ws->mergeCells("C{$r}:H{$r}");
        $this->applyHeaderStyle($ws, "C{$r}:H{$r}", self::MID_BLUE, 12, true);
        $ws->getRowDimension($r)->setRowHeight(24);
        $r++;

        // ─── Two-column detail panel ──────────────────────────────────────────
        $leftFields = [
            'Application No:'            => $pa->application_number,
            'Contract No:'               => $contractRef,
            'Contract Name:'             => $contract?->title ?? $tp?->name ?? $project->name,
            'Employer / Main Contractor:'=> $employer,
            'Site / Project:'            => $project->name ?? '—',
            'Subcontractor / Party:'     => $companyName,
        ];

        $rightFields = [
            'Application Date:'         => $pa->application_date?->format('d M Y') ?? now()->format('d M Y'),
            'Valuation Period End:'     => $pa->valuation_period_end?->format('d M Y') ?? '—',
            'Due Date for Payment:'     => $pa->due_date?->format('d M Y') ?? '—',
            'Final Date for Payment:'   => $pa->final_date_for_payment?->format('d M Y') ?? '—',
            'Payment Notice Deadline:'  => $pa->payment_notice_deadline?->format('d M Y') ?? '—',
            'Pay Less Notice Deadline:' => $pa->pay_less_notice_deadline?->format('d M Y') ?? '—',
            'Contract Value (£):'       => $contractSum,
            'Retention (%):'            => $retPct / 100,
        ];

        $panelStart = $r;
        foreach ($leftFields as $label => $val) {
            $ws->setCellValue("C{$r}", $label);
            $ws->setCellValue("D{$r}", $val);
            $this->font($ws, "C{$r}", bold: true);
            $this->fillColor($ws, "C{$r}:D{$r}", self::LIGHT_BLUE);
            $ws->getStyle("D{$r}")->getAlignment()->setWrapText(false);
            $ws->getRowDimension($r)->setRowHeight(14);
            $r++;
        }

        $r = $panelStart;
        foreach ($rightFields as $label => $val) {
            $ws->setCellValue("F{$r}", $label);
            $ws->setCellValue("G{$r}", $val);
            $this->font($ws, "F{$r}", bold: true);
            $this->fillColor($ws, "F{$r}:G{$r}", self::LIGHT_BLUE);
            if (is_float($val) && str_contains($label, '£')) {
                $ws->getStyle("G{$r}")->getNumberFormat()->setFormatCode(self::MONEY_FMT);
                $ws->getStyle("G{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            } elseif (str_contains($label, '(%)')) {
                $ws->getStyle("G{$r}")->getNumberFormat()->setFormatCode(self::PCT_FMT);
                $ws->getStyle("G{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            }
            $ws->getRowDimension($r)->setRowHeight(14);
            $r++;
        }

        $panelEnd = $panelStart + max(count($leftFields), count($rightFields)) - 1;
        $this->boxBorder($ws, "C{$panelStart}:D{$panelEnd}");
        $this->innerBorders($ws, "C{$panelStart}:D{$panelEnd}");
        $this->boxBorder($ws, "F{$panelStart}:G{$panelEnd}");
        $this->innerBorders($ws, "F{$panelStart}:G{$panelEnd}");

        $r = $panelEnd + 1;

        // Payment Terms note line
        if ($payTerms !== '—') {
            $ws->setCellValue("C{$r}", "Payment Terms: {$payTerms}");
            $ws->mergeCells("C{$r}:H{$r}");
            $this->font($ws, "C{$r}", color: self::MUTED_TEXT);
            $ws->getRowDimension($r)->setRowHeight(13);
            $r++;
        }
        $r++;

        // ─── Valuation Summary ────────────────────────────────────────────────
        $ws->setCellValue("C{$r}", 'VALUATION SUMMARY');
        $ws->mergeCells("C{$r}:H{$r}");
        $this->applyHeaderStyle($ws, "C{$r}:H{$r}", self::MID_BLUE);
        $ws->getRowDimension($r)->setRowHeight(16);
        $r++;

        $ws->setCellValue("C{$r}", 'Section');
        $ws->setCellValue("G{$r}", 'Contract Sum (£)');
        $ws->setCellValue("H{$r}", 'This Valuation (£)');
        $this->font($ws, "C{$r}:H{$r}", bold: true);
        $this->fillColor($ws, "C{$r}:H{$r}", self::LIGHT_BLUE);
        $ws->getStyle("G{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $ws->getStyle("H{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $ws->getRowDimension($r)->setRowHeight(14);
        $r++;

        $valTableStart = $r;
        $mwRow  = $r;
        $varRow = $r + 1;
        $matRow = $r + 2;

        $sections = [
            'Measured Works'    => [$contractSum, "='{$mwRef['sheet']}'!{$mwRef['col']}{$mwRef['row']}"],
            'Variations'        => [0.0,          "='{$varRef['sheet']}'!{$varRef['col']}{$varRef['row']}"],
            'Materials on Site' => [0.0,          "='{$matRef['sheet']}'!{$matRef['col']}{$matRef['row']}"],
        ];

        foreach ($sections as $name => [$cSum, $valFormula]) {
            $ws->setCellValue("C{$r}", $name);
            $ws->setCellValue("G{$r}", $cSum);
            $ws->setCellValue("H{$r}", $valFormula);
            $ws->getStyle("G{$r}")->getNumberFormat()->setFormatCode(self::MONEY_FMT);
            $ws->getStyle("H{$r}")->getNumberFormat()->setFormatCode(self::MONEY_FMT);
            $ws->getStyle("G{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $ws->getStyle("H{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $ws->getRowDimension($r)->setRowHeight(14);
            $r++;
        }

        // Gross Valuation total
        $ws->setCellValue("C{$r}", 'GROSS VALUATION');
        $ws->setCellValue("G{$r}", $contractSum);
        $ws->setCellValue("H{$r}", "=H{$mwRow}+H{$varRow}+H{$matRow}");
        $this->font($ws, "C{$r}:H{$r}", bold: true);
        $this->fillColor($ws, "C{$r}:H{$r}", self::TOTAL_BLUE);
        $ws->getStyle("G{$r}")->getNumberFormat()->setFormatCode(self::MONEY_FMT);
        $ws->getStyle("H{$r}")->getNumberFormat()->setFormatCode(self::MONEY_FMT);
        $ws->getStyle("G{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $ws->getStyle("H{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $ws->getRowDimension($r)->setRowHeight(18);
        $grossRow = $r;

        $this->boxBorder($ws, "C{$valTableStart}:H{$r}");
        $this->innerBorders($ws, "C{$valTableStart}:H{$r}");
        $ws->getStyle("C{$r}:H{$r}")->getBorders()->getTop()
            ->setBorderStyle(Border::BORDER_MEDIUM)->getColor()->setARGB(self::MID_BLUE);
        $r++;

        // ─── Payment Calculation ──────────────────────────────────────────────
        $ws->setCellValue("C{$r}", 'PAYMENT CALCULATION');
        $ws->mergeCells("C{$r}:H{$r}");
        $this->applyHeaderStyle($ws, "C{$r}:H{$r}", self::MID_BLUE);
        $ws->getRowDimension($r)->setRowHeight(16);
        $r++;

        $ws->setCellValue("C{$r}", 'Item');
        $ws->setCellValue("H{$r}", 'Amount (£)');
        $this->font($ws, "C{$r}:H{$r}", bold: true);
        $this->fillColor($ws, "C{$r}:H{$r}", self::LIGHT_BLUE);
        $ws->getStyle("H{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $ws->getRowDimension($r)->setRowHeight(14);
        $r++;

        $retPctDecimal  = $retPct / 100;
        $prevCert       = (float) $pa->less_previous_payments;
        $vatRate        = (float) ($pa->vat_rate ?? 20);

        $calcStart      = $r;
        $grossCalcRow   = $r;
        $retentionRow   = $r + 1;
        $netAfterRetRow = $r + 2;
        $lessPrevRow    = $r + 3;
        $amtDueRow      = $r + 4;
        $vatRow         = $r + 5;
        $totalDueRow    = $r + 6;

        $calcLines = [
            [$grossCalcRow,   'Gross Valuation',                          "=H{$grossRow}",                             false],
            [$retentionRow,   "Retention @ {$retPct}%",                  "=-H{$grossCalcRow}*{$retPctDecimal}",        false],
            [$netAfterRetRow, 'Net after Retention',                      "=H{$grossCalcRow}+H{$retentionRow}",         false],
            [$lessPrevRow,    'Less Previously Certified / Paid',         $prevCert > 0 ? -$prevCert : 0.0,            false],
            [$amtDueRow,      'Amount Due this Application (excl. VAT)', "=H{$netAfterRetRow}+H{$lessPrevRow}",        true],
            [$vatRow,         "VAT @ {$vatRate}%",                       "=H{$amtDueRow}*" . ($vatRate / 100),         false],
            [$totalDueRow,    'TOTAL DUE (incl. VAT)',                   "=H{$amtDueRow}+H{$vatRow}",                  true],
        ];

        foreach ($calcLines as [$rowNum, $label, $val, $bold]) {
            $ws->setCellValue("C{$rowNum}", $label);
            $ws->setCellValue("H{$rowNum}", $val);
            $ws->getStyle("H{$rowNum}")->getNumberFormat()->setFormatCode(self::MONEY_FMT);
            $ws->getStyle("H{$rowNum}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            if ($bold) {
                $this->font($ws, "C{$rowNum}:H{$rowNum}", bold: true);
                $this->fillColor($ws, "C{$rowNum}:H{$rowNum}", self::TOTAL_BLUE);
            }
            $ws->getRowDimension($rowNum)->setRowHeight(14);
        }

        $this->boxBorder($ws, "C{$calcStart}:H{$totalDueRow}");
        $this->innerBorders($ws, "C{$calcStart}:H{$totalDueRow}");
        $ws->getStyle("C{$amtDueRow}:H{$amtDueRow}")->getBorders()->getTop()
            ->setBorderStyle(Border::BORDER_MEDIUM)->getColor()->setARGB(self::MID_BLUE);
        $ws->getStyle("C{$totalDueRow}:H{$totalDueRow}")->getBorders()->getTop()
            ->setBorderStyle(Border::BORDER_MEDIUM)->getColor()->setARGB(self::MID_BLUE);

        $r = $totalDueRow + 1;

        // ─── Notes ────────────────────────────────────────────────────────────
        if (!empty($pa->notes)) {
            $ws->setCellValue("C{$r}", 'Notes:');
            $this->font($ws, "C{$r}", bold: true);
            $ws->getRowDimension($r)->setRowHeight(13);
            $r++;
            $ws->setCellValue("C{$r}", $pa->notes);
            $ws->mergeCells("C{$r}:H{$r}");
            $ws->getStyle("C{$r}")->getAlignment()->setWrapText(true);
            $ws->getStyle("C{$r}")->getAlignment()->setVertical(Alignment::VERTICAL_TOP);
            $ws->getRowDimension($r)->setRowHeight(40);
            $this->boxBorder($ws, "C{$r}:H{$r}");
            $r += 2;
        }

        // ─── Signature block ──────────────────────────────────────────────────
        $ws->setCellValue("C{$r}", 'Prepared by:');
        $ws->setCellValue("D{$r}", $pa->creator?->name ?? '');
        $ws->setCellValue("F{$r}", 'Signed:');
        $ws->setCellValue("G{$r}", '________________________');
        $this->font($ws, "C{$r}", bold: true);
        $this->font($ws, "F{$r}", bold: true);
        $ws->getRowDimension($r)->setRowHeight(14);
        $r++;

        $ws->setCellValue("C{$r}", 'For and on behalf of:');
        $ws->setCellValue("D{$r}", $companyName);
        $ws->setCellValue("F{$r}", 'Date:');
        $ws->setCellValue("G{$r}", now()->format('d M Y'));
        $this->font($ws, "C{$r}", bold: true);
        $this->font($ws, "F{$r}", bold: true);
        $ws->getRowDimension($r)->setRowHeight(14);
    }

    // ─── Sheet 3: Measured Works ──────────────────────────────────────────────

    private function buildMeasuredWorks(Spreadsheet $ss, PaymentApplication $pa): array
    {
        $ws   = new Worksheet($ss, 'Measured Works');
        $ss->addSheet($ws);

        $this->setupPageForPrint($ws, true); // A4 landscape

        $rows        = ($pa->breakdown ?? [])['measured_works'] ?? [];
        $gross       = (float) $pa->gross_valuation;
        $isFallback  = empty($rows);

        if ($isFallback) {
            $rows = [[
                'item_number'  => 1,
                'description'  => 'Manual Gross Valuation',
                'qty'          => 1,
                'unit'         => 'item',
                'rate'         => $gross,
                'pct_complete' => 100,
                'notes'        => '',
            ]];
        }

        // Columns: A=Item, B=Description, C=Qty, D=Unit, E=Rate, F=ContractVal, G=%Comp, H=Valuation, I=Notes
        foreach ([
            'A' => 8, 'B' => 52, 'C' => 8, 'D' => 8,
            'E' => 16, 'F' => 20, 'G' => 14, 'H' => 20, 'I' => 28,
        ] as $col => $w) {
            $ws->getColumnDimension($col)->setWidth($w);
        }

        // ─── Letterhead header image ──────────────────────────────────────────
        $r = $this->insertBrandingHeader($ws, 'A', 'I', self::IMG_WIDTH_LANDSCAPE);

        // Sheet title
        $ws->setCellValue("A{$r}", 'MEASURED WORKS');
        $ws->mergeCells("A{$r}:I{$r}");
        $this->applyHeaderStyle($ws, "A{$r}:I{$r}", self::DARK_BLUE, 12);
        $ws->getRowDimension($r)->setRowHeight(22);
        $r++;

        // Contract / application info bar
        $r = $this->writeBreakdownInfoBar($ws, $pa, $r);

        // Fallback notice
        if ($isFallback) {
            $note = 'Manual valuation fallback — no itemised breakdown entered. Edit this application to add measured works detail.';
            $ws->setCellValue("A{$r}", $note);
            $ws->mergeCells("A{$r}:I{$r}");
            $this->font($ws, "A{$r}", color: self::MUTED_TEXT);
            $this->fillColor($ws, "A{$r}:I{$r}", 'FFFFF8DC'); // light amber
            $ws->getRowDimension($r)->setRowHeight(14);
            $r++;
        }

        // ─── Column headers ───────────────────────────────────────────────────
        $headers = ['Item', 'Description', 'Qty', 'Unit', 'Rate (£)', 'Contract Value (£)', '% Complete', 'Valuation (£)', 'Notes'];
        foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I'] as $i => $col) {
            $ws->setCellValue("{$col}{$r}", $headers[$i]);
            $ws->getStyle("{$col}{$r}")->getAlignment()->setHorizontal(
                in_array($col, ['C', 'E', 'F', 'G', 'H']) ? Alignment::HORIZONTAL_RIGHT : Alignment::HORIZONTAL_LEFT
            );
        }
        $this->applyHeaderStyle($ws, "A{$r}:I{$r}", self::MID_BLUE);
        $ws->getRowDimension($r)->setRowHeight(18);
        $r++;

        $dataStartRow = $r;

        foreach ($rows as $i => $item) {
            $bg = ($i % 2 === 0) ? self::WHITE : self::ALT_ROW;
            $ws->setCellValue("A{$r}", $item['item_number'] ?? ($i + 1));
            $ws->setCellValue("B{$r}", $item['description'] ?? '');
            $ws->setCellValue("C{$r}", (float)($item['qty'] ?? 1));
            $ws->setCellValue("D{$r}", $item['unit'] ?? 'item');
            $ws->setCellValue("E{$r}", (float)($item['rate'] ?? 0));
            $ws->setCellValue("F{$r}", "=C{$r}*E{$r}");           // Contract Value
            $ws->setCellValue("G{$r}", (float)($item['pct_complete'] ?? 0) / 100);
            $ws->setCellValue("H{$r}", "=F{$r}*G{$r}");           // Valuation
            $ws->setCellValue("I{$r}", $item['notes'] ?? '');

            $this->formatBreakdownRow($ws, $r, ['C', 'E', 'F', 'G', 'H'], self::MONEY_FMT, self::PCT_FMT);
            $this->fillColor($ws, "A{$r}:I{$r}", $bg);
            $ws->getRowDimension($r)->setRowHeight(15);
            $r++;
        }

        $dataEndRow = $r - 1;

        $ws->mergeCells("A{$r}:G{$r}");
        $ws->setCellValue("A{$r}", 'TOTAL MEASURED WORKS');
        $ws->setCellValue("H{$r}", "=SUM(H{$dataStartRow}:H{$dataEndRow})");
        $this->styleTotalRow($ws, $r, 'A', 'I', 'H');

        $this->boxBorder($ws, "A{$dataStartRow}:I{$r}");
        $this->innerBorders($ws, "A{$dataStartRow}:I{$r}");
        $ws->getStyle("A{$r}:I{$r}")->getBorders()->getTop()
            ->setBorderStyle(Border::BORDER_MEDIUM)->getColor()->setARGB(self::MID_BLUE);

        return ['sheet' => 'Measured Works', 'col' => 'H', 'row' => $r];
    }

    // ─── Sheet 4: Variations ─────────────────────────────────────────────────

    private function buildVariations(Spreadsheet $ss, PaymentApplication $pa): array
    {
        $ws   = new Worksheet($ss, 'Variations');
        $ss->addSheet($ws);

        $this->setupPageForPrint($ws, true); // A4 landscape

        $rows = ($pa->breakdown ?? [])['variations'] ?? [];

        foreach ([
            'A' => 7, 'B' => 16, 'C' => 46, 'D' => 14,
            'E' => 20, 'F' => 13, 'G' => 20, 'H' => 14, 'I' => 28,
        ] as $col => $w) {
            $ws->getColumnDimension($col)->setWidth($w);
        }

        // ─── Letterhead header image ──────────────────────────────────────────
        $r = $this->insertBrandingHeader($ws, 'A', 'I', self::IMG_WIDTH_LANDSCAPE);

        $ws->setCellValue("A{$r}", 'VARIATIONS');
        $ws->mergeCells("A{$r}:I{$r}");
        $this->applyHeaderStyle($ws, "A{$r}:I{$r}", self::DARK_BLUE, 12);
        $ws->getRowDimension($r)->setRowHeight(22);
        $r++;

        $r = $this->writeBreakdownInfoBar($ws, $pa, $r);

        // ─── Column headers ───────────────────────────────────────────────────
        $headers = ['No.', 'Ref (COI/SI)', 'Description', 'Date Issued', 'Variation Value (£)', '% Complete', 'Valuation (£)', 'Status', 'Notes'];
        foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I'] as $i => $col) {
            $ws->setCellValue("{$col}{$r}", $headers[$i]);
            $ws->getStyle("{$col}{$r}")->getAlignment()->setHorizontal(
                in_array($col, ['E', 'F', 'G']) ? Alignment::HORIZONTAL_RIGHT : Alignment::HORIZONTAL_LEFT
            );
        }
        $this->applyHeaderStyle($ws, "A{$r}:I{$r}", self::MID_BLUE);
        $ws->getRowDimension($r)->setRowHeight(18);
        $r++;

        $dataStartRow = $r;

        // Running index across both manual and linked rows, so alternating row
        // shading stays consistent.
        $i = 0;

        // ── Manual variation items (from the application breakdown) ──────────────
        foreach ($rows as $item) {
            $bg = ($i % 2 === 0) ? self::WHITE : self::ALT_ROW;
            $ws->setCellValue("A{$r}", $item['ref'] ?? ($i + 1));
            $ws->setCellValue("B{$r}", $item['instruction_ref'] ?? '');
            $ws->setCellValue("C{$r}", $item['description'] ?? '');
            $ws->setCellValue("D{$r}", $item['date_issued'] ?? '');
            $ws->setCellValue("E{$r}", (float)($item['variation_value'] ?? 0));
            $ws->setCellValue("F{$r}", (float)($item['pct_complete'] ?? 100) / 100);
            $ws->setCellValue("G{$r}", "=E{$r}*F{$r}");           // Valuation
            $ws->setCellValue("H{$r}", $item['status'] ?? '');
            $ws->setCellValue("I{$r}", $item['notes'] ?? '');

            $ws->getStyle("E{$r}")->getNumberFormat()->setFormatCode(self::MONEY_FMT);
            $ws->getStyle("E{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $ws->getStyle("F{$r}")->getNumberFormat()->setFormatCode(self::PCT_FMT);
            $ws->getStyle("F{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $ws->getStyle("G{$r}")->getNumberFormat()->setFormatCode(self::MONEY_FMT);
            $ws->getStyle("G{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $this->fillColor($ws, "A{$r}:I{$r}", $bg);
            $ws->getRowDimension($r)->setRowHeight(15);
            $r++;
            $i++;
        }

        // ── Linked approved variations (snapshot values only — never live) ───────
        $linked = $pa->linkedVariations ?? collect();
        foreach ($linked as $lv) {
            $bg = ($i % 2 === 0) ? self::WHITE : self::ALT_ROW;
            $ws->setCellValue("A{$r}", $lv->variation_number_at_inclusion ?? '');
            $ws->setCellValue("B{$r}", '');
            $ws->setCellValue("C{$r}", $lv->title_at_inclusion ?? '');
            $ws->setCellValue("D{$r}", '');
            $ws->setCellValue("E{$r}", (float)($lv->amount_at_inclusion ?? 0));
            $ws->setCellValue("F{$r}", 1.0);                      // included at full snapshot amount
            $ws->setCellValue("G{$r}", "=E{$r}*F{$r}");           // Valuation
            $ws->setCellValue("H{$r}", $lv->status_at_inclusion ?? '');
            $ws->setCellValue("I{$r}", 'Linked Approved Variation');

            $ws->getStyle("E{$r}")->getNumberFormat()->setFormatCode(self::MONEY_FMT);
            $ws->getStyle("E{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $ws->getStyle("F{$r}")->getNumberFormat()->setFormatCode(self::PCT_FMT);
            $ws->getStyle("F{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $ws->getStyle("G{$r}")->getNumberFormat()->setFormatCode(self::MONEY_FMT);
            $ws->getStyle("G{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $this->fillColor($ws, "A{$r}:I{$r}", $bg);
            $ws->getRowDimension($r)->setRowHeight(15);
            $r++;
            $i++;
        }

        $hasRows = !empty($rows) || $linked->count() > 0;

        // Empty-state message when no variation rows exist
        if (!$hasRows) {
            $ws->setCellValue("A{$r}", 'No variations are included in this application.');
            $ws->mergeCells("A{$r}:I{$r}");
            $this->font($ws, "A{$r}", color: self::MUTED_TEXT);
            $this->fillColor($ws, "A{$r}:I{$r}", self::LIGHT_BLUE);
            $ws->getRowDimension($r)->setRowHeight(16);
            $r++;
            // Three empty placeholder rows for a clean table appearance
            for ($e = 0; $e < 3; $e++) {
                $ws->getRowDimension($r)->setRowHeight(15);
                $r++;
            }
        }

        $dataEndRow = $r - 1;

        $ws->mergeCells("A{$r}:F{$r}");
        $ws->setCellValue("A{$r}", 'TOTAL VARIATIONS');
        $ws->setCellValue("G{$r}", $hasRows ? "=SUM(G{$dataStartRow}:G{$dataEndRow})" : 0.0);
        $this->styleTotalRow($ws, $r, 'A', 'I', 'G');

        $this->boxBorder($ws, "A{$dataStartRow}:I{$r}");
        $this->innerBorders($ws, "A{$dataStartRow}:I{$r}");
        $ws->getStyle("A{$r}:I{$r}")->getBorders()->getTop()
            ->setBorderStyle(Border::BORDER_MEDIUM)->getColor()->setARGB(self::MID_BLUE);

        return ['sheet' => 'Variations', 'col' => 'G', 'row' => $r];
    }

    // ─── Sheet 5: Materials on Site ───────────────────────────────────────────

    private function buildMaterialsOnSite(Spreadsheet $ss, PaymentApplication $pa): array
    {
        $ws   = new Worksheet($ss, 'Materials on Site');
        $ss->addSheet($ws);

        $this->setupPageForPrint($ws, true); // A4 landscape

        $rows = ($pa->breakdown ?? [])['materials_on_site'] ?? [];

        foreach ([
            'A' => 8, 'B' => 52, 'C' => 8, 'D' => 8,
            'E' => 16, 'F' => 20, 'G' => 13, 'H' => 20, 'I' => 28,
        ] as $col => $w) {
            $ws->getColumnDimension($col)->setWidth($w);
        }

        // ─── Letterhead header image ──────────────────────────────────────────
        $r = $this->insertBrandingHeader($ws, 'A', 'I', self::IMG_WIDTH_LANDSCAPE);

        $ws->setCellValue("A{$r}", 'MATERIALS ON SITE');
        $ws->mergeCells("A{$r}:I{$r}");
        $this->applyHeaderStyle($ws, "A{$r}:I{$r}", self::DARK_BLUE, 12);
        $ws->getRowDimension($r)->setRowHeight(22);
        $r++;

        $r = $this->writeBreakdownInfoBar($ws, $pa, $r);

        // ─── Column headers ───────────────────────────────────────────────────
        $headers = ['Item', 'Description', 'Qty', 'Unit', 'Rate (£)', 'Total Value (£)', '% Claimed', 'Valuation (£)', 'Notes'];
        foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I'] as $i => $col) {
            $ws->setCellValue("{$col}{$r}", $headers[$i]);
            $ws->getStyle("{$col}{$r}")->getAlignment()->setHorizontal(
                in_array($col, ['C', 'E', 'F', 'G', 'H']) ? Alignment::HORIZONTAL_RIGHT : Alignment::HORIZONTAL_LEFT
            );
        }
        $this->applyHeaderStyle($ws, "A{$r}:I{$r}", self::MID_BLUE);
        $ws->getRowDimension($r)->setRowHeight(18);
        $r++;

        $dataStartRow = $r;

        foreach ($rows as $i => $item) {
            $bg = ($i % 2 === 0) ? self::WHITE : self::ALT_ROW;
            $ws->setCellValue("A{$r}", $item['item_number'] ?? ($i + 1));
            $ws->setCellValue("B{$r}", $item['description'] ?? '');
            $ws->setCellValue("C{$r}", (float)($item['qty'] ?? 1));
            $ws->setCellValue("D{$r}", $item['unit'] ?? 'item');
            $ws->setCellValue("E{$r}", (float)($item['rate'] ?? 0));
            $ws->setCellValue("F{$r}", "=C{$r}*E{$r}");           // Total Value
            $ws->setCellValue("G{$r}", (float)($item['claim_pct'] ?? 100) / 100);
            $ws->setCellValue("H{$r}", "=F{$r}*G{$r}");           // Valuation
            $ws->setCellValue("I{$r}", $item['notes'] ?? '');

            $this->formatBreakdownRow($ws, $r, ['C', 'E', 'F', 'G', 'H'], self::MONEY_FMT, self::PCT_FMT);
            $this->fillColor($ws, "A{$r}:I{$r}", $bg);
            $ws->getRowDimension($r)->setRowHeight(15);
            $r++;
        }

        // Empty-state message
        if (empty($rows)) {
            $ws->setCellValue("A{$r}", 'No materials on site are included in this application.');
            $ws->mergeCells("A{$r}:I{$r}");
            $this->font($ws, "A{$r}", color: self::MUTED_TEXT);
            $this->fillColor($ws, "A{$r}:I{$r}", self::LIGHT_BLUE);
            $ws->getRowDimension($r)->setRowHeight(16);
            $r++;
            for ($e = 0; $e < 3; $e++) {
                $ws->getRowDimension($r)->setRowHeight(15);
                $r++;
            }
        }

        $dataEndRow = $r - 1;

        $ws->mergeCells("A{$r}:G{$r}");
        $ws->setCellValue("A{$r}", 'TOTAL MATERIALS ON SITE');
        $ws->setCellValue("H{$r}", empty($rows) ? 0.0 : "=SUM(H{$dataStartRow}:H{$dataEndRow})");
        $this->styleTotalRow($ws, $r, 'A', 'I', 'H');

        $this->boxBorder($ws, "A{$dataStartRow}:I{$r}");
        $this->innerBorders($ws, "A{$dataStartRow}:I{$r}");
        $ws->getStyle("A{$r}:I{$r}")->getBorders()->getTop()
            ->setBorderStyle(Border::BORDER_MEDIUM)->getColor()->setARGB(self::MID_BLUE);

        return ['sheet' => 'Materials on Site', 'col' => 'H', 'row' => $r];
    }

    // ─── Shared layout helpers ────────────────────────────────────────────────

    // Contract / application info bar used by all breakdown sheets
    private function writeBreakdownInfoBar(Worksheet $ws, PaymentApplication $pa, int $r): int
    {
        $contract = $pa->contract;
        $tp       = $pa->tradePackage;
        $ref      = $contract?->reference_number ?? $contract?->title ?? $tp?->name ?? '—';
        $name     = $contract?->title ?? $tp?->name ?? $pa->project->name;

        $ws->setCellValue("A{$r}", 'Contract No:');
        $ws->setCellValue("C{$r}", $ref);
        $ws->setCellValue("E{$r}", 'Contract:');
        $ws->setCellValue("G{$r}", $name);
        $this->font($ws, "A{$r}", bold: true);
        $this->font($ws, "E{$r}", bold: true);
        $this->fillColor($ws, "A{$r}:I{$r}", self::LIGHT_BLUE);
        $ws->getStyle("C{$r}")->getAlignment()->setWrapText(false);
        $ws->getStyle("G{$r}")->getAlignment()->setWrapText(false);
        $ws->getRowDimension($r)->setRowHeight(14);
        $r++;

        $ws->setCellValue("A{$r}", 'Application No:');
        $ws->setCellValue("C{$r}", $pa->application_number);
        $ws->setCellValue("E{$r}", 'Due Date:');
        $ws->setCellValue("G{$r}", $pa->due_date?->format('d M Y') ?? '—');
        $ws->setCellValue("H{$r}", $pa->final_date_for_payment ? 'FDP: ' . $pa->final_date_for_payment->format('d M Y') : '');
        $this->font($ws, "A{$r}", bold: true);
        $this->font($ws, "E{$r}", bold: true);
        $this->fillColor($ws, "A{$r}:I{$r}", self::LIGHT_BLUE);
        $ws->getRowDimension($r)->setRowHeight(14);
        $r++;

        return $r;
    }

    // Format rate / amount / percent columns in breakdown data rows
    private function formatBreakdownRow(Worksheet $ws, int $r, array $numericCols, string $moneyFmt, string $pctFmt): void
    {
        foreach ($numericCols as $col) {
            $ws->getStyle("{$col}{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            if ($col === 'G') {
                $ws->getStyle("{$col}{$r}")->getNumberFormat()->setFormatCode($pctFmt);
            } else {
                $ws->getStyle("{$col}{$r}")->getNumberFormat()->setFormatCode($moneyFmt);
            }
        }
    }

    // Apply total-row styling (bold, total-blue fill, right-aligned amount, thick top border accent)
    private function styleTotalRow(Worksheet $ws, int $r, string $firstCol, string $lastCol, string $amountCol): void
    {
        $ws->getStyle("{$amountCol}{$r}")->getNumberFormat()->setFormatCode(self::MONEY_FMT);
        $ws->getStyle("{$amountCol}{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $ws->getStyle("{$firstCol}{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $this->font($ws, "{$firstCol}{$r}:{$lastCol}{$r}", bold: true, size: 11);
        $this->fillColor($ws, "{$firstCol}{$r}:{$lastCol}{$r}", self::TOTAL_BLUE);
        $ws->getRowDimension($r)->setRowHeight(20);
    }

    // ─── Utility helpers ──────────────────────────────────────────────────────

    private function sanitisePaymentTerms(mixed $raw): string
    {
        if ($raw === null || $raw === '' || $raw === '—') return '—';

        if (!is_numeric($raw)) {
            $lower = strtolower((string)$raw);
            foreach (['monthly', 'weekly', 'manual', 'fortnightly'] as $k) {
                if (str_contains($lower, $k)) return ucfirst($k);
            }
            return (string)$raw;
        }

        $days = (int)$raw;
        if (in_array($days, self::VALID_PAYMENT_TERMS, true)) return "{$days} days";
        if ($days > 365 || $days < 1) return '—';
        return "{$days} days";
    }

    private function applyHeaderStyle(
        Worksheet $ws,
        string    $range,
        string    $bg     = self::DARK_BLUE,
        int       $size   = 10,
        bool      $center = false
    ): void {
        $ws->getStyle($range)->applyFromArray([
            'font' => [
                'bold'  => true,
                'color' => ['argb' => self::WHITE],
                'size'  => $size,
            ],
            'fill' => [
                'fillType'   => Fill::FILL_SOLID,
                'startColor' => ['argb' => $bg],
            ],
            'alignment' => [
                'horizontal' => $center ? Alignment::HORIZONTAL_CENTER : Alignment::HORIZONTAL_LEFT,
                'vertical'   => Alignment::VERTICAL_CENTER,
                'indent'     => $center ? 0 : 1,
            ],
        ]);
    }

    private function font(
        Worksheet $ws,
        string    $range,
        bool      $bold  = false,
        int       $size  = 0,
        string    $color = ''
    ): void {
        $style = $ws->getStyle($range)->getFont();
        if ($bold)  $style->setBold(true);
        if ($size)  $style->setSize($size);
        if ($color) $style->getColor()->setARGB($color);
    }

    private function fillColor(Worksheet $ws, string $range, string $argb): void
    {
        $ws->getStyle($range)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB($argb);
    }

    private function boxBorder(Worksheet $ws, string $range): void
    {
        $ws->getStyle($range)->getBorders()->applyFromArray([
            'outline' => [
                'borderStyle' => Border::BORDER_MEDIUM,
                'color'       => ['argb' => self::MID_BLUE],
            ],
        ]);
    }

    private function innerBorders(Worksheet $ws, string $range): void
    {
        $ws->getStyle($range)->getBorders()->applyFromArray([
            'inside' => [
                'borderStyle' => Border::BORDER_THIN,
                'color'       => ['argb' => self::BORDER_COL],
            ],
        ]);
    }
}
