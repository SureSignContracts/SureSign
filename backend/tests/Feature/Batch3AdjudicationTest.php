<?php

namespace Tests\Feature;

use App\Models\AdjudicationCase;
use App\Models\AdjudicationDeadline;
use App\Models\AdjudicationDocument;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Batch 3: Adjudication.
 *
 * Two real gaps found and fixed here, affecting every role (not just
 * Client):
 *
 * 1. AdjudicationCaseController and AdjudicationDocumentController's
 *    show/update/destroy/advanceStep/archive/updateStatus methods took both
 *    {project} and {case}/{document} in the URL but never verified the case/
 *    document actually belonged to that project — same-organisation but
 *    mismatched project ID would have succeeded.
 *
 * 2. AdjudicationDeadlineController had NO authorization checks at all on
 *    ANY method — index/store/update/markComplete/destroy were fully open
 *    to any authenticated user of any organisation, for statutory
 *    adjudication deadlines. This is the most serious finding in this
 *    batch and is fixed here for every role.
 *
 * Date-chronology validation (validateDateChronology) and step-sequencing
 * (advanceStep) are untouched.
 */
class Batch3AdjudicationTest extends TestCase
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

    private function makeCase(Project $project, User $user, array $overrides = []): AdjudicationCase
    {
        static $n = 0;
        $n++;

        return AdjudicationCase::create(array_merge([
            'organization_id'  => $project->organization_id,
            'project_id'       => $project->id,
            'created_by'       => $user->id,
            'case_number'      => "ADJ-{$n}",
            'title'            => 'Non-payment dispute',
            'dispute_type'     => 'non_payment',
            'claimant_name'    => 'Contractor Ltd',
            'respondent_name'  => 'Employer Ltd',
            'status'           => 'draft',
            'current_step'     => 'notice_of_dispute',
        ], $overrides));
    }

    // ── Positive: Client has full operational control in their own org ────

    public function test_client_can_create_view_edit_advance_and_archive_a_case_in_their_own_project(): void
    {
        $a = $this->makeOrgAndUser('a');
        $project = $this->makeProject($a['org'], $a['user']);
        Sanctum::actingAs($a['user']);

        $store = $this->postJson("/api/projects/{$project->id}/adjudication-cases", [
            'title' => 'Non-payment dispute', 'dispute_type' => 'non_payment',
            'claimant_name' => 'Contractor Ltd', 'respondent_name' => 'Employer Ltd',
        ]);
        $store->assertStatus(201);
        $id = $store->json('id');

        $this->getJson("/api/projects/{$project->id}/adjudication-cases/{$id}")->assertStatus(200);
        $this->putJson("/api/projects/{$project->id}/adjudication-cases/{$id}", ['summary' => 'Updated summary'])->assertStatus(200);
        $this->postJson("/api/projects/{$project->id}/adjudication-cases/{$id}/advance-step")->assertStatus(200);
        $this->assertDatabaseHas('adjudication_cases', ['id' => $id, 'current_step' => 'notice_of_adjudication']);

        $this->postJson("/api/projects/{$project->id}/adjudication-cases/{$id}/archive")->assertStatus(200);
        $this->assertDatabaseHas('adjudication_cases', ['id' => $id, 'status' => 'archived']);
    }

    public function test_client_can_manage_documents_and_deadlines_on_their_own_case(): void
    {
        $a = $this->makeOrgAndUser('a');
        $project = $this->makeProject($a['org'], $a['user']);
        $case = $this->makeCase($project, $a['user']);
        Sanctum::actingAs($a['user']);

        $doc = $this->postJson("/api/projects/{$project->id}/adjudication-cases/{$case->id}/documents", [
            'title' => 'Referral bundle', 'document_type' => 'referral_submission',
        ]);
        $doc->assertStatus(201);
        $docId = $doc->json('id');
        $this->getJson("/api/projects/{$project->id}/adjudication-cases/{$case->id}/documents")->assertStatus(200);
        $this->deleteJson("/api/projects/{$project->id}/adjudication-documents/{$docId}")->assertStatus(204);

        $deadline = $this->postJson("/api/projects/{$project->id}/adjudication-cases/{$case->id}/deadlines", [
            'title' => 'Referral due', 'deadline_type' => 'referral_deadline', 'due_date' => now()->addDays(7)->toDateString(),
        ]);
        $deadline->assertStatus(201);
        $deadlineId = $deadline->json('id');

        $this->getJson("/api/projects/{$project->id}/adjudication-cases/{$case->id}/deadlines")->assertStatus(200);
        $this->putJson("/api/projects/{$project->id}/adjudication-deadlines/{$deadlineId}", ['title' => 'Referral due (revised)'])->assertStatus(200);
        $this->postJson("/api/projects/{$project->id}/adjudication-deadlines/{$deadlineId}/complete")->assertStatus(200);
        $this->assertDatabaseHas('adjudication_deadlines', ['id' => $deadlineId, 'status' => 'completed']);
        $this->deleteJson("/api/projects/{$project->id}/adjudication-deadlines/{$deadlineId}")->assertStatus(204);
    }

    // ── Negative: cross-tenant ────────────────────────────────────────────

    public function test_client_cannot_view_edit_advance_or_archive_another_organisations_case(): void
    {
        $a = $this->makeOrgAndUser('a');
        $b = $this->makeOrgAndUser('b');
        $projectB = $this->makeProject($b['org'], $b['user']);
        $caseB = $this->makeCase($projectB, $b['user']);

        Sanctum::actingAs($a['user']);

        $this->getJson("/api/projects/{$projectB->id}/adjudication-cases/{$caseB->id}")->assertStatus(403);
        $this->putJson("/api/projects/{$projectB->id}/adjudication-cases/{$caseB->id}", ['summary' => 'Hijacked'])->assertStatus(403);
        $this->postJson("/api/projects/{$projectB->id}/adjudication-cases/{$caseB->id}/advance-step")->assertStatus(403);
        $this->postJson("/api/projects/{$projectB->id}/adjudication-cases/{$caseB->id}/archive")->assertStatus(403);
        $this->deleteJson("/api/projects/{$projectB->id}/adjudication-cases/{$caseB->id}")->assertStatus(403);
    }

    public function test_client_cannot_address_a_case_using_a_mismatched_same_organisation_project_id(): void
    {
        $a = $this->makeOrgAndUser('a');
        $projectOne = $this->makeProject($a['org'], $a['user']);
        $projectTwo = $this->makeProject($a['org'], $a['user']);
        $case = $this->makeCase($projectOne, $a['user']);

        Sanctum::actingAs($a['user']);

        $this->getJson("/api/projects/{$projectTwo->id}/adjudication-cases/{$case->id}")->assertStatus(404);
        $this->postJson("/api/projects/{$projectTwo->id}/adjudication-cases/{$case->id}/advance-step")->assertStatus(404);
    }

    public function test_client_cannot_access_or_mutate_another_organisations_adjudication_documents(): void
    {
        $a = $this->makeOrgAndUser('a');
        $b = $this->makeOrgAndUser('b');
        $projectB = $this->makeProject($b['org'], $b['user']);
        $caseB = $this->makeCase($projectB, $b['user']);
        $documentB = AdjudicationDocument::create([
            'organization_id'      => $b['org']->id,
            'project_id'           => $projectB->id,
            'adjudication_case_id' => $caseB->id,
            'title'                => 'Confidential referral',
            'document_type'        => 'referral_submission',
        ]);

        Sanctum::actingAs($a['user']);

        $this->getJson("/api/projects/{$projectB->id}/adjudication-cases/{$caseB->id}/documents")->assertStatus(403);
        $this->postJson("/api/projects/{$projectB->id}/adjudication-cases/{$caseB->id}/documents", ['title' => 'x', 'document_type' => 'evidence'])->assertStatus(403);
        $this->deleteJson("/api/projects/{$projectB->id}/adjudication-documents/{$documentB->id}")->assertStatus(403);
    }

    public function test_client_cannot_access_or_mutate_another_organisations_adjudication_deadlines(): void
    {
        $a = $this->makeOrgAndUser('a');
        $b = $this->makeOrgAndUser('b');
        $projectB = $this->makeProject($b['org'], $b['user']);
        $caseB = $this->makeCase($projectB, $b['user']);
        $deadlineB = AdjudicationDeadline::create([
            'organization_id'      => $b['org']->id,
            'project_id'           => $projectB->id,
            'adjudication_case_id' => $caseB->id,
            'title'                => 'Response due',
            'deadline_type'        => 'response_deadline',
            'due_date'             => now()->addDays(14)->toDateString(),
            'status'               => 'upcoming',
        ]);

        Sanctum::actingAs($a['user']);

        $this->getJson("/api/projects/{$projectB->id}/adjudication-cases/{$caseB->id}/deadlines")->assertStatus(403);
        $this->postJson("/api/projects/{$projectB->id}/adjudication-cases/{$caseB->id}/deadlines", [
            'title' => 'Injected', 'deadline_type' => 'custom', 'due_date' => now()->toDateString(),
        ])->assertStatus(403);
        $this->putJson("/api/projects/{$projectB->id}/adjudication-deadlines/{$deadlineB->id}", ['title' => 'Hijacked'])->assertStatus(403);
        $this->postJson("/api/projects/{$projectB->id}/adjudication-deadlines/{$deadlineB->id}/complete")->assertStatus(403);
        $this->deleteJson("/api/projects/{$projectB->id}/adjudication-deadlines/{$deadlineB->id}")->assertStatus(403);

        $this->assertDatabaseHas('adjudication_deadlines', ['id' => $deadlineB->id, 'status' => 'upcoming']);
    }

    public function test_client_cannot_use_another_organisations_deadline_under_their_own_project_id(): void
    {
        // IDOR check: same-org project ID in the URL must not make a
        // different organisation's deadline reachable.
        $a = $this->makeOrgAndUser('a');
        $b = $this->makeOrgAndUser('b');
        $projectA = $this->makeProject($a['org'], $a['user']);
        $projectB = $this->makeProject($b['org'], $b['user']);
        $caseB = $this->makeCase($projectB, $b['user']);
        $deadlineB = AdjudicationDeadline::create([
            'organization_id'      => $b['org']->id,
            'project_id'           => $projectB->id,
            'adjudication_case_id' => $caseB->id,
            'title'                => 'Response due',
            'deadline_type'        => 'response_deadline',
            'due_date'             => now()->addDays(14)->toDateString(),
            'status'               => 'upcoming',
        ]);

        Sanctum::actingAs($a['user']);

        $this->putJson("/api/projects/{$projectA->id}/adjudication-deadlines/{$deadlineB->id}", ['title' => 'Hijacked'])->assertStatus(403);
    }
}
