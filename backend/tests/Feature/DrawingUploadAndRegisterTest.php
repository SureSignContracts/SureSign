<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Drawing;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The frontend's "Upload new file" path on Register Drawing (added
 * alongside the original "Select existing document" flow) is two
 * sequential calls to existing endpoints — POST /projects/{project}/documents
 * (with a file, creating a real Document Register entry, document_type
 * 'drawing') followed by the existing POST /projects/{project}/drawings
 * (document_id pointing at the one just created). Neither endpoint was
 * modified for this — this test exists because the combination is a new,
 * real code path the frontend now depends on, not because either endpoint
 * changed.
 */
class DrawingUploadAndRegisterTest extends TestCase
{
    use RefreshDatabase;

    public function test_uploading_a_new_document_and_registering_it_as_a_drawing_works_end_to_end(): void
    {
        Storage::fake('local');

        $org = Organization::create(['name' => 'Upload Register Org', 'slug' => 'upload-register-org', 'timezone' => 'Europe/London']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $project = Project::create(['organization_id' => $org->id, 'created_by' => $user->id, 'name' => 'Upload Register Project', 'status' => 'active']);

        Sanctum::actingAs($user);

        // A minimal but real PDF signature — FileSecurityService checks
        // actual magic bytes, not just the extension/mime hint.
        $pdfBytes = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF";
        $file = UploadedFile::fake()->createWithContent('S-204.pdf', $pdfBytes);

        $uploadResponse = $this->postJson("/api/projects/{$project->id}/documents", [
            'title' => 'Steel Connection Detail',
            'type' => 'drawing',
            'file' => $file,
        ]);

        $uploadResponse->assertStatus(201);
        $documentId = $uploadResponse->json('id');
        $this->assertDatabaseHas('documents', [
            'id' => $documentId, 'project_id' => $project->id, 'type' => 'drawing',
        ]);
        $this->assertNotNull($uploadResponse->json('file_path'));

        $registerResponse = $this->postJson("/api/projects/{$project->id}/drawings", [
            'drawing_number' => 'S-204',
            'title' => 'Steel Connection Detail',
            'document_id' => $documentId,
        ]);

        $registerResponse->assertStatus(201);
        $this->assertDatabaseHas('drawings', [
            'project_id' => $project->id, 'document_id' => $documentId, 'drawing_number' => 'S-204',
        ]);

        // The just-registered Document must no longer appear in the
        // eligible-documents list (Register Drawing's "existing document"
        // picker) — otherwise the same file could be registered twice.
        $eligible = $this->getJson("/api/projects/{$project->id}/drawings/eligible-documents")->json('data');
        $this->assertEmpty(array_filter($eligible, fn ($d) => $d['id'] === $documentId));
    }
}
