<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Organization;
use App\Models\PaymentApplication;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Batch 4: Payment Applications.
 *
 * Product decision (final): Client has full commercial authority within
 * its own organisation — create, edit, submit, certify, mark paid. This
 * controller was already fully backend-safe: every action already checked
 * org membership via authorizeProject(), and workflow-state guards
 * (only-draft-editable, only-submitted-certifiable, only-certified-payable,
 * only-draft-or-cancelled-deletable) were already correctly enforced,
 * equally for every role. No backend changes were needed here — only the
 * frontend blanket canWrite gate was replaced.
 */
class Batch4PaymentApplicationsTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrgAndUser(string $label): array
    {
        static $n = 0;
        $n++;

        $org = Organization::create(['name' => "{$label} Org {$n}", 'slug' => "org-{$label}-{$n}"]);
        $user = User::factory()->create(['organization_id' => $org->id]);

        return compact('org', 'user');
    }

    private function makeProject(Organization $org, User $user): Project
    {
        return Project::create([
            'organization_id' => $org->id,
            'created_by'      => $user->id,
            'name'            => "Project for {$org->name}",
            'status'          => 'active',
        ]);
    }

    private function makeContract(Project $project, User $user): Contract
    {
        return Contract::create([
            'project_id'      => $project->id,
            'organization_id' => $project->organization_id,
            'created_by'      => $user->id,
            'title'           => 'Main Contract',
            'type'            => 'main_contract',
            'status'          => 'active',
            'retention_percentage' => 5,
        ]);
    }

    private function makeApplication(Project $project, Contract $contract, User $user, array $overrides = []): PaymentApplication
    {
        static $n = 0;
        $n++;

        return PaymentApplication::create(array_merge([
            'project_id'          => $project->id,
            'contract_id'         => $contract->id,
            'organization_id'     => $project->organization_id,
            'created_by'          => $user->id,
            'application_number'  => $n,
            'status'              => 'draft',
            'application_date'    => now()->toDateString(),
            'gross_valuation'     => 10000,
            'amount_due'          => 9500,
        ], $overrides));
    }

    // ── Positive: Client has full commercial authority in their own org ──

    public function test_client_can_create_submit_certify_and_mark_paid_a_payment_application(): void
    {
        $a = $this->makeOrgAndUser('a');
        $project = $this->makeProject($a['org'], $a['user']);
        $contract = $this->makeContract($project, $a['user']);
        Sanctum::actingAs($a['user']);

        $store = $this->postJson("/api/contracts/{$contract->id}/payment-applications", [
            'application_date' => now()->toDateString(), 'gross_valuation' => 10000,
        ]);
        $store->assertStatus(201);
        $id = $store->json('id');

        $this->putJson("/api/payment-applications/{$id}", ['notes' => 'Updated notes'])->assertStatus(200);
        $this->postJson("/api/payment-applications/{$id}/submit")->assertStatus(200);

        $certify = $this->postJson("/api/payment-applications/{$id}/certify", ['certified_amount' => 9000]);
        $certify->assertStatus(200);
        $this->assertDatabaseHas('payment_applications', ['id' => $id, 'status' => 'certified']);

        $markPaid = $this->postJson("/api/payment-applications/{$id}/mark-paid", ['paid_amount' => 9000, 'payment_reference' => 'BACS-001']);
        $markPaid->assertStatus(200);
        $this->assertDatabaseHas('payment_applications', ['id' => $id, 'status' => 'paid']);
    }

    /**
     * Cleanup regression: markPaid() previously referenced
     * $validated['payment_reference'] unconditionally in its activity-log
     * message even though the field is nullable — an undefined-array-key
     * error (500) whenever the field was omitted entirely. Fixed with a
     * null-coalescing read.
     */
    public function test_marking_an_application_as_paid_without_a_payment_reference_does_not_error(): void
    {
        $a = $this->makeOrgAndUser('a');
        $project = $this->makeProject($a['org'], $a['user']);
        $contract = $this->makeContract($project, $a['user']);
        $certified = $this->makeApplication($project, $contract, $a['user'], ['status' => 'certified']);
        Sanctum::actingAs($a['user']);

        $response = $this->postJson("/api/payment-applications/{$certified->id}/mark-paid", ['paid_amount' => 9000]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('payment_applications', ['id' => $certified->id, 'status' => 'paid']);
    }

    public function test_client_can_delete_a_draft_application_but_not_a_certified_one(): void
    {
        $a = $this->makeOrgAndUser('a');
        $project = $this->makeProject($a['org'], $a['user']);
        $contract = $this->makeContract($project, $a['user']);
        $draft = $this->makeApplication($project, $contract, $a['user'], ['status' => 'draft']);
        $certified = $this->makeApplication($project, $contract, $a['user'], ['status' => 'certified']);
        Sanctum::actingAs($a['user']);

        $blocked = $this->deleteJson("/api/payment-applications/{$certified->id}");
        $blocked->assertStatus(422);
        $this->assertDatabaseHas('payment_applications', ['id' => $certified->id]);

        $allowed = $this->deleteJson("/api/payment-applications/{$draft->id}");
        $allowed->assertStatus(200);
        $this->assertSoftDeleted('payment_applications', ['id' => $draft->id]);
    }

    public function test_client_can_issue_a_payment_notice_and_a_pay_less_notice_on_their_own_application(): void
    {
        $a = $this->makeOrgAndUser('a');
        $project = $this->makeProject($a['org'], $a['user']);
        $contract = $this->makeContract($project, $a['user']);
        $app = $this->makeApplication($project, $contract, $a['user'], ['status' => 'submitted']);
        Sanctum::actingAs($a['user']);

        $pn = $this->postJson("/api/payment-applications/{$app->id}/payment-notice", [
            'notice_date' => now()->toDateString(), 'notified_sum' => 9500,
        ]);
        $pn->assertStatus(201);
        $this->assertDatabaseHas('payment_notices', ['payment_application_id' => $app->id, 'status' => 'issued']);

        $pln = $this->postJson("/api/payment-applications/{$app->id}/pay-less-notice", [
            'notice_date' => now()->toDateString(), 'original_amount_due' => 9500,
            'total_deductions' => 500, 'deduction_reason' => 'Defective works',
        ]);
        $pln->assertStatus(201);
        $this->assertDatabaseHas('pay_less_notices', ['payment_application_id' => $app->id, 'status' => 'issued']);
    }

    // ── Workflow-state protection ─────────────────────────────────────────

    public function test_only_draft_applications_can_be_submitted_or_edited(): void
    {
        $a = $this->makeOrgAndUser('a');
        $project = $this->makeProject($a['org'], $a['user']);
        $contract = $this->makeContract($project, $a['user']);
        $submitted = $this->makeApplication($project, $contract, $a['user'], ['status' => 'submitted']);
        Sanctum::actingAs($a['user']);

        $this->putJson("/api/payment-applications/{$submitted->id}", ['notes' => 'x'])->assertStatus(422);
        $this->postJson("/api/payment-applications/{$submitted->id}/submit")->assertStatus(422);
    }

    public function test_only_submitted_applications_can_be_certified_and_only_certified_can_be_paid(): void
    {
        $a = $this->makeOrgAndUser('a');
        $project = $this->makeProject($a['org'], $a['user']);
        $contract = $this->makeContract($project, $a['user']);
        $draft = $this->makeApplication($project, $contract, $a['user'], ['status' => 'draft']);
        Sanctum::actingAs($a['user']);

        $this->postJson("/api/payment-applications/{$draft->id}/certify", ['certified_amount' => 100])->assertStatus(422);
        $this->postJson("/api/payment-applications/{$draft->id}/mark-paid", ['paid_amount' => 100])->assertStatus(422);
    }

    // ── Negative: cross-tenant ────────────────────────────────────────────

    public function test_client_cannot_view_edit_certify_or_delete_another_organisations_application(): void
    {
        $a = $this->makeOrgAndUser('a');
        $b = $this->makeOrgAndUser('b');
        $projectB = $this->makeProject($b['org'], $b['user']);
        $contractB = $this->makeContract($projectB, $b['user']);
        $appB = $this->makeApplication($projectB, $contractB, $b['user'], ['status' => 'submitted']);

        Sanctum::actingAs($a['user']);

        $this->getJson("/api/payment-applications/{$appB->id}")->assertStatus(403);
        $this->putJson("/api/payment-applications/{$appB->id}", ['notes' => 'Hijacked'])->assertStatus(403);
        $this->postJson("/api/payment-applications/{$appB->id}/certify", ['certified_amount' => 1])->assertStatus(403);
        $this->postJson("/api/payment-applications/{$appB->id}/payment-notice", ['notice_date' => now()->toDateString(), 'notified_sum' => 1])->assertStatus(403);
        $this->deleteJson("/api/payment-applications/{$appB->id}")->assertStatus(403);
    }

    public function test_client_cannot_create_a_payment_application_under_another_organisations_contract(): void
    {
        $a = $this->makeOrgAndUser('a');
        $b = $this->makeOrgAndUser('b');
        $projectB = $this->makeProject($b['org'], $b['user']);
        $contractB = $this->makeContract($projectB, $b['user']);

        Sanctum::actingAs($a['user']);

        $response = $this->postJson("/api/contracts/{$contractB->id}/payment-applications", [
            'application_date' => now()->toDateString(), 'gross_valuation' => 500,
        ]);
        $response->assertStatus(403);
        $this->assertDatabaseMissing('payment_applications', ['contract_id' => $contractB->id]);
    }
}
