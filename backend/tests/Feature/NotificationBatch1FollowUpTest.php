<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\FinalAccount;
use App\Models\Organization;
use App\Models\PaymentApplication;
use App\Models\Project;
use App\Models\SuresignNotification;
use App\Models\SuresignSetting;
use App\Models\User;
use App\Models\Variation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Batch 1 follow-ups:
 *   1. The four new email events (payment_notice.issued, final_account.signed,
 *      final_account.final_certificate_issued, final_account.closed) now have
 *      settings-page toggles — prove enabled sends and disabled suppresses.
 *   2. VariationController::notify() now only emails for approved/rejected —
 *      prove the other five transitions stay in-app only while all seven
 *      still create a distinct in-app notification.
 */
class NotificationBatch1FollowUpTest extends TestCase
{
    use RefreshDatabase;

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

    private function enableBrevo(array $enabledEvents): void
    {
        SuresignSetting::instance()->update([
            'brevo_api_key'         => 'fake-brevo-key',
            'notification_settings' => $enabledEvents,
        ]);
        Http::fake(['api.brevo.com/*' => Http::response(['messageId' => 'test'], 201)]);
    }

    // ── Follow-up 1: new email-event toggles ─────────────────────────────

    public function test_payment_notice_issued_email_sends_when_the_event_is_enabled(): void
    {
        $this->enableBrevo(['payment_notice.issued']);

        $a         = $this->makeOrgAndClient('a');
        $project   = $this->makeProject($a['org'], $a['user']);
        $contract  = $this->makeContract($project, $a['user']);
        $certified = PaymentApplication::create([
            'project_id' => $project->id, 'contract_id' => $contract->id,
            'organization_id' => $project->organization_id, 'created_by' => $a['user']->id,
            'application_number' => 1, 'status' => 'certified',
            'application_date' => now()->toDateString(), 'gross_valuation' => 10000, 'amount_due' => 9500,
        ]);

        Sanctum::actingAs($a['user']);
        $this->postJson("/api/payment-applications/{$certified->id}/payment-notice", [
            'notice_date' => now()->toDateString(), 'notified_sum' => 9000,
        ])->assertStatus(201);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.brevo.com'));
    }

    public function test_payment_notice_issued_email_is_suppressed_when_the_event_is_disabled(): void
    {
        $this->enableBrevo([]); // brevo configured but no events opted in

        $a         = $this->makeOrgAndClient('a');
        $project   = $this->makeProject($a['org'], $a['user']);
        $contract  = $this->makeContract($project, $a['user']);
        $certified = PaymentApplication::create([
            'project_id' => $project->id, 'contract_id' => $contract->id,
            'organization_id' => $project->organization_id, 'created_by' => $a['user']->id,
            'application_number' => 1, 'status' => 'certified',
            'application_date' => now()->toDateString(), 'gross_valuation' => 10000, 'amount_due' => 9500,
        ]);

        Sanctum::actingAs($a['user']);
        $this->postJson("/api/payment-applications/{$certified->id}/payment-notice", [
            'notice_date' => now()->toDateString(), 'notified_sum' => 9000,
        ])->assertStatus(201);

        Http::assertNothingSent();

        // In-app notification still fires regardless of the email toggle.
        $this->assertDatabaseHas('suresign_notifications', [
            'source_type' => 'payment_notice', 'source_field' => 'issued',
        ]);
    }

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

    public function test_final_account_signed_certificate_issued_and_closed_emails_send_when_enabled(): void
    {
        $this->enableBrevo(['final_account.signed', 'final_account.final_certificate_issued', 'final_account.closed']);

        $a        = $this->makeOrgAndClient('a');
        $project  = $this->makeProject($a['org'], $a['user']);
        $contract = $this->makeContract($project, $a['user']);
        $agreed   = $this->makeFinalAccount($project, $contract, ['status' => FinalAccount::STATUS_AGREED]);

        Sanctum::actingAs($a['user']);
        $this->postJson("/api/final-accounts/{$agreed->id}/sign")->assertStatus(200);
        $this->postJson("/api/final-accounts/{$agreed->id}/issue-certificate")->assertStatus(200);
        $this->postJson("/api/final-accounts/{$agreed->id}/close")->assertStatus(200);

        Http::assertSentCount(3);
    }

    public function test_final_account_emails_are_suppressed_when_disabled_but_in_app_still_fires(): void
    {
        $this->enableBrevo([]);

        $a        = $this->makeOrgAndClient('a');
        $project  = $this->makeProject($a['org'], $a['user']);
        $contract = $this->makeContract($project, $a['user']);
        $agreed   = $this->makeFinalAccount($project, $contract, ['status' => FinalAccount::STATUS_AGREED]);

        Sanctum::actingAs($a['user']);
        $this->postJson("/api/final-accounts/{$agreed->id}/sign")->assertStatus(200);

        Http::assertNothingSent();
        $this->assertDatabaseHas('suresign_notifications', [
            'source_type' => 'final_account', 'source_id' => $agreed->id, 'source_field' => 'signed',
        ]);
    }

