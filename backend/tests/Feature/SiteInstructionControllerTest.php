<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectActivity;
use App\Models\SiteInstruction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Site Admin — Site Instructions. Covers tenant isolation, full CRUD, and
 * the activity-logging parity fix (SiteInstructionController previously
 * never recorded a ProjectActivity, unlike every sibling controller —
 * RFIs, Site Diaries, Meetings, EOT Requests).
 */
class SiteInstructionControllerTest extends TestCase
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

    public function test_store_creates_instruction_and_logs_activity(): void
    {
        ['org' => $org, 'user' => $user] = $this->makeOrgAndUser('a');
        $project = $this->makeProject($org, $user);

        Sanctum::actingAs($user);
        $response = $this->postJson("/api/projects/{$project->id}/site-instructions", [
            'title'       => 'Change render colour',
            'issued_date' => now()->toDateString(),
            'status'      => 'issued',
        ])->assertStatus(201);

        $this->assertDatabaseHas('site_instructions', [
            'id' => $response->json('id'), 'project_id' => $project->id, 'status' => 'issued',
        ]);

        $this->assertEquals(
            1,
            ProjectActivity::where('project_id', $project->id)->where('activity_type', 'site_instruction_issued')->count()
        );
    }

    public function test_update_status_change_logs_activity(): void
    {
        ['org' => $org, 'user' => $user] = $this->makeOrgAndUser('b');
        $project = $this->makeProject($org, $user);
        $instruction = SiteInstruction::create([
            'project_id' => $project->id, 'organization_id' => $org->id, 'created_by' => $user->id,
            'instruction_number' => 1, 'title' => 'Snag remedial works', 'status' => 'draft',
            'issued_date' => now()->toDateString(),
        ]);

        Sanctum::actingAs($user);
        $this->putJson("/api/projects/{$project->id}/site-instructions/{$instruction->id}", ['status' => 'issued'])->assertStatus(200);

        $this->assertEquals(
            1,
            ProjectActivity::where('project_id', $project->id)->where('activity_type', 'site_instruction_updated')->count()
        );
    }

    public function test_client_from_another_organisation_cannot_access_instruction(): void
    {
        ['org' => $orgA, 'user' => $userA] = $this->makeOrgAndUser('a');
        $projectA = $this->makeProject($orgA, $userA);
        $instruction = SiteInstruction::create([
            'project_id' => $projectA->id, 'organization_id' => $orgA->id, 'created_by' => $userA->id,
            'instruction_number' => 1, 'title' => 'Confidential instruction', 'status' => 'draft',
            'issued_date' => now()->toDateString(),
        ]);

        ['user' => $userB] = $this->makeOrgAndUser('b');
        Sanctum::actingAs($userB);

        $this->getJson("/api/projects/{$projectA->id}/site-instructions/{$instruction->id}")->assertStatus(403);
        $this->putJson("/api/projects/{$projectA->id}/site-instructions/{$instruction->id}", ['title' => 'Hijacked'])->assertStatus(403);
        $this->deleteJson("/api/projects/{$projectA->id}/site-instructions/{$instruction->id}")->assertStatus(403);
    }

    public function test_destroy_removes_instruction(): void
    {
        ['org' => $org, 'user' => $user] = $this->makeOrgAndUser('c');
        $project = $this->makeProject($org, $user);
        $instruction = SiteInstruction::create([
            'project_id' => $project->id, 'organization_id' => $org->id, 'created_by' => $user->id,
            'instruction_number' => 1, 'title' => 'Temporary works', 'status' => 'draft',
            'issued_date' => now()->toDateString(),
        ]);

        Sanctum::actingAs($user);
        $this->deleteJson("/api/projects/{$project->id}/site-instructions/{$instruction->id}")->assertStatus(204);

        $this->assertDatabaseMissing('site_instructions', ['id' => $instruction->id]);
    }
}
