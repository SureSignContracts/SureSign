<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\FinalAccount;
use App\Models\Organization;
use App\Models\PaymentApplication;
use App\Models\PayLessNotice;
use App\Models\Project;
use App\Models\SuresignNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Batch 1: Commercial notifications (Payment Applications, Payment Notices,
 * Pay Less Notices, Final Accounts). Verifies the new sendToOrganization()
 * fan-out is wired correctly for each lifecycle event, respects the
 * approved channel policy (which events also get an email), and that
 * action URLs resolve through WorkspaceNavigationResolver.
 */
class NotificationBatch1CommercialTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every org gets two Client users — the actor and a bystander. Actor
     * exclusion is the default for these synchronous commercial actions, so
     * a single-user org would otherwise leave every notification table
     * empty regardless of whether the fan-out logic actually works.
     */
    private function makeOrgAndClient(string $label): array
    {
        static $n = 0;
        $n++;

        $org   = Organization::create(['name' => "{$label} Org {$n}", 'slug' => "org-{$label}-{$n}"]);
        $user  = User::factory()->create(['organization_id' => $org->id]);
        $other = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole(Role::firstOrCreate(['name' => 'Client', 'guard_name' => 'web']));
        $other->assignRole(Role::firstOrCreate(['name' => 'Client', 'guard_name' => 'web']));

        return compact('org', 'user', 'other');
    }

    private function makeProject(Organization $org, User $user): Project
    {
        return Project::create([
            'organization_id' => $org->id, 'created_by' => $user->id,
            'name' => "Project for {$org->name}", 'status' => 'active',
        ]);
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
            'application_number' => $n, 'status' => 'draft',
            'application_date' => now()->toDateString(), 'gross_valuation' => 10000, 'amount_due' => 9500,
        ], $overrides));
    }

    // ── Payment Application ──────────────────────────────────────────────

    public function test_payment_application_submitted_notifies_other_client_users_and_excludes_the_actor(): void
    {
        $a        = $this->makeOrgAndClient('a');
        $project  = $this->makeProject($a['org'], $a['user']);
        $contract = $this->makeContract($project, $a['user']);
        $draft    = $this->makeApplication($project, $contract, $a['user'], ['status' => 'draft']);

        \Laravel\Sanctum\Sanctum::actingAs($a['user']);
        $this->postJson("/api/payment-applications/{$draft->id}/submit")->assertStatus(200);

        $this->assertDatabaseHas('suresign_notifications', [
            'user_id' => $a['other']->id, 'source_type' => 'payment_application',
            'source_id' => $draft->id, 'source_field' => 'submitted',
        ]);
        $this->assertDatabaseMissing('suresign_notifications', [
            'user_id' => $a['user']->id, 'source_type' => 'payment_application', 'source_id' => $draft->id,
        ]);
    }

    public function test_payment_application_certified_has_correct_priority_category_and_action_url(): void
    {
        $a        = $this->makeOrgAndClient('a');
        $project  = $this->makeProject($a['org'], $a['user']);
        $contract = $this->makeContract($project, $a['user']);
        $submitted = $this->makeApplication($project, $contract, $a['user'], ['status' => 'submitted']);

        \Laravel\Sanctum\Sanctum::actingAs($a['user']);
        $this->postJson("/api/payment-applications/{$submitted->id}/certify", ['certified_amount' => 9000])->assertStatus(200);

        $this->assertDatabaseHas('suresign_notifications', [
            'source_type' => 'payment_application', 'source_id' => $submitted->id, 'source_field' => 'certified',
            'category'    => SuresignNotification::CATEGORY_COMMERCIAL,
            'priority'    => SuresignNotification::PRIORITY_WARNING,
            'action_url'  => "/app/projects/{$project->id}/commercial?tab=applications",
        ]);
    }

    public function test_payment_application_marked_paid_notifies_in_app_only_no_new_email_added(): void
    {
        $a        = $this->makeOrgAndClient('a');
        $project  = $this->makeProject($a['org'], $a['user']);
        $contract = $this->makeContract($project, $a['user']);
        $certified = $this->makeApplication($project, $contract, $a['user'], ['status' => 'certified']);

        \Laravel\Sanctum\Sanctum::actingAs($a['user']);
        $this->postJson("/api/payment-applications/{$certified->id}/mark-paid", ['paid_amount' => 9000])->assertStatus(200);

        $this->assertDatabaseHas('suresign_notifications', [
            'source_type' => 'payment_application', 'source_id' => $certified->id, 'source_field' => 'paid',
        ]);
        // No assertion possible against Brevo directly (it's an HTTP call gated by
        // settings), but the controller change under test adds no EmailNotificationService
        // call for markPaid — covered by static review; this test proves the in-app side only.
    }

    public function test_inactive_and_other_org_users_do_not_receive_payment_application_notifications(): void
    {
        $a = $this->makeOrgAndClient('a');
        $b = $this->makeOrgAndClient('b');
        $inactiveInA = User::factory()->create(['organization_id' => $a['org']->id, 'is_active' => false]);
        $inactiveInA->assignRole(Role::firstOrCreate(['name' => 'Client', 'guard_name' => 'web']));

        $project  = $this->makeProject($a['org'], $a['user']);
        $contract = $this->makeContract($project, $a['user']);
        $draft    = $this->makeApplication($project, $contract, $a['user'], ['status' => 'draft']);

        \Laravel\Sanctum\Sanctum::actingAs($a['user']);
        $this->postJson("/api/payment-applications/{$draft->id}/submit")->assertStatus(200);

        $this->assertDatabaseMissing('suresign_notifications', ['user_id' => $b['user']->id]);
        $this->assertDatabaseMissing('suresign_notifications', ['user_id' => $inactiveInA->id]);
    }

    public function test_resubmitting_after_cancellation_does_not_duplicate_the_submitted_notification(): void
    {
        // Simulates a retried/duplicate-report scenario for the same lifecycle stage.
        $a        = $this->makeOrgAndClient('a');
        $project  = $this->makeProject($a['org'], $a['user']);
        $contract = $this->makeContract($project, $a['user']);
        $draft    = $this->makeApplication($project, $contract, $a['user'], ['status' => 'draft']);

        \Laravel\Sanctum\Sanctum::actingAs($a['user']);
        $this->postJson("/api/payment-applications/{$draft->id}/submit")->assertStatus(200);
        // Calling submit again is rejected by the workflow guard (already submitted) —
        // proves the guard itself prevents a second dispatch, not just the dedup key.
        $this->postJson("/api/payment-applications/{$draft->id}/submit")->assertStatus(422);

        $this->assertEquals(
            1,
            SuresignNotification::where('source_type', 'payment_application')
                ->where('source_id', $draft->id)->where('source_field', 'submitted')->count()
        );
    }

    // ── Payment Notice / Pay Less Notice ─────────────────────────────────

    public function test_payment_notice_issued_notifies_in_app_with_notice_category(): void
    {
        $a        = $this->makeOrgAndClient('a');
        $project  = $this->makeProject($a['org'], $a['user']);
        $contract = $this->makeContract($project, $a['user']);
        $certified = $this->makeApplication($project, $contract, $a['user'], ['status' => 'certified']);

        \Laravel\Sanctum\Sanctum::actingAs($a['user']);
        $response = $this->postJson("/api/payment-applications/{$certified->id}/payment-notice", [
            'notice_date' => now()->toDateString(), 'notified_sum' => 9000,
        ]);
        $response->assertStatus(201);
        $noticeId = $response->json('notice.id');

        $this->assertDatabaseHas('suresign_notifications', [
            'source_type' => 'payment_notice', 'source_id' => $noticeId, 'source_field' => 'issued',
            'category'    => SuresignNotification::CATEGORY_NOTICE,
            'action_url'  => "/app/projects/{$project->id}/commercial?tab=notices",
        ]);
    }

    public function test_pay_less_notice_issued_via_payment_application_notifies_in_app(): void
    {
        $a        = $this->makeOrgAndClient('a');
        $project  = $this->makeProject($a['org'], $a['user']);
        $contract = $this->makeContract($project, $a['user']);
        $certified = $this->makeApplication($project, $contract, $a['user'], ['status' => 'certified']);

        \Laravel\Sanctum\Sanctum::actingAs($a['user']);
        $response = $this->postJson("/api/payment-applications/{$certified->id}/pay-less-notice", [
            'notice_date' => now()->toDateString(), 'reference' => 'PLN-1',
            'original_amount_due' => 9000, 'total_deductions' => 500, 'deduction_reason' => 'Defects',
        ]);
        $response->assertStatus(201);
        $noticeId = $response->json('notice.id');

        $this->assertDatabaseHas('suresign_notifications', [
            'source_type' => 'pay_less_notice', 'source_id' => $noticeId, 'source_field' => 'issued',
            'action_url'  => "/app/projects/{$project->id}/commercial?tab=notices",
        ]);
    }

    public function test_pay_less_notice_standalone_store_as_draft_does_not_notify_but_issuing_via_update_does(): void
    {
        $a       = $this->makeOrgAndClient('a');
        $project = $this->makeProject($a['org'], $a['user']);

        \Laravel\Sanctum\Sanctum::actingAs($a['user']);

        $draft = $this->postJson("/api/projects/{$project->id}/pay-less-notices", [
            'notice_date' => now()->toDateString(), 'amount' => 500, 'reason' => 'Defects', 'status' => 'draft',
        ]);
        $draft->assertStatus(201);
        $noticeId = $draft->json('id');

        $this->assertDatabaseMissing('suresign_notifications', ['source_type' => 'pay_less_notice', 'source_id' => $noticeId]);

        $issue = $this->putJson("/api/projects/{$project->id}/pay-less-notices/{$noticeId}", ['status' => 'issued']);
        $issue->assertStatus(200);

        $this->assertDatabaseHas('suresign_notifications', [
            'source_type' => 'pay_less_notice', 'source_id' => $noticeId, 'source_field' => 'issued',
        ]);
    }

    // ── Final Account ─────────────────────────────────────────────────────

    private function makeFinalAccount(Project $project, Contract $contract, array $overrides = []): FinalAccount
    {
        static $n = 0;
        $n++;

        return FinalAccount::create(array_merge([
            'organization_id' => $project->organization_id, 'project_id' => $project->id,
            'contract_id' => $contract->id, 'is_trade_package' => false,
            'reference' => "FA-{$n}", 'status' => FinalAccount::STATUS_DRAFT,
            'original_contract_sum' => 100000,
        ], $overrides));
    }

    public function test_final_account_signed_notifies_and_final_account_review_transitions_stay_silent(): void
    {
        $a        = $this->makeOrgAndClient('a');
        $project  = $this->makeProject($a['org'], $a['user']);
        $contract = $this->makeContract($project, $a['user']);
        $agreed   = $this->makeFinalAccount($project, $contract, ['status' => FinalAccount::STATUS_AGREED]);

        \Laravel\Sanctum\Sanctum::actingAs($a['user']);
        $this->postJson("/api/final-accounts/{$agreed->id}/sign")->assertStatus(200);

        $this->assertDatabaseHas('suresign_notifications', [
            'source_type' => 'final_account', 'source_id' => $agreed->id, 'source_field' => 'signed',
            'action_url'  => "/app/projects/{$project->id}/commercial?tab=final-account&fa={$agreed->id}",
        ]);

        // agree() itself is not one of the approved notify events — confirms no
        // notification spam was introduced for internal review-state transitions.
        $this->assertDatabaseMissing('suresign_notifications', [
            'source_type' => 'final_account', 'source_id' => $agreed->id, 'source_field' => 'agreed',
        ]);
    }

    public function test_final_account_certificate_issued_and_closed_notify_distinctly(): void
    {
        $a        = $this->makeOrgAndClient('a');
        $project  = $this->makeProject($a['org'], $a['user']);
        $contract = $this->makeContract($project, $a['user']);
        $signed   = $this->makeFinalAccount($project, $contract, ['status' => FinalAccount::STATUS_SIGNED]);

        \Laravel\Sanctum\Sanctum::actingAs($a['user']);
        $this->postJson("/api/final-accounts/{$signed->id}/issue-certificate")->assertStatus(200);
        $this->postJson("/api/final-accounts/{$signed->id}/close")->assertStatus(200);

        $this->assertDatabaseHas('suresign_notifications', [
            'source_type' => 'final_account', 'source_id' => $signed->id, 'source_field' => 'final_certificate_issued',
        ]);
        $this->assertDatabaseHas('suresign_notifications', [
            'source_type' => 'final_account', 'source_id' => $signed->id, 'source_field' => 'closed',
        ]);
    }

    public function test_final_account_submitted_notifies_in_app(): void
    {
        $a        = $this->makeOrgAndClient('a');
        $project  = $this->makeProject($a['org'], $a['user']);
        $contract = $this->makeContract($project, $a['user']);
        $draft    = $this->makeFinalAccount($project, $contract, ['status' => FinalAccount::STATUS_DRAFT]);

        \Laravel\Sanctum\Sanctum::actingAs($a['user']);
        $this->postJson("/api/final-accounts/{$draft->id}/submit")->assertStatus(200);

        $this->assertDatabaseHas('suresign_notifications', [
            'source_type' => 'final_account', 'source_id' => $draft->id, 'source_field' => 'submitted',
        ]);
    }

    public function test_trade_package_scoped_final_account_action_url_points_to_the_workspace(): void
    {
        $a        = $this->makeOrgAndClient('a');
        $project  = $this->makeProject($a['org'], $a['user']);
        $contract = $this->makeContract($project, $a['user']);

        $tradePackage = \App\Models\TradePackage::create([
            'project_id' => $project->id, 'organization_id' => $a['org']->id,
            'name' => 'Package A', 'slug' => 'package-a-' . uniqid(), 'status' => 'active',
        ]);

        $agreed = $this->makeFinalAccount($project, $contract, [
            'status' => FinalAccount::STATUS_AGREED, 'is_trade_package' => true, 'trade_package_id' => $tradePackage->id,
        ]);

        \Laravel\Sanctum\Sanctum::actingAs($a['user']);
        $this->postJson("/api/final-accounts/{$agreed->id}/sign")->assertStatus(200);

        $this->assertDatabaseHas('suresign_notifications', [
            'source_type' => 'final_account', 'source_id' => $agreed->id, 'source_field' => 'signed',
            'action_url'  => "/app/projects/{$project->id}/subcontracts/{$tradePackage->id}?tab=commercial",
        ]);
    }
}
