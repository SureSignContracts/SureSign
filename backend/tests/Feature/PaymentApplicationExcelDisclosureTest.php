<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\PaymentApplication;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Covers the M7 fix in PaymentApplicationController::generateExcel(): the
 * catch block used to log AND return "Excel generation failed: " .
 * $e->getMessage() at 500. This forces a REAL failure (not a mock) by
 * giving the payment application a `breakdown` value that decodes to a
 * plain string rather than an array -- ExcelGenerationService::
 * buildMeasuredWorks() then does `foreach ($rows as $i => $item)` over that
 * string, which throws a genuine \TypeError.
 */
class PaymentApplicationExcelDisclosureTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_real_generation_failure_returns_a_generic_message_and_is_logged(): void
    {
        if (!class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
            $this->markTestSkipped('phpoffice/phpspreadsheet is not installed in this environment.');
        }

        Log::spy();

        $org = Organization::create(['name' => 'Org', 'slug' => 'org-' . uniqid()]);
        $user = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $project = Project::create(['organization_id' => $org->id, 'created_by' => $user->id, 'name' => 'P']);

        $paymentApplication = PaymentApplication::create([
            'project_id' => $project->id, 'organization_id' => $org->id, 'created_by' => $user->id,
            'application_number' => 1, 'gross_valuation' => 1000, 'amount_due' => 1000,
            'status' => 'draft',
            // Deliberately malformed -- breakdown is cast 'array' but nothing
            // prevents a plain string being persisted through it, and
            // buildMeasuredWorks() assumes ['measured_works'] resolves to an
            // iterable array.
            'breakdown' => 'not-an-array',
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/payment-applications/{$paymentApplication->id}/generate-excel");

        $response->assertStatus(500)->assertJson(['message' => 'The Excel workbook could not be generated.']);
        $this->assertStringNotContainsString('TypeError', $response->getContent());
        $this->assertStringNotContainsString('ExcelGenerationService', $response->getContent());
        $this->assertStringNotContainsString('.php', $response->getContent());

        Log::shouldHaveReceived('error')
            ->withArgs(fn ($message, $context) =>
                $message === 'Payment application Excel generation failed'
                && $context['user_id'] === $user->id
                && $context['payment_application_id'] === $paymentApplication->id
                && isset($context['exception'])
            )
            ->once();
    }
}
