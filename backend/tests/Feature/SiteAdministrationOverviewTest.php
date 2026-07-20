<?php

namespace Tests\Feature;

use App\Models\EotRequest;
use App\Models\MeetingMinutes;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Rfi;
use App\Models\SiteDiary;
use App\Models\SiteInstruction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Site Admin — GET /site-administration/overview.
 *
 * Covers: tenant isolation, per-module summary counts, and action_url
 * correctness for the org-wide RFI/Site Instruction/Site Diary/Meeting/EOT
 * Request browse surface.
 */
class SiteAdministrationOverviewTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrg(string $label): Organization
    {
        static $n = 0;
        $n++;

        return Organization::create([
            'name' => "{$label} Org {$n}", 'slug' => "org-{$label}-{$n}", 'timezone' => 'Europe/London',
        ]);
    }

    private function makeProject(Organization $org, User $user, array $overrides = []): Project
    {
        static $n = 0;
        $n++;

        return Project::create(array_merge([
            'organization_id' => $org->id, 'created_by' => $user->id,
            'name' => "Project {$n}", 'status' => 'active', 'currency' => 'GBP',
        ], $overrides));
    }

    public function test_client_only_receives_their_own_organisations_records(): void
    {
        $orgA = $this->makeOrg('a');
        $userA = User::factory()->create(['organization_id' => $orgA->id]);
        $projectA = $this->makeProject($orgA, $userA, ['name' => 'Alpha Tower']);
        Rfi::create([
            'project_id' => $projectA->id, 'organization_id' => $orgA->id, 'created_by' => $userA->id,
            'rfi_number' => 1, 'subject' => 'Alpha RFI', 'status' => 'open', 'raised_date' => now()->toDateString(),
        ]);

        $orgB = $this->makeOrg('b');
        $userB = User::factory()->create(['organization_id' => $orgB->id]);
        $projectB = $this->makeProject($orgB, $userB, ['name' => 'Beta Wharf']);
        Rfi::create([
            'project_id' => $projectB->id, 'organization_id' => $orgB->id, 'created_by' => $userB->id,
            'rfi_number' => 1, 'subject' => 'Beta RFI', 'status' => 'open', 'raised_date' => now()->toDateString(),
        ]);

        Sanctum::actingAs($userA);
        $response = $this->getJson('/api/site-administration/overview')->assertStatus(200);

        $subjects = collect($response->json('rfis'))->pluck('title')->all();
        $this->assertContains('Alpha RFI', $subjects);
        $this->assertNotContains('Beta RFI', $subjects);
        $this->assertEquals(1, $response->json('summary.rfis.total'));
    }

    public function test_summary_counts_and_rows_cover_every_module(): void
    {
        $org = $this->makeOrg('full');
        $user = User::factory()->create(['organization_id' => $org->id]);
        $project = $this->makeProject($org, $user);

        Rfi::create([
            'project_id' => $project->id, 'organization_id' => $org->id, 'created_by' => $user->id,
            'rfi_number' => 1, 'subject' => 'Foundation query', 'status' => 'open', 'raised_date' => now()->toDateString(),
        ]);
        SiteInstruction::create([
            'project_id' => $project->id, 'organization_id' => $org->id, 'created_by' => $user->id,
            'instruction_number' => 1, 'title' => 'Change tile spec', 'status' => 'issued', 'issued_date' => now()->toDateString(),
        ]);
        SiteDiary::create([
            'project_id' => $project->id, 'organization_id' => $org->id, 'created_by' => $user->id,
            'diary_date' => now()->toDateString(), 'status' => 'submitted',
        ]);
        MeetingMinutes::create([
            'project_id' => $project->id, 'organization_id' => $org->id, 'created_by' => $user->id,
            'meeting_number' => 1, 'title' => 'Progress meeting', 'meeting_date' => now()->toDateString(), 'status' => 'draft',
        ]);
        EotRequest::create([
            'project_id' => $project->id, 'organization_id' => $org->id, 'created_by' => $user->id,
            'eot_number' => 1, 'title' => 'Weather delay', 'notice_date' => now()->toDateString(),
            'status' => 'submitted', 'days_claimed' => 5,
        ]);

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/site-administration/overview')->assertStatus(200);

        $this->assertEquals(1, $response->json('summary.rfis.open'));
        $this->assertEquals(1, $response->json('summary.site_instructions.issued'));
        $this->assertEquals(1, $response->json('summary.site_diaries.submitted'));
        $this->assertEquals(1, $response->json('summary.meetings.draft'));
        $this->assertEquals(1, $response->json('summary.eot_requests.submitted'));

        $this->assertCount(1, $response->json('rfis'));
        $this->assertCount(1, $response->json('site_instructions'));
        $this->assertCount(1, $response->json('site_diaries'));
        $this->assertCount(1, $response->json('meetings'));
        $this->assertCount(1, $response->json('eot_requests'));

        $this->assertEquals(
            "/app/projects/{$project->id}/rfis",
            $response->json('rfis.0.action_url')
        );
        $this->assertEquals(
            "/app/projects/{$project->id}/notices",
            $response->json('site_instructions.0.action_url')
        );
    }
}
