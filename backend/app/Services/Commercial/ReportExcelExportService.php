<?php

namespace App\Services\Commercial;

use App\Models\BrandingSetting;
use App\Services\BrandingService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Excel export for the Commercial Summary Report (Reports module).
 *
 * A deliberately separate, lightweight service from ExcelGenerationService
 * — that class generates a single Payment Application's multi-sheet
 * workbook and always saves the result as a Document tied to one project,
 * neither of which applies to an organisation-wide report spanning many
 * projects with no single owning project. This reuses the same
 * PhpSpreadsheet library and the same BrandingService/styling conventions
 * (colour palette, A4 print setup, letterhead header) rather than
 * introducing a new spreadsheet engine.
 */
class ReportExcelExportService
{
    private const DARK_BLUE  = 'FF1F3864';
    private const MID_BLUE   = 'FF2E75B6';
    private const LIGHT_BLUE = 'FFDCE6F1';
    private const TOTAL_BLUE = 'FFBDD7EE';
    private const WHITE      = 'FFFFFFFF';
    private const MUTED_TEXT = 'FF595959';
    private const BORDER_COL = 'FFB8CCE4';

    public function downloadCommercialSummary(array $report, int $organizationId): BinaryFileResponse
    {
        $branding = BrandingService::forOrganization($organizationId);

        $spreadsheet = new Spreadsheet();
        $spreadsheet->getDefaultStyle()->getFont()->setName('Calibri')->setSize(10);
        $ws = $spreadsheet->getActiveSheet();
        $ws->setTitle('Commercial Summary Report');

        $setup = $ws->getPageSetup();
        $setup->setPaperSize(PageSetup::PAPERSIZE_A4);
        $setup->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
        $setup->setFitToPage(true);
        $setup->setFitToWidth(1);
        $setup->setFitToHeight(0);

        foreach (['A' => 2, 'B' => 30, 'C' => 16, 'D' => 16, 'E' => 16, 'F' => 16, 'G' => 16, 'H' => 16, 'I' => 16, 'J' => 14] as $col => $w) {
            $ws->getColumnDimension($col)->setWidth($w);
        }

        $r = $this->insertHeader($ws, $branding);
        $r = $this->writeMetadata($ws, $report['metadata'], $r);
        $r = $this->writeCurrencySections($ws, $report['currency_sections'], $r);
        $this->writeProjectTable($ws, $report['projects'], $r);

        $fileName = 'commercial-summary-report-' . $report['metadata']['period']['key'] . '-' . now()->format('Ymd-His') . '.xlsx';
        $tmp = tempnam(sys_get_temp_dir(), 'report_xls_');
        (new Xlsx($spreadsheet))->save($tmp);

        return response()->download($tmp, $fileName)->deleteFileAfterSend(true);
    }

    private function insertHeader(Worksheet $ws, ?BrandingSetting $branding): int
    {
        $headerPath = BrandingService::headerPath($branding);
        $logoPath   = BrandingService::logoPath($branding);
        $usePath    = $headerPath ?? $logoPath;

        if ($usePath) {
            try {
                $drawing = new Drawing();
                $drawing->setName('Company Header');
                $drawing->setPath($usePath);
                $drawing->setWidth(900);
                $drawing->setResizeProportional(true);
                $drawing->setCoordinates('A1');
                $drawing->setWorksheet($ws);

                $info = @getimagesize($usePath);
                $scaledHeight = ($info && $info[0] > 0) ? (int) round(900 * $info[1] / $info[0]) : 80;
                $rowsNeeded = max(3, (int) ceil(($scaledHeight + 2) / 15));
                for ($i = 1; $i <= $rowsNeeded; $i++) {
                    $ws->getRowDimension($i)->setRowHeight(15);
                }

                return $rowsNeeded + 2;
            } catch (\Throwable) {
                // fall through to text-only header
            }
        }

        $name = $branding?->company_display_name ?? $branding?->company_name ?? '';
        if ($name) {
            $ws->setCellValue('A1', $name);
            $ws->mergeCells('A1:J1');
            $this->headerStyle($ws, 'A1:J1', self::DARK_BLUE, 13);
            $ws->getRowDimension(1)->setRowHeight(26);
            return 3;
        }

        return 2;
    }

    private function writeMetadata(Worksheet $ws, array $metadata, int $r): int
    {
        $ws->setCellValue("A{$r}", 'COMMERCIAL SUMMARY REPORT');
        $ws->mergeCells("A{$r}:J{$r}");
        $this->headerStyle($ws, "A{$r}:J{$r}", self::DARK_BLUE, 14);
        $ws->getRowDimension($r)->setRowHeight(24);
        $r += 2;

        $fields = [
            'Organisation'        => $metadata['organisation'],
            'Reporting Period'    => "{$metadata['period']['label']} ({$metadata['period']['from']} to {$metadata['period']['to']})",
            'Generated Date'      => $metadata['generated_date'],
            'Generated Time'      => $metadata['generated_time'],
            'Effective Timezone'  => $metadata['effective_timezone'],
            'Generated By'        => $metadata['generated_by'],
            'Currency Context'    => $metadata['currency_context'],
            'Report Type'         => $metadata['report_type'],
        ];

        foreach ($fields as $label => $value) {
            $ws->setCellValue("A{$r}", $label . ':');
            $ws->setCellValue("B{$r}", $value);
            $this->font($ws, "A{$r}", bold: true, color: self::MUTED_TEXT);
            $ws->getRowDimension($r)->setRowHeight(14);
            $r++;
        }

        return $r + 1;
    }

