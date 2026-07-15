<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Organization;
use App\Models\PayLessNotice;
use App\Models\PaymentApplication;
use App\Models\PaymentNotice;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Batch 4: Payment Notices and Pay Less Notices.
 *
 * Found and fixed a real gap: PaymentNoticeController::destroy() and
 * PayLessNoticeController::update()/destroy() had NO status guard at all —
 * an issued statutory notice could be edited or deleted with no
 * restriction, for every role. Fixed by locking edit/delete once
 * status === 'issued'.
 *
 * A separate pre-existing bug — PayLessNoticeController's show/update/
 * destroy methods only declared a PayLessNotice parameter even though
 * their routes are nested as /projects/{project}/pay-less-notices/
 * {pay_less_notice}, causing Laravel to pass the {project} segment
 * positionally into the wrong argument and 500 with a TypeError on every
 * call — has since been fixed (cleanup pass) by declaring the unused
 * Project $project parameter, matching the established pattern elsewhere
 * (MeetingMinutesController, SiteDiaryController, etc). These tests now
 * exercise the real HTTP routes directly.
 */
class Batch4NoticesTest extends TestCase
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
        ]);
    }

    private function makePaymentNotice(Project $project, User $user, array $overrides = []): PaymentNotice
    {
        static $n = 0;
        $n++;

        $contract = $this->makeContract($project, $user);
        $app = PaymentApplication::create([
            'project_id'         => $project->id,
            'contract_id'        => $contract->id,
            'organization_id'    => $project->organization_id,
            'created_by'         => $user->id,
            'application_number' => $n,
            'status'             => 'submitted',
            'application_date'   => now()->toDateString(),
            'gross_valuation'    => 5000,
        ]);

        return PaymentNotice::create(array_merge([
            'project_id'              => $project->id,
            'organization_id'         => $project->organization_id,
            'created_by'              => $user->id,
            'payment_application_id'  => $app->id,
            'notice_date'             => now()->toDateString(),
            'notified_sum'            => 5000,
            'status'                  => 'issued',
        ], $overrides));
    }

    private function makePayLessNotice(Project $project, User $user, array $overrides = []): PayLessNotice
    {
        return PayLessNotice::create(array_merge([
            'project_id'      => $project->id,
            'organization_id' => $project->organization_id,
            'created_by'      => $user->id,
            'notice_date'     => now()->toDateString(),
            'amount'          => 500,
            'status'          => 'issued',
        ], $overrides));
    }

    // ── Workflow-state protection (the new fix) ───────────────────────────

    public function test_an_issued_payment_notice_cannot_be_deleted(): void
    {
        $a = $this->makeOrgAndUser('a');
        $project = $this->makeProject($a['org'], $a['user']);
        $notice = $this->makePaymentNotice($project, $a['user']);
        Sanctum::actingAs($a['user']);

        $response = $this->deleteJson("/api/payment-notices/{$notice->id}");
        $response->assertStatus(422);
        $this->assertDatabaseHas('payment_notices', ['id' => $notice->id]);
    }

    public function test_a_draft_payment_notice_can_still_be_deleted(): void
    {
        $a = $this->makeOrgAndUser('a');
        $project = $this->makeProject($a['org'], $a['user']);
        $notice = $this->makePaymentNotice($project, $a['user'], ['status' => 'draft']);
        Sanctum::actingAs($a['user']);

        $this->deleteJson("/api/payment-notices/{$notice->id}")->assertStatus(204);
        $this->assertSoftDeleted('payment_notices', ['id' => $notice->id]);
    }

    public function test_an_issued_pay_less_notice_cannot_be_edited_or_deleted(): void
    {
        $a = $this->makeOrgAndUser('a');
        $project = $this->makeProject($a['org'], $a['user']);
        $notice = $this->makePayLessNotice($project, $a['user']);
        Sanctum::actingAs($a['user']);

        $this->putJson("/api/projects/{$project->id}/pay-less-notices/{$notice->id}", ['amount' => 999])->assertStatus(422);
        $this->deleteJson("/api/projects/{$project->id}/pay-less-notices/{$notice->id}")->assertStatus(422);
        $this->assertDatabaseHas('pay_less_notices', ['id' => $notice->id, 'amount' => 500]);
    }

    public function test_a_draft_pay_less_notice_can_still_be_edited_and_deleted(): void
    {
        $a = $this->makeOrgAndUser('a');
        $project = $this->makeProject($a['org'], $a['user']);
        $notice = $this->makePayLessNotice($project, $a['user'], ['status' => 'draft']);
        Sanctum::actingAs($a['user']);

        $this->putJson("/api/projects/{$project->id}/pay-less-notices/{$notice->id}", ['amount' => 999])->assertStatus(200);
        $this->assertDatabaseHas('pay_less_notices', ['id' => $notice->id, 'amount' => 999]);

        $this->deleteJson("/api/projects/{$project->id}/pay-less-notices/{$notice->id}")->assertStatus(204);
        $this->assertDatabaseMissing('pay_less_notices', ['id' => $notice->id]);
    }

    // ── Positive: Client can view/manage their own org's notices ─────────

    public function test_client_can_view_notices_in_their_own_organisation(): void
    {
        $a = $this->makeOrgAndUser('a');
        $project = $this->makeProject($a['org'], $a['user']);
        $pn = $this->makePaymentNotice($project, $a['user']);
        $pln = $this->makePayLessNotice($project, $a['user']);
        Sanctum::actingAs($a['user']);

        $this->getJson("/api/payment-notices/{$pn->id}")->assertStatus(200);
        $this->getJson("/api/projects/{$project->id}/payment-notices")->assertStatus(200);
        $this->getJson("/api/projects/{$project->id}/pay-less-notices/{$pln->id}")->assertStatus(200);
        $this->getJson("/api/projects/{$project->id}/pay-less-notices")->assertStatus(200);
    }

    public function test_client_can_create_a_pay_less_notice_directly_in_their_own_project(): void
    {
        $a = $this->makeOrgAndUser('a');
        $project = $this->makeProject($a['org'], $a['user']);
        Sanctum::actingAs($a['user']);

        $response = $this->postJson("/api/projects/{$project->id}/pay-less-notices", [
            'notice_date' => now()->toDateString(), 'amount' => 250,
        ]);
        $response->assertStatus(201);
        $this->assertDatabaseHas('pay_less_notices', ['project_id' => $project->id, 'amount' => 250]);
    }

    // ── Negative: cross-tenant ────────────────────────────────────────────

    public function test_client_cannot_view_edit_or_delete_another_organisations_payment_notice(): void
    {
        $a = $this->makeOrgAndUser('a');
        $b = $this->makeOrgAndUser('b');
        $projectB = $this->makeProject($b['org'], $b['user']);
        $noticeB = $this->makePaymentNotice($projectB, $b['user'], ['status' => 'draft']);

        Sanctum::actingAs($a['user']);

        $this->getJson("/api/payment-notices/{$noticeB->id}")->assertStatus(403);
        $this->deleteJson("/api/payment-notices/{$noticeB->id}")->assertStatus(403);
        $this->getJson("/api/projects/{$projectB->id}/payment-notices")->assertStatus(403);
    }

    public function test_client_cannot_view_edit_or_delete_another_organisations_pay_less_notice(): void
    {
        $a = $this->makeOrgAndUser('a');
        $b = $this->makeOrgAndUser('b');
        $projectB = $this->makeProject($b['org'], $b['user']);
        $noticeB = $this->makePayLessNotice($projectB, $b['user'], ['status' => 'draft']);

        Sanctum::actingAs($a['user']);

        $this->getJson("/api/projects/{$projectB->id}/pay-less-notices/{$noticeB->id}")->assertStatus(403);
        $this->putJson("/api/projects/{$projectB->id}/pay-less-notices/{$noticeB->id}", ['amount' => 1])->assertStatus(403);
        $this->deleteJson("/api/projects/{$projectB->id}/pay-less-notices/{$noticeB->id}")->assertStatus(403);
        $this->getJson("/api/projects/{$projectB->id}/pay-less-notices")->assertStatus(403);
        $this->postJson("/api/projects/{$projectB->id}/pay-less-notices", ['notice_date' => now()->toDateString(), 'amount' => 1])->assertStatus(403);
        $this->assertDatabaseHas('pay_less_notices', ['id' => $noticeB->id, 'amount' => 500]);
    }
}
