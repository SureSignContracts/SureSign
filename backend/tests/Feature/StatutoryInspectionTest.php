<?php

namespace Tests\Feature;

use App\Models\DeliveryDocument;
use App\Models\FileUpload;
use App\Models\FridayPack;
use App\Models\Organization;
use App\Models\PlantDeployment;
use App\Models\PlantItem;
use App\Models\Project;
use App\Models\StatutoryInspection;
use App\Models\User;
use App\Services\FridayPack\FridayPackGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Friday Pack Realignment, R1E.2E — Statutory Inspections. Mirrors
 * PlantEquipmentTest/HsInspectionTest's exact conventions. See
 * StatutoryInspection/PlantLinkResolver/
 * FridayPackStatutoryInspectionSourceService's own docblocks for the
 * architectural decisions this proves — a StatutoryInspection is an
 * independent inspection/check EVENT record, never a DeliveryDocument,
 * never an HsInspection, never a PlantDeployment, and its optional plant
 * link is always re-resolved and validated server-side.
 */
class StatutoryInspectionTest extends TestCase
{
    use RefreshDatabase;

    private const FRIDAY = '2026-08-21';
    private const MONDAY = '2026-08-17';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function makeOrgProjectAndEditor(string $suffix, string $role = 'Client'): array
    {
        $org = Organization::create(['name' => "Org {$suffix}", 'slug' => "org-{$suffix}", 'timezone' => 'Europe/London']);
        $editor = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $editor->assignRole(Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']));
        $project = Project::create([
            'organization_id' => $org->id, 'created_by' => $editor->id, 'name' => "Project {$suffix}", 'code' => strtoupper($suffix),
        ]);

        return [$org, $editor, $project];
    }

    private function makePlantItem(Project $project, User $editor, array $overrides = []): PlantItem
    {
        return PlantItem::create(array_merge([
            'organization_id' => $project->organization_id, 'project_id' => $project->id, 'created_by' => $editor->id,
            'name' => 'Tower Crane A', 'type' => 'Tower Crane',
        ], $overrides));
    }

    private function makeDeliveryDocument(Project $project, User $editor, array $overrides = []): DeliveryDocument
    {
        return DeliveryDocument::create(array_merge([
            'organization_id' => $project->organization_id, 'project_id' => $project->id,
            'title' => 'A Document', 'category' => 'other', 'status' => 'required',
            'created_by' => $editor->id,
        ], $overrides));
    }

    private function makeInspection(Project $project, User $editor, array $overrides = []): StatutoryInspection
    {
        return StatutoryInspection::create(array_merge([
            'organization_id' => $project->organization_id, 'project_id' => $project->id, 'created_by' => $editor->id,
            'inspection_date' => self::MONDAY, 'inspection_type' => 'Scaffold Inspection',
            'subject_description' => 'North Elevation Scaffold', 'outcome' => 'satisfactory', 'status' => 'open',
        ], $overrides));
    }

    private function generatePack(Project $project, User $editor, string $friday = self::FRIDAY): FridayPack
    {
        return app(FridayPackGenerationService::class)->generate($project, $friday, $editor);
    }

    private function fakeImage(string $name = 'evidence.png'): UploadedFile
    {
        $file = UploadedFile::fake()->create($name, 10, 'image/png');
        file_put_contents($file->getPathname(), "\x89PNG\r\n\x1a\n" . str_repeat('x', 200));
        return $file;
    }

    // ── Domain ───────────────────────────────────────────────────────────

    public function test_create_standalone_non_plant_inspection(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('d1');
        Sanctum::actingAs($editor);
        $response = $this->postJson("/api/projects/{$project->id}/statutory-inspections", [
            'inspection_date' => self::MONDAY, 'inspection_type' => 'Scaffold Inspection',
            'subject_description' => 'North Elevation Scaffold', 'outcome' => 'satisfactory',
        ]);
        $response->assertCreated();
        $this->assertNull($response->json('plant_item_id'));
    }

    public function test_create_plant_linked_inspection(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('d2');
        $item = $this->makePlantItem($project, $editor);
        Sanctum::actingAs($editor);
        $response = $this->postJson("/api/projects/{$project->id}/statutory-inspections", [
            'inspection_date' => self::MONDAY, 'inspection_type' => 'Lifting Equipment Inspection',
            'subject_description' => 'Tower Crane A', 'plant_item_id' => $item->id, 'outcome' => 'satisfactory',
        ]);
        $response->assertCreated();
        $this->assertSame($item->id, $response->json('plant_item_id'));
    }

    public function test_inspection_date_required(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('d3');
        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/statutory-inspections", [
            'inspection_type' => 'X', 'subject_description' => 'Y', 'outcome' => 'satisfactory',
        ])->assertStatus(422);
    }

    public function test_inspection_type_required(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('d4');
        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/statutory-inspections", [
            'inspection_date' => self::MONDAY, 'subject_description' => 'Y', 'outcome' => 'satisfactory',
        ])->assertStatus(422);
    }

    public function test_subject_description_required(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('d5');
        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/statutory-inspections", [
            'inspection_date' => self::MONDAY, 'inspection_type' => 'X', 'outcome' => 'satisfactory',
        ])->assertStatus(422);
    }

    public function test_reference_optional(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('d6');
        $inspection = $this->makeInspection($project, $editor);
        $this->assertNull($inspection->reference);
    }

    public function test_notes_optional(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('d7');
        $inspection = $this->makeInspection($project, $editor);
        $this->assertNull($inspection->notes);
    }

    public function test_next_due_date_optional(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('d8');
        $inspection = $this->makeInspection($project, $editor);
        $this->assertNull($inspection->next_due_date);
    }

    public function test_no_automatic_next_due_calculation(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('d9');
        Sanctum::actingAs($editor);
        $response = $this->postJson("/api/projects/{$project->id}/statutory-inspections", [
            'inspection_date' => self::MONDAY, 'inspection_type' => 'Lifting Equipment Inspection',
            'subject_description' => 'Crane', 'outcome' => 'satisfactory',
        ]);
        // No next_due_date supplied — SureSign must never invent one.
        $this->assertNull($response->json('next_due_date'));
    }

    public function test_next_due_date_before_inspection_date_is_accepted(): void
    {
        // A migrated/imported record or a data-entry correction may
        // legitimately expose an already-past obligation — SureSign must
        // never enforce next_due_date >= inspection_date.
        [, $editor, $project] = $this->makeOrgProjectAndEditor('d10');
        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/statutory-inspections", [
            'inspection_date' => self::FRIDAY, 'inspection_type' => 'X', 'subject_description' => 'Y',
            'outcome' => 'satisfactory', 'next_due_date' => self::MONDAY,
        ])->assertCreated();
    }

    public function test_soft_delete(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('d11');
        $inspection = $this->makeInspection($project, $editor);

        Sanctum::actingAs($editor);
        $this->deleteJson("/api/projects/{$project->id}/statutory-inspections/{$inspection->id}")->assertNoContent();

        $this->assertSoftDeleted('statutory_inspections', ['id' => $inspection->id]);
    }

    // ── Plant relation ───────────────────────────────────────────────────

    public function test_plant_item_id_nullable(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('p1');
        $inspection = $this->makeInspection($project, $editor);
        $this->assertNull($inspection->plant_item_id);
    }

    public function test_same_project_plant_item_accepted(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('p2');
        $item = $this->makePlantItem($project, $editor);
        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/statutory-inspections", [
            'inspection_date' => self::MONDAY, 'inspection_type' => 'X', 'subject_description' => 'Y',
            'plant_item_id' => $item->id, 'outcome' => 'satisfactory',
        ])->assertCreated();
    }

    public function test_cross_project_plant_item_rejected(): void
    {
        [$org, $editor, $projectA] = $this->makeOrgProjectAndEditor('p3a');
        $projectB = Project::create(['organization_id' => $org->id, 'created_by' => $editor->id, 'name' => 'Project B']);
        $itemB = $this->makePlantItem($projectB, $editor);

        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$projectA->id}/statutory-inspections", [
            'inspection_date' => self::MONDAY, 'inspection_type' => 'X', 'subject_description' => 'Y',
            'plant_item_id' => $itemB->id, 'outcome' => 'satisfactory',
        ])->assertStatus(422);
    }

    public function test_cross_org_plant_item_rejected(): void
    {
        [, $editor, $projectA] = $this->makeOrgProjectAndEditor('p4a');
        [, , $projectB] = $this->makeOrgProjectAndEditor('p4b');
        $itemB = $this->makePlantItem($projectB, $editor);

        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$projectA->id}/statutory-inspections", [
            'inspection_date' => self::MONDAY, 'inspection_type' => 'X', 'subject_description' => 'Y',
            'plant_item_id' => $itemB->id, 'outcome' => 'satisfactory',
        ])->assertStatus(422);
    }

    public function test_soft_deleted_linked_plant_item_remains_historically_resolvable(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('p5');
        $item = $this->makePlantItem($project, $editor, ['name' => 'Crane X']);
        $inspection = $this->makeInspection($project, $editor, ['plant_item_id' => $item->id]);
        $item->delete();

        $this->assertSame('Crane X', $inspection->fresh()->plantItem->name);
    }

    public function test_new_record_cannot_select_soft_deleted_plant_item(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('p6');
        $item = $this->makePlantItem($project, $editor);
        $item->delete();

        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/statutory-inspections", [
            'inspection_date' => self::MONDAY, 'inspection_type' => 'X', 'subject_description' => 'Y',
            'plant_item_id' => $item->id, 'outcome' => 'satisfactory',
        ])->assertStatus(422);
    }

    public function test_linked_plant_item_hard_delete_protection(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('p7');
        $item = $this->makePlantItem($project, $editor);
        $this->makeInspection($project, $editor, ['plant_item_id' => $item->id]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        \DB::table('plant_items')->where('id', $item->id)->delete();
    }

    public function test_update_can_change_plant_link_to_valid_same_project_item(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('p8');
        $itemA = $this->makePlantItem($project, $editor, ['name' => 'A']);
        $itemB = $this->makePlantItem($project, $editor, ['name' => 'B']);
        $inspection = $this->makeInspection($project, $editor, ['plant_item_id' => $itemA->id]);

        Sanctum::actingAs($editor);
        $this->putJson("/api/projects/{$project->id}/statutory-inspections/{$inspection->id}", [
            'plant_item_id' => $itemB->id,
        ])->assertOk();

        $this->assertSame($itemB->id, $inspection->fresh()->plant_item_id);
    }

    public function test_update_omitting_plant_item_id_never_disturbs_existing_link(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('p9');
        $item = $this->makePlantItem($project, $editor);
        $inspection = $this->makeInspection($project, $editor, ['plant_item_id' => $item->id]);
        $item->delete();

        Sanctum::actingAs($editor);
        // notes-only update — plant_item_id key genuinely absent from the
        // payload, so the already-soft-deleted link must never be
        // re-validated or disturbed.
        $this->putJson("/api/projects/{$project->id}/statutory-inspections/{$inspection->id}", [
            'notes' => 'context',
        ])->assertOk();

        $this->assertSame($item->id, $inspection->fresh()->plant_item_id);
    }

    // ── Outcome / status ─────────────────────────────────────────────────

    public function test_satisfactory_accepted(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('o1');
        $inspection = $this->makeInspection($project, $editor, ['outcome' => 'satisfactory']);
        $this->assertSame('satisfactory', $inspection->outcome);
    }

    public function test_issues_found_accepted(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('o2');
        $inspection = $this->makeInspection($project, $editor, ['outcome' => 'issues_found']);
        $this->assertSame('issues_found', $inspection->outcome);
    }

    public function test_invalid_outcome_rejected(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('o3');
        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/statutory-inspections", [
            'inspection_date' => self::MONDAY, 'inspection_type' => 'X', 'subject_description' => 'Y',
            'outcome' => 'compliant',
        ])->assertStatus(422);
    }

    public function test_open_default_and_accepted(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('o4');
        Sanctum::actingAs($editor);
        $response = $this->postJson("/api/projects/{$project->id}/statutory-inspections", [
            'inspection_date' => self::MONDAY, 'inspection_type' => 'X', 'subject_description' => 'Y', 'outcome' => 'satisfactory',
        ]);
        $this->assertSame('open', $response->json('status'));
    }

    public function test_closed_accepted(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('o5');
        $inspection = $this->makeInspection($project, $editor, ['status' => 'closed']);
        $this->assertSame('closed', $inspection->status);
    }

    public function test_invalid_status_rejected(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('o6');
        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/statutory-inspections", [
            'inspection_date' => self::MONDAY, 'inspection_type' => 'X', 'subject_description' => 'Y',
            'outcome' => 'satisfactory', 'status' => 'overdue',
        ])->assertStatus(422);
    }

    public function test_issues_found_plus_closed_valid(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('o7');
        $inspection = $this->makeInspection($project, $editor, ['outcome' => 'issues_found', 'status' => 'closed']);
        $this->assertSame('issues_found', $inspection->outcome);
        $this->assertSame('closed', $inspection->status);
    }

    public function test_satisfactory_plus_open_valid(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('o8');
        $inspection = $this->makeInspection($project, $editor, ['outcome' => 'satisfactory', 'status' => 'open']);
        $this->assertSame('satisfactory', $inspection->outcome);
        $this->assertSame('open', $inspection->status);
    }

    public function test_outcome_does_not_rewrite_status(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('o9');
        $inspection = $this->makeInspection($project, $editor, ['status' => 'closed']);

        Sanctum::actingAs($editor);
        $this->putJson("/api/projects/{$project->id}/statutory-inspections/{$inspection->id}", [
            'outcome' => 'issues_found',
        ])->assertOk();

        $this->assertSame('closed', $inspection->fresh()->status);
    }

    public function test_status_does_not_rewrite_outcome(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('o10');
        $inspection = $this->makeInspection($project, $editor, ['outcome' => 'satisfactory']);

        Sanctum::actingAs($editor);
        $this->putJson("/api/projects/{$project->id}/statutory-inspections/{$inspection->id}", [
            'status' => 'closed',
        ])->assertOk();

        $this->assertSame('satisfactory', $inspection->fresh()->outcome);
    }

    // ── Tenancy ──────────────────────────────────────────────────────────

    public function test_cross_org_inspection_access_rejected(): void
    {
        [, , $projectA] = $this->makeOrgProjectAndEditor('t1a');
        [, $editorB, $projectB] = $this->makeOrgProjectAndEditor('t1b');
        $inspectionA = $this->makeInspection($projectA, $editorB);

        Sanctum::actingAs($editorB);
        $this->getJson("/api/projects/{$projectB->id}/statutory-inspections/{$inspectionA->id}")->assertStatus(403);
    }

    public function test_wrong_project_parent_rejected(): void
    {
        [$org, $editor, $projectA] = $this->makeOrgProjectAndEditor('t2a');
        $projectB = Project::create(['organization_id' => $org->id, 'created_by' => $editor->id, 'name' => 'Project B']);
        $inspectionA = $this->makeInspection($projectA, $editor);

        Sanctum::actingAs($editor);
        $this->getJson("/api/projects/{$projectB->id}/statutory-inspections/{$inspectionA->id}")->assertStatus(404);
    }

    public function test_injected_organization_id_ignored(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('t3');
        [$otherOrg] = $this->makeOrgProjectAndEditor('t3other');

        Sanctum::actingAs($editor);
        $response = $this->postJson("/api/projects/{$project->id}/statutory-inspections", [
            'inspection_date' => self::MONDAY, 'inspection_type' => 'X', 'subject_description' => 'Y',
            'outcome' => 'satisfactory', 'organization_id' => $otherOrg->id,
        ]);
        $this->assertSame($project->organization_id, $response->json('organization_id'));
    }

    public function test_inspection_cannot_move_project(): void
    {
        [$org, $editor, $projectA] = $this->makeOrgProjectAndEditor('t4a');
        $projectB = Project::create(['organization_id' => $org->id, 'created_by' => $editor->id, 'name' => 'Project B']);
        $inspection = $this->makeInspection($projectA, $editor);

        Sanctum::actingAs($editor);
        $this->putJson("/api/projects/{$projectA->id}/statutory-inspections/{$inspection->id}", [
            'subject_description' => 'Still here', 'project_id' => $projectB->id,
        ])->assertOk();

        $this->assertSame($projectA->id, $inspection->fresh()->project_id);
    }

    // ── Attachments ──────────────────────────────────────────────────────

    public function test_upload_evidence(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('a1');
        $inspection = $this->makeInspection($project, $editor);

        Sanctum::actingAs($editor);
        $response = $this->postJson("/api/projects/{$project->id}/statutory-inspections/{$inspection->id}/attachments", ['file' => $this->fakeImage()]);

        $response->assertCreated();
        $this->assertDatabaseHas('file_uploads', ['attachable_type' => StatutoryInspection::class, 'attachable_id' => $inspection->id]);
    }

    public function test_list_safe_metadata(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('a2');
        $inspection = $this->makeInspection($project, $editor);

        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/statutory-inspections/{$inspection->id}/attachments", ['file' => $this->fakeImage()])->assertCreated();
        $listResponse = $this->getJson("/api/projects/{$project->id}/statutory-inspections/{$inspection->id}/attachments");

        $this->assertArrayHasKey('original_name', $listResponse->json()[0]);
        $this->assertArrayNotHasKey('disk', $listResponse->json()[0]);
    }

    public function test_upload_response_safe_metadata(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('a3');
        $inspection = $this->makeInspection($project, $editor);

        Sanctum::actingAs($editor);
        $response = $this->postJson("/api/projects/{$project->id}/statutory-inspections/{$inspection->id}/attachments", ['file' => $this->fakeImage()]);

        $response->assertJsonMissingPath('disk');
        $response->assertJsonMissingPath('file_path');
    }

    public function test_no_disk_file_path_anywhere(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('a4');
        $inspection = $this->makeInspection($project, $editor);

        Sanctum::actingAs($editor);
        $upload = $this->postJson("/api/projects/{$project->id}/statutory-inspections/{$inspection->id}/attachments", ['file' => $this->fakeImage()])->json();

        $this->assertArrayHasKey('id', $upload);
        $this->assertArrayNotHasKey('disk', $upload);
        $this->assertArrayNotHasKey('file_path', $upload);
    }

    public function test_cross_record_attachment_protected(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('a5');
        $inspectionA = $this->makeInspection($project, $editor, ['subject_description' => 'A']);
        $inspectionB = $this->makeInspection($project, $editor, ['subject_description' => 'B']);

        Sanctum::actingAs($editor);
        $upload = $this->postJson("/api/projects/{$project->id}/statutory-inspections/{$inspectionA->id}/attachments", ['file' => $this->fakeImage()])->json();

        $this->assertSame($inspectionA->id, FileUpload::find($upload['id'])->attachable_id);
        $this->assertNotSame($inspectionB->id, FileUpload::find($upload['id'])->attachable_id);
    }

    public function test_soft_deleted_inspection_attachment_route_unavailable(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('a6');
        $inspection = $this->makeInspection($project, $editor);
        $inspection->delete();

        Sanctum::actingAs($editor);
        $this->getJson("/api/projects/{$project->id}/statutory-inspections/{$inspection->id}/attachments")->assertStatus(404);
    }

    // ── Activity ─────────────────────────────────────────────────────────

    public function test_create_logged(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('ac1');
        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/statutory-inspections", [
            'inspection_date' => self::MONDAY, 'inspection_type' => 'X', 'subject_description' => 'Y', 'outcome' => 'satisfactory',
        ])->assertCreated();
        $this->assertDatabaseHas('project_activities', ['activity_type' => 'statutory_inspection_added']);
    }

    public function test_update_logged(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('ac2');
        $inspection = $this->makeInspection($project, $editor);

        Sanctum::actingAs($editor);
        $this->putJson("/api/projects/{$project->id}/statutory-inspections/{$inspection->id}", ['notes' => 'x'])->assertOk();
        $this->assertDatabaseHas('project_activities', ['activity_type' => 'statutory_inspection_updated']);
    }

    public function test_status_change_logged(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('ac3');
        $inspection = $this->makeInspection($project, $editor, ['status' => 'open']);

        Sanctum::actingAs($editor);
        $this->putJson("/api/projects/{$project->id}/statutory-inspections/{$inspection->id}", ['status' => 'closed'])->assertOk();
        $this->assertDatabaseHas('project_activities', ['activity_type' => 'statutory_inspection_status_changed']);
    }

    public function test_delete_logged(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('ac4');
        $inspection = $this->makeInspection($project, $editor);

        Sanctum::actingAs($editor);
        $this->deleteJson("/api/projects/{$project->id}/statutory-inspections/{$inspection->id}")->assertNoContent();
        $this->assertDatabaseHas('project_activities', ['activity_type' => 'statutory_inspection_deleted']);
    }

    // ── Friday Pack ──────────────────────────────────────────────────────

    public function test_mon_fri_inspection_selected(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('fp1');
        $this->makeInspection($project, $editor, ['inspection_date' => '2026-08-19']);
        $pack = $this->generatePack($project, $editor);

        $this->assertCount(1, $pack->snapshot_json['sections']['permits_inspections']['inspections']);
    }

    public function test_saturday_excluded(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('fp2');
        $this->makeInspection($project, $editor, ['inspection_date' => '2026-08-22']);
        $pack = $this->generatePack($project, $editor);

        $this->assertSame([], $pack->snapshot_json['sections']['permits_inspections']['inspections']);
    }

    public function test_non_plant_inspection_represented_correctly(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('fp3');
        $this->makeInspection($project, $editor, ['inspection_date' => self::MONDAY, 'subject_description' => 'North Scaffold']);
        $pack = $this->generatePack($project, $editor);

        $item = $pack->snapshot_json['sections']['permits_inspections']['inspections'][0];
        $this->assertSame('North Scaffold', $item['subject_description']);
        $this->assertNull($item['plant']);
    }

    public function test_plant_linked_inspection_represented_correctly(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('fp4');
        $plantItem = $this->makePlantItem($project, $editor, ['name' => 'Crane A', 'identifier' => 'CR-001']);
        $this->makeInspection($project, $editor, ['inspection_date' => self::MONDAY, 'plant_item_id' => $plantItem->id]);
        $pack = $this->generatePack($project, $editor);

        $item = $pack->snapshot_json['sections']['permits_inspections']['inspections'][0];
        $this->assertSame('Crane A', $item['plant']['name']);
        $this->assertSame('CR-001', $item['plant']['identifier']);
    }

    public function test_plant_name_identifier_frozen(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('fp5');
        $plantItem = $this->makePlantItem($project, $editor, ['name' => 'Original Name', 'identifier' => 'ORIG-1']);
        $this->makeInspection($project, $editor, ['inspection_date' => self::MONDAY, 'plant_item_id' => $plantItem->id]);
        $pack = $this->generatePack($project, $editor);

        $plantItem->update(['name' => 'Renamed', 'identifier' => 'NEW-2']);

        $frozen = $pack->fresh()->snapshot_json['sections']['permits_inspections']['inspections'][0]['plant'];
        $this->assertSame('Original Name', $frozen['name']);
        $this->assertSame('ORIG-1', $frozen['identifier']);
    }

    public function test_source_count_correct(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('fp6');
        $this->makeInspection($project, $editor, ['inspection_date' => self::MONDAY]);
        $this->makeInspection($project, $editor, ['inspection_date' => self::FRIDAY]);
        $pack = $this->generatePack($project, $editor);

        $this->assertSame(2, $pack->snapshot_json['sections']['permits_inspections']['inspection_source_count']);
    }

    public function test_permit_data_remains_unchanged(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('fp7');
        $this->makeDeliveryDocument($project, $editor, ['category' => 'permit', 'title' => 'Hot Works Permit']);
        $pack = $this->generatePack($project, $editor);

        $section = $pack->snapshot_json['sections']['permits_inspections'];
        $this->assertCount(1, $section['permits']);
        $this->assertSame('Hot Works Permit', $section['permits'][0]['title']);
        $this->assertSame(1, $section['permit_source_count']);
    }

    public function test_no_fabricated_compliance_language(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('fp8');
        $pack = $this->generatePack($project, $editor);

        $section = $pack->snapshot_json['sections']['permits_inspections'];
        $this->assertSame([], $section['inspections']);
        $this->assertSame(0, $section['inspection_source_count']);
        $json = strtolower(json_encode($section));
        $this->assertStringNotContainsString('no inspections required', $json);
        $this->assertStringNotContainsString('all inspections current', $json);
        $this->assertStringNotContainsString('everything compliant', $json);
        $this->assertStringNotContainsString('no statutory inspections due', $json);
    }

    // ── DeliveryDocument boundary ────────────────────────────────────────

    public function test_temporary_works_delivery_document_does_not_create_inspection(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('b1');
        $this->makeDeliveryDocument($project, $editor, ['category' => 'temporary_works', 'title' => 'Temp Works Design']);
        $pack = $this->generatePack($project, $editor);

        $this->assertSame([], $pack->snapshot_json['sections']['permits_inspections']['inspections']);
        $this->assertSame(0, StatutoryInspection::count());
    }

    public function test_permit_delivery_document_does_not_create_inspection(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('b2');
        $this->makeDeliveryDocument($project, $editor, ['category' => 'permit', 'title' => 'Hot Works Permit']);
        $pack = $this->generatePack($project, $editor);

        $this->assertSame([], $pack->snapshot_json['sections']['permits_inspections']['inspections']);
        $this->assertSame(0, StatutoryInspection::count());
    }

    public function test_rams_delivery_document_does_not_create_inspection(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('b3');
        $this->makeDeliveryDocument($project, $editor, ['category' => 'rams', 'title' => 'RAMS Doc']);
        $pack = $this->generatePack($project, $editor);

        $this->assertSame([], $pack->snapshot_json['sections']['permits_inspections']['inspections']);
        $this->assertSame(0, StatutoryInspection::count());
    }

    // ── Plant presence/evidence boundary ─────────────────────────────────

    public function test_plant_deployment_does_not_create_inspection(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('b4');
        $item = $this->makePlantItem($project, $editor);
        PlantDeployment::create([
            'organization_id' => $project->organization_id, 'project_id' => $project->id,
            'plant_item_id' => $item->id, 'created_by' => $editor->id, 'on_site_from' => self::MONDAY,
        ]);
        $pack = $this->generatePack($project, $editor);

        $this->assertSame([], $pack->snapshot_json['sections']['permits_inspections']['inspections']);
        $this->assertSame(0, StatutoryInspection::count());
    }

    public function test_plant_item_attachment_does_not_create_inspection(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('b5');
        $item = $this->makePlantItem($project, $editor);

        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/plant-items/{$item->id}/attachments", ['file' => $this->fakeImage()])->assertCreated();

        $this->assertSame(0, StatutoryInspection::count());
    }

    // ── Snapshot freeze / regeneration / scheduler ──────────────────────

    public function test_snapshot_freeze(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('fr1');
        $this->makeInspection($project, $editor, ['inspection_date' => self::MONDAY, 'inspection_type' => 'Original Type']);
        $pack = $this->generatePack($project, $editor);

        StatutoryInspection::first()->update(['inspection_type' => 'Renamed Type']);

        $frozen = $pack->fresh()->snapshot_json['sections']['permits_inspections']['inspections'][0]['inspection_type'];
        $this->assertSame('Original Type', $frozen);
    }

    public function test_draft_regeneration_refreshes(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('fr2');
        $pack = $this->generatePack($project, $editor);
        $this->makeInspection($project, $editor, ['inspection_date' => self::MONDAY]);

        $pack = app(FridayPackGenerationService::class)->generate($project, self::FRIDAY, $editor);

        $this->assertCount(1, $pack->snapshot_json['sections']['permits_inspections']['inspections']);
    }

    public function test_approved_sent_immutable(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('fr3');
        $this->makeInspection($project, $editor, ['inspection_date' => self::MONDAY]);
        $pack = $this->generatePack($project, $editor);
        $pack->update(['status' => 'ready_for_review']);
        $pack->update(['status' => 'approved']);

        $this->expectException(\App\Support\FridayPack\FridayPackImmutableException::class);
        $pack->update(['snapshot_json' => array_merge($pack->snapshot_json, ['sections' => []])]);
    }

    public function test_scheduler_captures_records_only(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('fr4');
        $this->makeInspection($project, $editor, ['inspection_date' => self::MONDAY]);

        $pack = app(FridayPackGenerationService::class)->generateScheduledIfMissing($project, self::FRIDAY);

        $this->assertCount(1, $pack->snapshot_json['sections']['permits_inspections']['inspections']);
    }

    public function test_schema_version_remains_2(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('fr5');
        $pack = $this->generatePack($project, $editor);
        $this->assertSame(2, $pack->snapshot_json['schema_version']);
    }

    /**
     * R1G.1-ACT (2026-09-03) — SCHEMA_TWO_LIVE is now `true`; a schema-2
     * pack's PDF now succeeds via the real route. Updated in place (was
     * "test_schema_2_pdf_still_blocked") — see project-context.md's
     * R1G.1-ACT entry.
     */
    public function test_schema_2_pdf_now_succeeds(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('fr6');
        $pack = $this->generatePack($project, $editor);

        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/pdf")->assertStatus(201);
    }

    // ── Feature / Nav ────────────────────────────────────────────────────

    public function test_feature_gate_works(): void
    {
        $this->assertTrue(\App\Support\FeatureAvailability\FeatureAvailabilityRegistry::isValid('project.statutory_inspections'));
    }

    public function test_health_and_safety_nav_exposes_all_implemented_modules_only(): void
    {
        $registry = \App\Support\FeatureAvailability\FeatureAvailabilityRegistry::ALL;
        $this->assertContains('project.site_inductions', $registry);
        $this->assertContains('project.incidents', $registry);
        $this->assertContains('project.hs_inspections', $registry);
        $this->assertContains('project.plant_equipment', $registry);
        $this->assertContains('project.statutory_inspections', $registry);
        $this->assertNotContains('project.health_safety', $registry);
    }
}