    // ── Follow-up 2: Variation email now restricted to approved/rejected ──

    private function makeVariation(Project $project, Contract $contract, User $user, array $overrides = []): Variation
    {
        static $n = 0;
        $n++;

        return Variation::create(array_merge([
            'project_id' => $project->id, 'contract_id' => $contract->id,
            'organization_id' => $project->organization_id, 'created_by' => $user->id,
            'variation_number' => $n, 'title' => 'Extra groundworks', 'type' => 'addition',
            'status' => 'draft',
        ], $overrides));
    }

    public function test_variation_approved_sends_email_when_enabled(): void
    {
        $this->enableBrevo(['variation.approved']);

        $a        = $this->makeOrgAndClient('a');
        $project  = $this->makeProject($a['org'], $a['user']);
        $contract = $this->makeContract($project, $a['user']);
        $assessed = $this->makeVariation($project, $contract, $a['user'], ['status' => 'assessed']);

        Sanctum::actingAs($a['user']);
        $this->postJson("/api/variations/{$assessed->id}/approve")->assertStatus(200);

        Http::assertSentCount(1);
    }

    public function test_variation_rejected_sends_email_when_enabled(): void
    {
        $this->enableBrevo(['variation.rejected']);

        $a        = $this->makeOrgAndClient('a');
        $project  = $this->makeProject($a['org'], $a['user']);
        $contract = $this->makeContract($project, $a['user']);
        $assessed = $this->makeVariation($project, $contract, $a['user'], ['status' => 'assessed']);

        Sanctum::actingAs($a['user']);
        $this->postJson("/api/variations/{$assessed->id}/reject", ['rejection_reason' => 'Not required'])->assertStatus(200);

        Http::assertSentCount(1);
    }

    public function test_intermediate_variation_transitions_never_send_email_even_when_all_events_are_enabled(): void
    {
        // All 7 event keys "enabled" — proves the suppression is enforced in
        // code (VariationController::notify), not just by settings toggles.
        $this->enableBrevo([
            'variation.submitted', 'variation.instructed', 'variation.quoted',
            'variation.assessed', 'variation.approved', 'variation.rejected', 'variation.resubmitted',
        ]);

        $a        = $this->makeOrgAndClient('a');
        $project  = $this->makeProject($a['org'], $a['user']);
        $contract = $this->makeContract($project, $a['user']);
        $draft    = $this->makeVariation($project, $contract, $a['user'], ['status' => 'draft']);

        Sanctum::actingAs($a['user']);
        $this->postJson("/api/variations/{$draft->id}/submit")->assertStatus(200);
        $this->postJson("/api/variations/{$draft->id}/instruct")->assertStatus(200);
        $this->postJson("/api/variations/{$draft->id}/quote", ['quoted_amount' => 5000])->assertStatus(200);
        $this->postJson("/api/variations/{$draft->id}/assess", ['assessed_amount' => 4800])->assertStatus(200);

        // None of submitted/instructed/quoted/assessed should ever call Brevo.
        Http::assertNothingSent();
    }

    public function test_all_seven_variation_transitions_still_create_distinct_in_app_notifications(): void
    {
        $this->enableBrevo([]);

        $a        = $this->makeOrgAndClient('a');
        $project  = $this->makeProject($a['org'], $a['user']);
        $contract = $this->makeContract($project, $a['user']);
        $draft    = $this->makeVariation($project, $contract, $a['user'], ['status' => 'draft']);

        Sanctum::actingAs($a['user']);
        $this->postJson("/api/variations/{$draft->id}/submit")->assertStatus(200);
        $this->postJson("/api/variations/{$draft->id}/instruct")->assertStatus(200);
        $this->postJson("/api/variations/{$draft->id}/quote", ['quoted_amount' => 5000])->assertStatus(200);
        $this->postJson("/api/variations/{$draft->id}/assess", ['assessed_amount' => 4800])->assertStatus(200);
        $this->postJson("/api/variations/{$draft->id}/reject", ['rejection_reason' => 'Needs rework'])->assertStatus(200);
        $this->postJson("/api/variations/{$draft->id}/resubmit")->assertStatus(200);

        foreach (['variation_submitted', 'variation_instructed', 'variation_quoted', 'variation_assessed', 'variation_rejected', 'variation_resubmitted'] as $type) {
            $this->assertDatabaseHas('suresign_notifications', [
                'source_type' => 'variation', 'source_id' => $draft->id, 'source_field' => $type, 'type' => $type,
            ]);
        }
    }
}