    private function writeCurrencySections(Worksheet $ws, array $sections, int $r): int
    {
        foreach ($sections as $section) {
            $currency = $section['currency'];

            $ws->setCellValue("A{$r}", "FINANCIAL POSITION ({$currency})");
            $ws->mergeCells("A{$r}:J{$r}");
            $this->headerStyle($ws, "A{$r}:J{$r}", self::MID_BLUE, 11);
            $ws->getRowDimension($r)->setRowHeight(18);
            $r++;

            $lines = [
                ['Certified to Date', $section['financial_position']['certified_total']],
                ['Paid to Date', $section['financial_position']['paid_total']],
                ['Outstanding', $section['financial_position']['outstanding_total']],
                ['Retention Held', $section['retention_position']['retention_total']],
                ['Approved Variation Value', $section['variation_position']['approved_variation_value']],
                ['Pending Variation Value', $section['variation_position']['pending_variation_value']],
            ];

            foreach ($lines as [$label, $amount]) {
                $ws->setCellValue("A{$r}", $label);
                $ws->setCellValue("B{$r}", $amount);
                $ws->getStyle("B{$r}")->getNumberFormat()->setFormatCode('#,##0.00');
                $ws->getRowDimension($r)->setRowHeight(14);
                $r++;
            }

            $ws->setCellValue("A{$r}", 'COMMERCIAL PIPELINE');
            $this->font($ws, "A{$r}", bold: true);
            $ws->getRowDimension($r)->setRowHeight(14);
            $r++;

            $pipeline = $section['commercial_pipeline'];
            foreach ([
                'Awaiting Submission'    => $pipeline['awaiting_submission'],
                'Awaiting Certification' => $pipeline['awaiting_certification'],
                'Certified but Unpaid'   => $pipeline['certified_unpaid'],
            ] as $label => $bucket) {
                $ws->setCellValue("A{$r}", $label);
                $ws->setCellValue("B{$r}", $bucket['count']);
                $ws->setCellValue("C{$r}", $bucket['value']);
                $ws->getStyle("C{$r}")->getNumberFormat()->setFormatCode('#,##0.00');
                $ws->getRowDimension($r)->setRowHeight(14);
                $r++;
            }

            $r++;
            $ws->setCellValue("A{$r}", $section['narrative']);
            $ws->mergeCells("A{$r}:J{$r}");
            $ws->getStyle("A{$r}")->getAlignment()->setWrapText(true);
            $this->font($ws, "A{$r}", color: self::MUTED_TEXT);
            $ws->getRowDimension($r)->setRowHeight(30);
            $r += 2;
        }

        return $r;
    }

    private function writeProjectTable(Worksheet $ws, array $projects, int $r): void
    {
        $ws->setCellValue("A{$r}", 'PER PROJECT SUMMARY');
        $ws->mergeCells("A{$r}:J{$r}");
        $this->headerStyle($ws, "A{$r}:J{$r}", self::DARK_BLUE, 11);
        $ws->getRowDimension($r)->setRowHeight(18);
        $r++;

        $headers = ['Project', 'Currency', 'Contract Value', 'Certified', 'Paid', 'Outstanding', 'Retention', 'Approved Var.', 'Pending Var.', 'Status'];
        $cols    = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J'];
        foreach ($cols as $i => $col) {
            $ws->setCellValue("{$col}{$r}", $headers[$i]);
        }
        $this->headerStyle($ws, "A{$r}:J{$r}", self::MID_BLUE, 10, false);
        $ws->getRowDimension($r)->setRowHeight(16);
        $r++;

        $moneyCols = ['C', 'D', 'E', 'F', 'G', 'H', 'I'];

        foreach ($projects as $i => $row) {
            $bg = ($i % 2 === 0) ? self::WHITE : self::LIGHT_BLUE;
            $ws->setCellValue("A{$r}", $row['project_name']);
            $ws->setCellValue("B{$r}", $row['currency']);
            $ws->setCellValue("C{$r}", $row['contract_value']);
            $ws->setCellValue("D{$r}", $row['certified']);
            $ws->setCellValue("E{$r}", $row['paid']);
            $ws->setCellValue("F{$r}", $row['outstanding']);
            $ws->setCellValue("G{$r}", $row['retention']);
            $ws->setCellValue("H{$r}", $row['approved_variation_value']);
            $ws->setCellValue("I{$r}", $row['pending_variation_value']);
            $ws->setCellValue("J{$r}", $row['status']);

            foreach ($moneyCols as $col) {
                $ws->getStyle("{$col}{$r}")->getNumberFormat()->setFormatCode('#,##0.00');
            }
            $this->fillColor($ws, "A{$r}:J{$r}", $bg);
            $ws->getRowDimension($r)->setRowHeight(15);
            $r++;
        }

        if (empty($projects)) {
            $ws->setCellValue("A{$r}", 'No projects to report on.');
            $ws->mergeCells("A{$r}:J{$r}");
            $this->font($ws, "A{$r}", color: self::MUTED_TEXT);
        }
    }

    private function headerStyle(Worksheet $ws, string $range, string $bg, int $size = 10, bool $center = false): void
    {
        $ws->getStyle($range)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => self::WHITE], 'size' => $size],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $bg]],
            'alignment' => ['horizontal' => $center ? Alignment::HORIZONTAL_CENTER : Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER, 'indent' => $center ? 0 : 1],
        ]);
    }

    private function font(Worksheet $ws, string $range, bool $bold = false, string $color = ''): void
    {
        $style = $ws->getStyle($range)->getFont();
        if ($bold) $style->setBold(true);
        if ($color) $style->getColor()->setARGB($color);
    }

    private function fillColor(Worksheet $ws, string $range, string $argb): void
    {
        $ws->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($argb);
    }
}
