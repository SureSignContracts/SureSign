<?php

namespace Tests\Feature;

use App\Models\AdjudicationCase;
use App\Models\AdjudicationDocument;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regression coverage for a confirmed tenant-isolation gap:
 * AdjudicationDocumentController previously had no organisation check at all
 * on index/store/destroy — any authenticated user of ANY organisation could
 * list, upload to, or delete another organisation's adjudication documents
 * simply by guessing/enumerating IDs.
 */
class AdjudicationDocumentTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrgProjectCaseWithUser(): array
    {
        static $n = 0;
        $n++;

        $org = Organization::create(['name' => "Org {$n}", 'slug' => "org-{$n}"]);

        $user = User::factory()->create(['organization_id' => $org->id]);

        $project = Project::create([
            'organization_id' => $org->id,
            'created_by'      => $user->id,
            'name'            => "Project {$n}",
        ]);

        $case = AdjudicationCase::create([
            'organization_id'  => $org->id,
            'project_id'       => $project->id,
            'created_by'       => $user->id,
            'case_number'      => "CASE-{$n}",
            'title'            => "Dispute {$n}",
            'dispute_type'     => 'payment',
            'claimant_name'    => 'Claimant',
            'respondent_name'  => 'Respondent',
        ]);

        return compact('org', 'user', 'project', 'case');
    }

    public function test_client_cannot_list_another_organisations_adjudication_documents(): void
    {
        $a = $this->makeOrgProjectCaseWithUser();
        $b = $this->makeOrgProjectCaseWithUser();

        AdjudicationDocument::create([
            'organization_id'      => $b['org']->id,
            'project_id'           => $b['project']->id,
            'adjudication_case_id' => $b['case']->id,
            'title'                => 'Confidential referral',
            'document_type'        => 'referral_submission',
        ]);

        Sanctum::actingAs($a['user']);

        $response = $this->getJson("/api/projects/{$b['project']->id}/adjudication-cases/{$b['case']->id}/documents");

        $response->assertStatus(403);
    }

    public function test_client_cannot_upload_to_another_organisations_adjudication_case(): void
    {
        $a = $this->makeOrgProjectCaseWithUser();
        $b = $this->makeOrgProjectCaseWithUser();

        Sanctum::actingAs($a['user']);

        $response = $this->postJson(
            "/api/projects/{$b['project']->id}/adjudication-cases/{$b['case']->id}/documents",
            ['title' => 'Injected doc', 'document_type' => 'evidence']
        );

        $response->assertStatus(403);
        $this->assertDatabaseMissing('adjudication_documents', ['title' => 'Injected doc']);
    }

    public function test_client_cannot_delete_another_organisations_adjudication_document(): void
    {
        $a = $this->makeOrgProjectCaseWithUser();
        $b = $this->makeOrgProjectCaseWithUser();

        $document = AdjudicationDocument::create([
            'organization_id'      => $b['org']->id,
            'project_id'           => $b['project']->id,
            'adjudication_case_id' => $b['case']->id,
            'title'                => 'Decision notice',
            'document_type'        => 'decision',
        ]);

        Sanctum::actingAs($a['user']);

        $response = $this->deleteJson("/api/projects/{$b['project']->id}/adjudication-documents/{$document->id}");

        $response->assertStatus(403);
        $this->assertDatabaseHas('adjudication_documents', ['id' => $document->id]);
    }

    public function test_client_can_upload_to_their_own_organisations_adjudication_case(): void
    {
        $a = $this->makeOrgProjectCaseWithUser();

        Sanctum::actingAs($a['user']);

        $file = UploadedFile::fake()->create('evidence.pdf', 10, 'application/pdf');
        // Give it a real PDF signature so the magic-byte check passes.
        file_put_contents($file->getPathname(), "%PDF-1.4\n" . str_repeat('x', 200));

        $response = $this->postJson(
            "/api/projects/{$a['project']->id}/adjudication-cases/{$a['case']->id}/documents",
            ['title' => 'My evidence', 'document_type' => 'evidence', 'file' => $file]
        );

        $response->assertStatus(201);
        $this->assertDatabaseHas('adjudication_documents', [
            'title'                => 'My evidence',
            'organization_id'      => $a['org']->id,
            'adjudication_case_id' => $a['case']->id,
        ]);
    }

    public function test_uploading_a_php_file_to_an_adjudication_case_is_rejected(): void
    {
        $a = $this->makeOrgProjectCaseWithUser();

        Sanctum::actingAs($a['user']);

        $file = UploadedFile::fake()->createWithContent('shell.php', '<?php system($_GET["c"]); ?>');

        $response = $this->postJson(
            "/api/projects/{$a['project']->id}/adjudication-cases/{$a['case']->id}/documents",
            ['title' => 'Sneaky', 'document_type' => 'evidence', 'file' => $file]
        );

        $response->assertStatus(422);
        $this->assertDatabaseMissing('adjudication_documents', ['title' => 'Sneaky']);
    }
}
