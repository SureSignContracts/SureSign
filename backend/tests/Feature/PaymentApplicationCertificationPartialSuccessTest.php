<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Organization;
use App\Models\PaymentApplication;
use App\Models\Project;
use App\Models\SuresignSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Error Messaging & Recovery UX, Batch 3 — confirmed Phase A audit finding:
 * PaymentApplicationController::certify() always certifies the application
 * (correct — certification itself must never be blocked by a PDF failure)
 * but previously gave the caller no way to know whether the certificate PDF
 * actually generated. The frontend unconditionally told the customer "PDF
 * saved to documents" even when generation had failed. Fixed by surfacing
 * `certificate_generated` on the response.
 *
 * Forces a REAL generation failure (not a mock) via the same
 * `feature_document_generation` kill switch DocumentGenerationService
 * itself checks first (`abort_unless(...)`), rather than relying on
 * malformed view data or a fragile blade-template assumption.
 */
class PaymentApplicationCertificationPartialSuccessTest extends TestCase
{
    use RefreshDatabase;

    private function makeSubmittedApplication(): array
    {
        $org = Organization::create(['name' => 'Org', 'slug' => 'org-' . uniqid()]);
        $user = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $project = Project::create(['organization_id' => $org->id, 'created_by' => $user->id, 'name' => 'P']);
        $contract = Contract::create([
            'project_id' => $project->id, 'organization_id' => $org->id, 'created_by' => $user->id,
            'title' => 'Main Contract', 'type' => 'main_contract', 'status' => 'active',
        ]);

        $paymentApplication = PaymentApplication::create([
            'project_id' => $project->id, 'organization_id' => $org->id, 'created_by' => $user->id,
            'contract_id' => $contract->id,
            'application_number' => 1, 'application_date' => now()->toDateString(),
            'gross_valuation' => 1000, 'amount_due' => 1000,
            'status' => 'submitted',
        ]);

        return [$user, $paymentApplication];
    }

    public function test_certification_succeeds_and_reports_pdf_failure_when_document_generation_is_disabled(): void
    {
        [$user, $paymentApplication] = $this->makeSubmittedApplication();
        SuresignSetting::instance()->update(['feature_document_generation' => false]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/payment-applications/{$paymentApplication->id}/certify", [
            'certified_amount' => 900,
        ]);

        // The certification itself must never fail because the PDF failed.
        $response->assertStatus(200)->assertJson(['certificate_generated' => false]);
        $this->assertDatabaseHas('payment_applications', [
            'id' => $paymentApplication->id,
            'status' => 'certified',
            'certified_amount' => 900,
        ]);
    }

    public function test_certification_reports_pdf_success_when_document_generation_is_enabled(): void
    {
        [$user, $paymentApplication] = $this->makeSubmittedApplication();
        SuresignSetting::instance()->update(['feature_document_generation' => true]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/payment-applications/{$paymentApplication->id}/certify", [
            'certified_amount' => 900,
        ]);

        $response->assertStatus(200)->assertJson(['certificate_generated' => true]);
    }
}
