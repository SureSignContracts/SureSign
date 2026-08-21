<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\FeatureAvailability;
use App\Models\Organization;
use App\Models\PaymentApplication;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Commercial Correctness Hardening (2026-08-21).
 *
 * Focused test coverage for the actually-wired Pay Less Notice creation
 * path — PaymentApplicationController::createPayLessNotice(), reached by
 * the frontend via POST /payment-applications/{id}/pay-less-notice (see
 * frontend/src/app/app/projects/[id]/commercial/page.tsx). This is
 * deliberately NOT PayLessNoticeController::store() — that controller's
 * store()/update()/destroy() never set the required, non-nullable
 * pay_less_notices.payment_application_id column, so calling them would
 * fail with a database integrity error. The frontend never calls that
 * path; it is flagged for separate future triage and is out of scope for
 * this phase.
 *
 * Also locks in the resolved PaymentApplication status architecture: the
 * parent's `status` column is application-level only (draft, submitted,
 * certified, paid, cancelled, disputed) and is never set to
 * 'payment_notice_issued'/'pay_less_notice_issued' — notice-issued state
 * lives exclusively on the child PaymentNotice/PayLessNotice records (and
 * is derived client-side for display). See
 * PaymentApplicationController::cancel()'s own updated docblock.
 *
 * Existing PaymentDateService date/deadline logic is exercised as-is and
 * is not modified or recalculated by these tests.
 */
class PayLessNoticeCommercialHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrg(string $name = 'Org'): Organization
    {
        return Organization::create(['name' => $name, 'slug' => str()->slug($name) . '-' . str()->random(6), 'timezone' => 'Europe/London']);
    }

    private function makeUser(Organization $org, ?string $role = null): User
    {
        $user = User::factory()->create(['organization_id' => $org->id]);
        if ($role) {
            $user->assignRole(Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']));
        }
        return $user;
    }

    private function makeProject(Organization $org, User $user): Project
    {
        return Project::create(['organization_id' => $org->id, 'created_by' => $user->id, 'name' => 'Riverside Apartments']);
    }

    private function makeContract(Project $project, User $user): Contract
    {
        return Contract::create([
            'project_id' => $project->id, 'organization_id' => $project->organization_id,
            'created_by' => $user->id, 'title' => 'Main Contract', 'type' => 'main_contract',
            'status' => 'active', 'retention_percentage' => 5,
        ]);
    }

    private function makeApplication(Project $project, Contract $contract, User $user, array $overrides = []): PaymentApplication
    {
        static $n = 0;
        $n++;

        return PaymentApplication::create(array_merge([
            'project_id' => $project->id, 'contract_id' => $contract->id,
            'organization_id' => $project->organization_id, 'created_by' => $user->id,
            'application_number' => $n, 'status' => 'submitted',
            'application_date' => now()->toDateString(),
            'gross_valuation' => 10000, 'amount_due' => 9500,
        ], $overrides));
    }

    // ── Valid creation ───────────────────────────────────────────────────

    public function test_pay_less_notice_can_be_created_on_a_submitted_application(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org);
        $project = $this->makeProject($org, $user);
        $contract = $this->makeContract($project, $user);
        $app = $this->makeApplication($project, $contract, $user);
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/payment-applications/{$app->id}/pay-less-notice", [
            'notice_date' => now()->toDateString(),
            'original_amount_due' => 9500,
            'total_deductions' => 500,
            'deduction_reason' => 'Defective works',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('pay_less_notices', [
            'payment_application_id' => $app->id,
            'project_id' => $project->id,
            'organization_id' => $org->id,
            'status' => 'issued',
            'revised_amount_payable' => '9000.00',
        ]);
    }

    public function test_pay_less_notice_can_be_created_on_a_certified_application(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org);
        $project = $this->makeProject($org, $user);
        $contract = $this->makeContract($project, $user);
        $app = $this->makeApplication($project, $contract, $user, ['status' => 'certified']);
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/payment-applications/{$app->id}/pay-less-notice", [
            'notice_date' => now()->toDateString(),
            'original_amount_due' => 9500,
            'total_deductions' => 1000,
            'deduction_reason' => 'Incomplete works',
        ]);

        $response->assertStatus(201);
    }

    public function test_pay_less_notice_cannot_be_created_on_a_draft_or_paid_application(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org);
        $project = $this->makeProject($org, $user);
        $contract = $this->makeContract($project, $user);
        $draft = $this->makeApplication($project, $contract, $user, ['status' => 'draft']);
        $paid = $this->makeApplication($project, $contract, $user, ['status' => 'paid']);
        Sanctum::actingAs($user);

        $payload = [
            'notice_date' => now()->toDateString(), 'original_amount_due' => 100,
            'total_deductions' => 10, 'deduction_reason' => 'x',
        ];

        $this->postJson("/api/payment-applications/{$draft->id}/pay-less-notice", $payload)->assertStatus(422);
        $this->postJson("/api/payment-applications/{$paid->id}/pay-less-notice", $payload)->assertStatus(422);
        $this->assertDatabaseCount('pay_less_notices', 0);
    }

    // ── Deduction arithmetic never goes negative (existing rule, not recalculated) ──

    public function test_revised_amount_payable_never_goes_below_zero_when_deductions_exceed_amount_due(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org);
        $project = $this->makeProject($org, $user);
        $contract = $this->makeContract($project, $user);
        $app = $this->makeApplication($project, $contract, $user);
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/payment-applications/{$app->id}/pay-less-notice", [
            'notice_date' => now()->toDateString(),
            'original_amount_due' => 500,
            'total_deductions' => 900,
            'deduction_reason' => 'Full withholding',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('pay_less_notices', [
            'payment_application_id' => $app->id,
            'revised_amount_payable' => '0.00',
        ]);
    }

    // ── Pay Less Notice deadline / lateness (existing PaymentDateService-populated field) ──

    public function test_pay_less_notice_is_flagged_late_when_issued_after_the_deadline(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org);
        $project = $this->makeProject($org, $user);
        $contract = $this->makeContract($project, $user);
        $app = $this->makeApplication($project, $contract, $user, [
            'pay_less_notice_deadline' => now()->subDays(2)->toDateString(),
        ]);
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/payment-applications/{$app->id}/pay-less-notice", [
            'notice_date' => now()->toDateString(),
            'original_amount_due' => 1000, 'total_deductions' => 100,
            'deduction_reason' => 'Late notice test',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('pay_less_notices', [
            'payment_application_id' => $app->id,
            'is_late' => 1,
        ]);
    }

    public function test_pay_less_notice_is_not_flagged_late_when_issued_before_the_deadline(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org);
        $project = $this->makeProject($org, $user);
        $contract = $this->makeContract($project, $user);
        $app = $this->makeApplication($project, $contract, $user, [
            'pay_less_notice_deadline' => now()->addDays(5)->toDateString(),
        ]);
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/payment-applications/{$app->id}/pay-less-notice", [
            'notice_date' => now()->toDateString(),
            'original_amount_due' => 1000, 'total_deductions' => 100,
            'deduction_reason' => 'On-time notice test',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('pay_less_notices', [
            'payment_application_id' => $app->id,
            'is_late' => 0,
        ]);
    }

    public function test_pay_less_notice_deadline_dates_from_payment_date_service_are_stable(): void
    {
        // Existing behavior only — asserts the current, already-shipped
        // arithmetic, never changes or re-derives the statutory rule.
        $org = $this->makeOrg();
        $user = $this->makeUser($org);
        $project = $this->makeProject($org, $user);
        $contract = $this->makeContract($project, $user);
        $contract->update([
            'due_date_offset_days' => 7,
            'final_date_offset_days' => 14,
            'payment_notice_offset_days' => 5,
            'pay_less_notice_offset_days' => 7,
        ]);

        $dates = \App\Services\PaymentDateService::calculateForApplication(
            \Carbon\Carbon::parse('2026-08-01'), $contract->fresh()
        );

        // due_date = application_date + due_date_offset (7)
        // final_date = due_date + final_date_offset (14) — cascades off due_date, not application_date
        // payment_notice_deadline = due_date + notice_offset (5)
        // pay_less_notice_deadline = final_date - pay_less_offset (7)
        $this->assertSame('2026-08-08', $dates['due_date']);
        $this->assertSame('2026-08-22', $dates['final_date_for_payment']);
        $this->assertSame('2026-08-13', $dates['payment_notice_deadline']);
        $this->assertSame('2026-08-15', $dates['pay_less_notice_deadline']);
    }

    // ── Generated document linkage ───────────────────────────────────────

    public function test_pay_less_notice_creation_attaches_a_generated_document_when_generation_is_enabled(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org);
        $project = $this->makeProject($org, $user);
        $contract = $this->makeContract($project, $user);
        $app = $this->makeApplication($project, $contract, $user);
        \App\Models\SuresignSetting::instance()->update(['feature_document_generation' => true]);
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/payment-applications/{$app->id}/pay-less-notice", [
            'notice_date' => now()->toDateString(),
            'original_amount_due' => 1000, 'total_deductions' => 100,
            'deduction_reason' => 'Document linkage test',
        ]);

        $response->assertStatus(201);
        $this->assertNotNull($response->json('document'));

        $noticeId = $response->json('notice.id');
        $this->assertDatabaseHas('documents', [
            'documentable_type' => \App\Models\PayLessNotice::class,
            'documentable_id' => $noticeId,
        ]);
    }

    public function test_pay_less_notice_creation_succeeds_and_reports_no_document_when_generation_is_disabled(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org);
        $project = $this->makeProject($org, $user);
        $contract = $this->makeContract($project, $user);
        $app = $this->makeApplication($project, $contract, $user);
        \App\Models\SuresignSetting::instance()->update(['feature_document_generation' => false]);
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/payment-applications/{$app->id}/pay-less-notice", [
            'notice_date' => now()->toDateString(),
            'original_amount_due' => 1000, 'total_deductions' => 100,
            'deduction_reason' => 'Generation disabled test',
        ]);

        // The notice itself must never fail to be created because PDF
        // generation is unavailable — mirrors the same partial-success
        // contract already proven for certify().
        $response->assertStatus(201);
        $this->assertNull($response->json('document'));
        $this->assertDatabaseHas('pay_less_notices', ['payment_application_id' => $app->id, 'status' => 'issued']);
    }

    // ── Tenant isolation / cross-project ID substitution ─────────────────

    public function test_user_cannot_create_a_pay_less_notice_on_another_organisations_application(): void
    {
        $orgA = $this->makeOrg('Org A');
        $orgB = $this->makeOrg('Org B');
        $userA = $this->makeUser($orgA);
        $userB = $this->makeUser($orgB);
        $projectB = $this->makeProject($orgB, $userB);
        $contractB = $this->makeContract($projectB, $userB);
        $appB = $this->makeApplication($projectB, $contractB, $userB);

        Sanctum::actingAs($userA);

        $response = $this->postJson("/api/payment-applications/{$appB->id}/pay-less-notice", [
            'notice_date' => now()->toDateString(),
            'original_amount_due' => 100, 'total_deductions' => 10,
            'deduction_reason' => 'Cross-tenant attempt',
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseCount('pay_less_notices', 0);
    }

    public function test_user_cannot_list_another_organisations_pay_less_notices_via_project_id_substitution(): void
    {
        $orgA = $this->makeOrg('Org A');
        $orgB = $this->makeOrg('Org B');
        $userA = $this->makeUser($orgA);
        $userB = $this->makeUser($orgB);
        $projectB = $this->makeProject($orgB, $userB);
        $contractB = $this->makeContract($projectB, $userB);
        $appB = $this->makeApplication($projectB, $contractB, $userB);
        Sanctum::actingAs($userB);
        $this->postJson("/api/payment-applications/{$appB->id}/pay-less-notice", [
            'notice_date' => now()->toDateString(),
            'original_amount_due' => 500, 'total_deductions' => 50,
            'deduction_reason' => 'Owned by org B',
        ])->assertStatus(201);

        // Org A user substitutes org B's own project id into the nested route.
        Sanctum::actingAs($userA);
        $response = $this->getJson("/api/projects/{$projectB->id}/pay-less-notices");

        $response->assertStatus(403);
    }

    // ── Role permissions ──────────────────────────────────────────────────

    public function test_admin_role_can_create_a_pay_less_notice_across_organisations(): void
    {
        $org = $this->makeOrg();
        $owner = $this->makeUser($org);
        $project = $this->makeProject($org, $owner);
        $contract = $this->makeContract($project, $owner);
        $app = $this->makeApplication($project, $contract, $owner);

        $admin = $this->makeUser($this->makeOrg('Platform Admin Org'), 'Admin');
        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/payment-applications/{$app->id}/pay-less-notice", [
            'notice_date' => now()->toDateString(),
            'original_amount_due' => 1000, 'total_deductions' => 200,
            'deduction_reason' => 'Admin cross-org action',
        ]);

        $response->assertStatus(201);
    }

    public function test_client_role_cannot_create_a_pay_less_notice_outside_their_own_organisation(): void
    {
        $org = $this->makeOrg();
        $owner = $this->makeUser($org);
        $project = $this->makeProject($org, $owner);
        $contract = $this->makeContract($project, $owner);
        $app = $this->makeApplication($project, $contract, $owner);

        $client = $this->makeUser($this->makeOrg('Other Org'), 'Client');
        Sanctum::actingAs($client);

        $response = $this->postJson("/api/payment-applications/{$app->id}/pay-less-notice", [
            'notice_date' => now()->toDateString(),
            'original_amount_due' => 1000, 'total_deductions' => 200,
            'deduction_reason' => 'Client cross-org attempt',
        ]);

        $response->assertStatus(403);
    }

    // ── Feature Availability enforcement ─────────────────────────────────

    public function test_commercial_maintenance_blocks_pay_less_notice_creation(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org);
        $project = $this->makeProject($org, $user);
        $contract = $this->makeContract($project, $user);
        $app = $this->makeApplication($project, $contract, $user);
        FeatureAvailability::create(['feature_key' => 'project.commercial', 'status' => 'maintenance']);
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/payment-applications/{$app->id}/pay-less-notice", [
            'notice_date' => now()->toDateString(),
            'original_amount_due' => 1000, 'total_deductions' => 200,
            'deduction_reason' => 'Should be blocked',
        ]);

        $response->assertStatus(503);
        $response->assertJson(['code' => 'feature_maintenance', 'feature' => 'project.commercial']);
        $this->assertDatabaseCount('pay_less_notices', 0);
    }

    public function test_commercial_maintenance_does_not_block_read_access_to_existing_pay_less_notices(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org);
        $project = $this->makeProject($org, $user);
        $contract = $this->makeContract($project, $user);
        $app = $this->makeApplication($project, $contract, $user);
        Sanctum::actingAs($user);
        $this->postJson("/api/payment-applications/{$app->id}/pay-less-notice", [
            'notice_date' => now()->toDateString(),
            'original_amount_due' => 1000, 'total_deductions' => 200,
            'deduction_reason' => 'Created before maintenance',
        ])->assertStatus(201);

        FeatureAvailability::create(['feature_key' => 'project.commercial', 'status' => 'maintenance']);

        $response = $this->getJson("/api/projects/{$project->id}/pay-less-notices");
        $response->assertStatus(200);
    }

    // ── Status semantics: notice issuance never mutates parent status ────

    public function test_issuing_a_pay_less_notice_does_not_change_the_parent_application_status(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org);
        $project = $this->makeProject($org, $user);
        $contract = $this->makeContract($project, $user);
        $app = $this->makeApplication($project, $contract, $user, ['status' => 'submitted']);
        Sanctum::actingAs($user);

        $this->postJson("/api/payment-applications/{$app->id}/pay-less-notice", [
            'notice_date' => now()->toDateString(),
            'original_amount_due' => 1000, 'total_deductions' => 200,
            'deduction_reason' => 'Status stability check',
        ])->assertStatus(201);

        // The parent PaymentApplication.status is application-level only —
        // it must remain 'submitted', never silently advance to a
        // notice-issued value. That state lives exclusively on the child
        // PayLessNotice record created above.
        $this->assertDatabaseHas('payment_applications', ['id' => $app->id, 'status' => 'submitted']);
    }

    public function test_cancel_still_accepts_a_submitted_application_and_still_rejects_others(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org);
        $project = $this->makeProject($org, $user);
        $contract = $this->makeContract($project, $user);
        $submitted = $this->makeApplication($project, $contract, $user, ['status' => 'submitted']);
        $draft = $this->makeApplication($project, $contract, $user, ['status' => 'draft']);
        $certified = $this->makeApplication($project, $contract, $user, ['status' => 'certified']);
        Sanctum::actingAs($user);

        // Confirms the cancel() literal-cleanup preserved existing behavior
        // exactly — 'submitted' remains cancellable, non-submitted statuses
        // remain rejected, unchanged before and after the fix.
        $this->postJson("/api/payment-applications/{$submitted->id}/cancel")->assertStatus(200);
        $this->assertDatabaseHas('payment_applications', ['id' => $submitted->id, 'status' => 'cancelled']);

        $this->postJson("/api/payment-applications/{$draft->id}/cancel")->assertStatus(422);
        $this->postJson("/api/payment-applications/{$certified->id}/cancel")->assertStatus(422);
    }

    public function test_a_submitted_application_with_an_issued_pay_less_notice_can_still_be_cancelled(): void
    {
        // Real-world case cancel()'s literal cleanup depends on: an
        // application that already has a pay-less notice issued against it
        // is STILL stored as status='submitted' (never
        // 'pay_less_notice_issued'), so it must still be cancellable.
        $org = $this->makeOrg();
        $user = $this->makeUser($org);
        $project = $this->makeProject($org, $user);
        $contract = $this->makeContract($project, $user);
        $app = $this->makeApplication($project, $contract, $user, ['status' => 'submitted']);
        Sanctum::actingAs($user);

        $this->postJson("/api/payment-applications/{$app->id}/pay-less-notice", [
            'notice_date' => now()->toDateString(),
            'original_amount_due' => 1000, 'total_deductions' => 200,
            'deduction_reason' => 'Prior to cancellation',
        ])->assertStatus(201);

        $this->postJson("/api/payment-applications/{$app->id}/cancel")->assertStatus(200);
        $this->assertDatabaseHas('payment_applications', ['id' => $app->id, 'status' => 'cancelled']);
    }
}
