<?php

namespace Tests\Feature;

use App\Models\PaymentApplication;
use App\Models\PaymentApplicationVariation;
use App\Models\Project;
use App\Services\ExcelGenerationService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use ReflectionClass;
use Tests\TestCase;

/**
 * Focused coverage for the Variations sheet of the payment-application workbook.
 *
 * NOTE: This test requires phpoffice/phpspreadsheet (declared in composer.json).
 * It is skipped automatically if the package is not installed — e.g. in a sandbox
 * where `composer install` has not completed. Run it in CI / the real environment
 * where the dependency is present.
 *
 * The test exercises ExcelGenerationService::buildVariations() in isolation via
 * reflection so it needs no database, storage, or branding assets: the payment
 * application and its relations are constructed in memory.
 */
class ExcelGenerationVariationsTest extends TestCase
{
    private function skipIfSpreadsheetMissing(): void
    {
        if (! class_exists(Spreadsheet::class)) {
            $this->markTestSkipped(
                'phpoffice/phpspreadsheet is not installed in this environment. '
                . 'Run this test where composer install has completed (CI / real environment).'
            );
        }
    }

    /**
     * Build an in-memory payment application with one manual variation item and
     * two linked approved variation snapshots, then build the Variations sheet.
     */
    private function buildVariationsSheet(): array
    {
        $manualValue = 99000.00;
        $linkedA     = 4250.00;
        $linkedB     = 6939.62;
        $linkedTotal = $linkedA + $linkedB; // 11,189.62

        $pa = new PaymentApplication();
        $pa->forceFill([
            'application_number'      => '12',
            'reference'               => 'APP-12',
            'use_breakdown'           => true,
            'measured_works_total'    => 184977.00,
            'variations_total'        => $manualValue,
            'linked_variations_total' => $linkedTotal,
            'materials_on_site_total' => 6123.51,
            // Gross = measured + variations(manual+linked) + materials.
            'gross_valuation'         => 184977.00 + $manualValue + $linkedTotal + 6123.51,
            'breakdown'               => [
                'variations' => [
                    [
                        'ref'             => 'MV-1',
                        'instruction_ref' => 'SI-09',
                        'description'     => 'Manual extra works',
                        'date_issued'     => '2026-03-01',
                        'variation_value' => $manualValue,
                        'pct_complete'    => 100,
                        'status'          => 'agreed',
                        'notes'           => 'manual entry',
                    ],
                ],
            ],
        ]);

        // Pre-set relations so nothing lazy-loads from the database.
        $project = new Project();
        $project->forceFill(['name' => 'South Molton Street']);
        $pa->setRelation('project', $project);
        $pa->setRelation('contract', null);
        $pa->setRelation('tradePackage', null);

        $mkLinked = function (array $attrs) {
            $lv = new PaymentApplicationVariation();
            $lv->forceFill($attrs);
            return $lv;
        };
        $pa->setRelation('linkedVariations', collect([
            $mkLinked([
                'variation_number_at_inclusion' => 'VAR-001',
                'title_at_inclusion'            => 'Additional flashing detail',
                'status_at_inclusion'           => 'approved',
                'amount_at_inclusion'           => $linkedA,
            ]),
            $mkLinked([
                'variation_number_at_inclusion' => 'VAR-002',
                'title_at_inclusion'            => 'Revised parapet upstand',
                'status_at_inclusion'           => 'approved',
                'amount_at_inclusion'           => $linkedB,
            ]),
        ]));

        // Invoke the private builder with branding = null (BrandingService path
        // helpers are null-safe, so no asset is required).
        $service = new ExcelGenerationService();
        $ref     = new ReflectionClass($service);

        $brandingProp = $ref->getProperty('branding');
        $brandingProp->setAccessible(true);
        $brandingProp->setValue($service, null);

        $spreadsheet = new Spreadsheet();
        $spreadsheet->removeSheetByIndex(0);

        $method = $ref->getMethod('buildVariations');
        $method->setAccessible(true);
        $totalRef = $method->invoke($service, $spreadsheet, $pa);

        return [
            'spreadsheet' => $spreadsheet,
            'totalRef'    => $totalRef,
            'manualValue' => $manualValue,
            'linkedA'     => $linkedA,
            'linkedB'     => $linkedB,
            'linkedTotal' => $linkedTotal,
        ];
    }

    public function test_variations_sheet_includes_manual_and_linked_rows(): void
    {
        $this->skipIfSpreadsheetMissing();

        $built = $this->buildVariationsSheet();
        $ws    = $built['spreadsheet']->getSheetByName('Variations');
        $this->assertNotNull($ws, 'Variations sheet should exist.');

        $manualDescriptions = [];
        $linkedRows         = [];

        foreach ($ws->getRowIterator() as $row) {
            $r    = $row->getRowIndex();
            $desc = (string) $ws->getCell("C{$r}")->getValue();
            $note = (string) $ws->getCell("I{$r}")->getValue();

            if ($note === 'Linked Approved Variation') {
                $linkedRows[] = [
                    'number' => (string) $ws->getCell("A{$r}")->getValue(),
                    'title'  => $desc,
                    'value'  => (float) $ws->getCell("E{$r}")->getValue(),
                    'status' => (string) $ws->getCell("H{$r}")->getValue(),
                ];
            } elseif ($desc === 'Manual extra works') {
                $manualDescriptions[] = (float) $ws->getCell("E{$r}")->getValue();
            }
        }

        // Manual row preserved.
        $this->assertCount(1, $manualDescriptions, 'Manual variation row should be present.');
        $this->assertEqualsWithDelta($built['manualValue'], $manualDescriptions[0], 0.001);

        // Both linked snapshot rows present and flagged.
        $this->assertCount(2, $linkedRows, 'Both linked approved variations should appear, flagged.');

        $byNumber = collect($linkedRows)->keyBy('number');
        $this->assertEqualsWithDelta($built['linkedA'], $byNumber['VAR-001']['value'], 0.001);
        $this->assertEqualsWithDelta($built['linkedB'], $byNumber['VAR-002']['value'], 0.001);
        $this->assertSame('Additional flashing detail', $byNumber['VAR-001']['title']);
        $this->assertSame('approved', $byNumber['VAR-001']['status']);
    }

    public function test_total_variations_includes_manual_plus_linked_without_double_counting(): void
    {
        $this->skipIfSpreadsheetMissing();

        $built = $this->buildVariationsSheet();
        $ws    = $built['spreadsheet']->getSheetByName('Variations');

        $totalCell = $built['totalRef']['col'] . $built['totalRef']['row'];
        $total     = (float) $ws->getCell($totalCell)->getCalculatedValue();

        $expected = $built['manualValue'] + $built['linkedA'] + $built['linkedB']; // 110,189.62

        // Total reflects manual + linked exactly once each.
        $this->assertEqualsWithDelta($expected, $total, 0.01, 'Total variations must equal manual + linked.');

        // The linked portion of the total equals the stored linked_variations_total,
        // confirming linked snapshots are counted once (not doubled).
        $linkedPortion = $total - $built['manualValue'];
        $this->assertEqualsWithDelta($built['linkedTotal'], $linkedPortion, 0.01);

        // Guard against a regression where linked variations were summed twice.
        $doubleCounted = $built['manualValue'] + 2 * $built['linkedTotal'];
        $this->assertNotEqualsWithDelta($doubleCounted, $total, 0.01, 'Linked variations must not be double-counted.');
    }
}
