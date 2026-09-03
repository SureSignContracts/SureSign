<?php

namespace Tests\Feature;

use App\Models\FileUpload;
use App\Models\FridayPack;
use App\Models\Organization;
use App\Models\PlantDeployment;
use App\Models\PlantItem;
use App\Models\Project;
use App\Models\User;
use App\Services\FridayPack\FridayPackGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Friday Pack Realignment, R1E.2D — Plant & Equipment. Mirrors
 * HsInspectionTest/SiteInductionTest's exact conventions. See PlantItem/
 * PlantDeployment models and FridayPackPlantEquipmentSourceService
 * docblocks for the architectural decisions this proves — PlantItem
 * (identity) and PlantDeployment (presence) remain strictly separate,
 * presence is authoritative via PlantDeployment only, and overlap
 * protection is proven separately (real MySQL) in
 * PlantDeploymentConcurrencyMysqlTest.
 */
class PlantEquipmentTest extends TestCase
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

    private function makeDeployment(PlantItem $item, Project $project, User $editor, array $overrides = []): PlantDeployment
    {
        return PlantDeployment::create(array_merge([
            'organization_id' => $project->organization_id, 'project_id' => $project->id,
            'plant_item_id' => $item->id, 'created_by' => $editor->id,
            'on_site_from' => self::MONDAY,
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

    // ── Plant Item ───────────────────────────────────────────────────────

    public function test_create_plant_item(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('pi1');
        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/plant-items", [
            'name' => 'Telehandler 01', 'type' => 'Telehandler',
        ])->assertCreated();
    }

    public function test_name_required(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('pi2');
        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/plant-items", ['type' => 'Telehandler'])->assertStatus(422);
    }

    public function test_type_required(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('pi3');
        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/plant-items", ['name' => 'X'])->assertStatus(422);
    }

    public function test_identifier_optional(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('pi4');
        $item = $this->makePlantItem($project, $editor);
        $this->assertNull($item->identifier);
    }

    public function test_owner_supplier_optional(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('pi5');
        $item = $this->makePlantItem($project, $editor);
        $this->assertNull($item->owner_supplier);
    }

    public function test_status_defaults_active(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('pi6');
        Sanctum::actingAs($editor);
        $response = $this->postJson("/api/projects/{$project->id}/plant-items", ['name' => 'X', 'type' => 'Y']);
        $this->assertSame('active', $response->json('status'));
    }

    public function test_status_inactive_accepted(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('pi7');
        $item = $this->makePlantItem($project, $editor, ['status' => 'inactive']);
        $this->assertSame('inactive', $item->status);
    }

    public function test_invalid_status_rejected(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('pi8');
        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/plant-items", [
            'name' => 'X', 'type' => 'Y', 'status' => 'off_hire',
        ])->assertStatus(422);
    }

    public function test_plant_item_soft_delete(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('pi9');
        $item = $this->makePlantItem($project, $editor);

        Sanctum::actingAs($editor);
        $this->deleteJson("/api/projects/{$project->id}/plant-items/{$item->id}")->assertNoContent();

        $this->assertSoftDeleted('plant_items', ['id' => $item->id]);
    }

    public function test_cannot_delete_item_with_open_deployment(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('pi10');
        $item = $this->makePlantItem($project, $editor);
        $this->makeDeployment($item, $project, $editor, ['off_site_at' => null]);

        Sanctum::actingAs($editor);
        $this->deleteJson("/api/projects/{$project->id}/plant-items/{$item->id}")->assertStatus(409);
        $this->assertDatabaseHas('plant_items', ['id' => $item->id, 'deleted_at' => null]);
    }

    public function test_historical_closed_deployments_remain_after_item_soft_delete(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('pi11');
        $item = $this->makePlantItem($project, $editor);
        $deployment = $this->makeDeployment($item, $project, $editor, ['off_site_at' => '2026-08-20']);

        Sanctum::actingAs($editor);
        $this->deleteJson("/api/projects/{$project->id}/plant-items/{$item->id}")->assertNoContent();

        $this->assertDatabaseHas('plant_deployments', ['id' => $deployment->id]);
    }

    public function test_no_arbitrary_uniqueness_on_name_type(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('pi12');
        $this->makePlantItem($project, $editor, ['name' => 'Telehandler', 'type' => 'Telehandler']);
        $second = $this->makePlantItem($project, $editor, ['name' => 'Telehandler', 'type' => 'Telehandler']);
        $this->assertNotNull($second->id);
    }

    // ── Tenancy ──────────────────────────────────────────────────────────

    public function test_cross_org_rejected(): void
    {
        [, , $projectA] = $this->makeOrgProjectAndEditor('t1a');
        [, $editorB, $projectB] = $this->makeOrgProjectAndEditor('t1b');
        $itemA = $this->makePlantItem($projectA, $editorB);

        Sanctum::actingAs($editorB);
        $this->getJson("/api/projects/{$projectB->id}/plant-items/{$itemA->id}")->assertStatus(403);
    }

    public function test_wrong_project_parent_rejected(): void
    {
        [$org, $editor, $projectA] = $this->makeOrgProjectAndEditor('t2a');
        $projectB = Project::create(['organization_id' => $org->id, 'created_by' => $editor->id, 'name' => 'Project B']);
        $itemA = $this->makePlantItem($projectA, $editor);

        Sanctum::actingAs($editor);
        $this->getJson("/api/projects/{$projectB->id}/plant-items/{$itemA->id}")->assertStatus(404);
    }

    public function test_injected_organization_id_ignored(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('t3');
        [$otherOrg] = $this->makeOrgProjectAndEditor('t3other');

        Sanctum::actingAs($editor);
        $response = $this->postJson("/api/projects/{$project->id}/plant-items", [
            'name' => 'X', 'type' => 'Y', 'organization_id' => $otherOrg->id,
        ]);
        $this->assertSame($project->organization_id, $response->json('organization_id'));
    }

    public function test_plant_item_cannot_move_project(): void
    {
        [$org, $editor, $projectA] = $this->makeOrgProjectAndEditor('t4a');
        $projectB = Project::create(['organization_id' => $org->id, 'created_by' => $editor->id, 'name' => 'Project B']);
        $item = $this->makePlantItem($projectA, $editor);

        Sanctum::actingAs($editor);
        $this->putJson("/api/projects/{$projectA->id}/plant-items/{$item->id}", [
            'name' => 'Still here', 'project_id' => $projectB->id,
        ])->assertOk();

        $this->assertSame($projectA->id, $item->fresh()->project_id);
    }

    public function test_deployment_cannot_link_cross_project_plant_item(): void
    {
        [$org, $editor, $projectA] = $this->makeOrgProjectAndEditor('t5a');
        $projectB = Project::create(['organization_id' => $org->id, 'created_by' => $editor->id, 'name' => 'Project B']);
        $itemA = $this->makePlantItem($projectA, $editor);

        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$projectB->id}/plant-items/{$itemA->id}/deployments", [
            'on_site_from' => self::MONDAY,
        ])->assertStatus(404);
    }

    public function test_deployment_cannot_move_parent_on_update(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('t6');
        $itemA = $this->makePlantItem($project, $editor, ['name' => 'Item A']);
        $itemB = $this->makePlantItem($project, $editor, ['name' => 'Item B']);
        $deployment = $this->makeDeployment($itemA, $project, $editor);

        Sanctum::actingAs($editor);
        // plant_item_id is not part of the update validation set at all —
        // it is silently ignored, never accepted.
        $this->putJson(
            "/api/projects/{$project->id}/plant-items/{$itemA->id}/deployments/{$deployment->id}",
            ['notes' => 'still here', 'plant_item_id' => $itemB->id]
        )->assertOk();

        $this->assertSame($itemA->id, $deployment->fresh()->plant_item_id);
    }

    // ── Deployment domain ────────────────────────────────────────────────

    public function test_create_deployment(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('dp1');
        $item = $this->makePlantItem($project, $editor);

        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/plant-items/{$item->id}/deployments", [
            'on_site_from' => self::MONDAY,
        ])->assertCreated();
    }

    public function test_on_site_from_required(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('dp2');
        $item = $this->makePlantItem($project, $editor);

        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/plant-items/{$item->id}/deployments", [])->assertStatus(422);
    }

    public function test_off_site_at_nullable(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('dp3');
        $item = $this->makePlantItem($project, $editor);
        $deployment = $this->makeDeployment($item, $project, $editor);
        $this->assertNull($deployment->off_site_at);
    }

    public function test_off_site_at_before_on_site_from_rejected(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('dp4');
        $item = $this->makePlantItem($project, $editor);

        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/plant-items/{$item->id}/deployments", [
            'on_site_from' => '2026-08-20', 'off_site_at' => '2026-08-10',
        ])->assertStatus(422);
    }

    public function test_open_deployment_supported(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('dp5');
        $item = $this->makePlantItem($project, $editor);
        $deployment = $this->makeDeployment($item, $project, $editor, ['off_site_at' => null]);
        $this->assertTrue($deployment->isOpen());
    }

    public function test_closed_deployment_supported(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('dp6');
        $item = $this->makePlantItem($project, $editor);
        $deployment = $this->makeDeployment($item, $project, $editor, ['off_site_at' => '2026-08-20']);
        $this->assertFalse($deployment->isOpen());
    }

    public function test_non_overlapping_sequential_deployments_allowed(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('dp7');
        $item = $this->makePlantItem($project, $editor);

        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/plant-items/{$item->id}/deployments", [
            'on_site_from' => '2026-08-03', 'off_site_at' => '2026-08-20',
        ])->assertCreated();
        $this->postJson("/api/projects/{$project->id}/plant-items/{$item->id}/deployments", [
            'on_site_from' => '2026-08-27', 'off_site_at' => null,
        ])->assertCreated();

        $this->assertSame(2, PlantDeployment::where('plant_item_id', $item->id)->count());
    }

    public function test_overlapping_closed_ranges_rejected(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('dp8');
        $item = $this->makePlantItem($project, $editor);
        $this->makeDeployment($item, $project, $editor, ['on_site_from' => '2026-08-03', 'off_site_at' => '2026-08-20']);

        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/plant-items/{$item->id}/deployments", [
            'on_site_from' => '2026-08-15', 'off_site_at' => '2026-08-25',
        ])->assertStatus(409);
    }

    public function test_second_open_deployment_rejected(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('dp9');
        $item = $this->makePlantItem($project, $editor);
        $this->makeDeployment($item, $project, $editor, ['on_site_from' => '2026-08-03', 'off_site_at' => null]);

        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/plant-items/{$item->id}/deployments", [
            'on_site_from' => '2026-08-10',
        ])->assertStatus(409);
    }

    public function test_open_deployment_overlapping_dated_interval_rejected(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('dp10');
        $item = $this->makePlantItem($project, $editor);
        // Existing OPEN deployment from 03 Aug.
        $this->makeDeployment($item, $project, $editor, ['on_site_from' => '2026-08-03', 'off_site_at' => null]);

        Sanctum::actingAs($editor);
        // A proposed dated interval entirely BEFORE the open deployment's
        // start does not overlap; one that reaches into/past it does.
        $this->postJson("/api/projects/{$project->id}/plant-items/{$item->id}/deployments", [
            'on_site_from' => '2026-07-01', 'off_site_at' => '2026-07-15',
        ])->assertCreated();

        $this->postJson("/api/projects/{$project->id}/plant-items/{$item->id}/deployments", [
            'on_site_from' => '2026-07-01', 'off_site_at' => '2026-08-05',
        ])->assertStatus(409);
    }

    public function test_update_rechecks_overlap_excluding_self(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('dp11');
        $item = $this->makePlantItem($project, $editor);
        $deployment = $this->makeDeployment($item, $project, $editor, ['on_site_from' => '2026-08-03', 'off_site_at' => '2026-08-10']);

        Sanctum::actingAs($editor);
        // Updating the SAME deployment's own dates must not conflict with itself.
        $this->putJson(
            "/api/projects/{$project->id}/plant-items/{$item->id}/deployments/{$deployment->id}",
            ['on_site_from' => '2026-08-04', 'off_site_at' => '2026-08-11']
        )->assertOk();
    }

    public function test_update_still_rejects_overlap_with_another_deployment(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('dp12');
        $item = $this->makePlantItem($project, $editor);
        $this->makeDeployment($item, $project, $editor, ['on_site_from' => '2026-08-03', 'off_site_at' => '2026-08-10']);
        $deploymentB = $this->makeDeployment($item, $project, $editor, ['on_site_from' => '2026-08-20', 'off_site_at' => '2026-08-25']);

        Sanctum::actingAs($editor);
        $this->putJson(
            "/api/projects/{$project->id}/plant-items/{$item->id}/deployments/{$deploymentB->id}",
            ['on_site_from' => '2026-08-09']
        )->assertStatus(409);
    }

    public function test_deployment_soft_delete(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('dp13');
        $item = $this->makePlantItem($project, $editor);
        $deployment = $this->makeDeployment($item, $project, $editor);

        Sanctum::actingAs($editor);
        $this->deleteJson("/api/projects/{$project->id}/plant-items/{$item->id}/deployments/{$deployment->id}")->assertNoContent();

        $this->assertSoftDeleted('plant_deployments', ['id' => $deployment->id]);
    }

    public function test_no_attachment_routes_on_deployment(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('dp14');
        $item = $this->makePlantItem($project, $editor);
        $deployment = $this->makeDeployment($item, $project, $editor);

        Sanctum::actingAs($editor);
        $this->getJson("/api/projects/{$project->id}/plant-items/{$item->id}/deployments/{$deployment->id}/attachments")->assertStatus(404);
    }

    // ── Attachments (Plant Item only) ───────────────────────────────────

    public function test_plant_item_evidence_upload(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('a1');
        $item = $this->makePlantItem($project, $editor);

        Sanctum::actingAs($editor);
        $response = $this->postJson("/api/projects/{$project->id}/plant-items/{$item->id}/attachments", ['file' => $this->fakeImage()]);

        $response->assertCreated();
        $this->assertDatabaseHas('file_uploads', ['attachable_type' => PlantItem::class, 'attachable_id' => $item->id]);
    }

    public function test_list_response_redacted(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('a2');
        $item = $this->makePlantItem($project, $editor);

        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/plant-items/{$item->id}/attachments", ['file' => $this->fakeImage()])->assertCreated();
        $listResponse = $this->getJson("/api/projects/{$project->id}/plant-items/{$item->id}/attachments");

        $this->assertArrayNotHasKey('disk', $listResponse->json()[0]);
        $this->assertArrayNotHasKey('file_path', $listResponse->json()[0]);
        $this->assertArrayHasKey('original_name', $listResponse->json()[0]);
    }

    public function test_upload_response_redacted(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('a3');
        $item = $this->makePlantItem($project, $editor);

        Sanctum::actingAs($editor);
        $response = $this->postJson("/api/projects/{$project->id}/plant-items/{$item->id}/attachments", ['file' => $this->fakeImage()]);

        $response->assertJsonMissingPath('disk');
        $response->assertJsonMissingPath('file_path');
    }

    public function test_no_disk_file_path_anywhere(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('a4');
        $item = $this->makePlantItem($project, $editor);

        Sanctum::actingAs($editor);
        $upload = $this->postJson("/api/projects/{$project->id}/plant-items/{$item->id}/attachments", ['file' => $this->fakeImage()])->json();

        $this->assertArrayHasKey('id', $upload);
        $this->assertArrayNotHasKey('disk', $upload);
    }

    public function test_cross_record_attachment_access_protected(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('a5');
        $itemA = $this->makePlantItem($project, $editor, ['name' => 'A']);
        $itemB = $this->makePlantItem($project, $editor, ['name' => 'B']);

        Sanctum::actingAs($editor);
        $upload = $this->postJson("/api/projects/{$project->id}/plant-items/{$itemA->id}/attachments", ['file' => $this->fakeImage()])->json();

        $this->assertSame($itemA->id, FileUpload::find($upload['id'])->attachable_id);
        $this->assertNotSame($itemB->id, FileUpload::find($upload['id'])->attachable_id);
    }

    public function test_soft_deleted_item_cannot_expose_evidence(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('a6');
        $item = $this->makePlantItem($project, $editor);
        $item->delete();

        Sanctum::actingAs($editor);
        $this->getJson("/api/projects/{$project->id}/plant-items/{$item->id}/attachments")->assertStatus(404);
    }

    // ── Activity ─────────────────────────────────────────────────────────

    public function test_plant_item_create_update_delete_logged(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('af1');
        Sanctum::actingAs($editor);

        $this->postJson("/api/projects/{$project->id}/plant-items", ['name' => 'X', 'type' => 'Y'])->assertCreated();
        $this->assertDatabaseHas('project_activities', ['activity_type' => 'plant_item_added']);

        $item = PlantItem::first();
        $this->putJson("/api/projects/{$project->id}/plant-items/{$item->id}", ['name' => 'Z'])->assertOk();
        $this->assertDatabaseHas('project_activities', ['activity_type' => 'plant_item_updated']);

        $this->deleteJson("/api/projects/{$project->id}/plant-items/{$item->id}")->assertNoContent();
        $this->assertDatabaseHas('project_activities', ['activity_type' => 'plant_item_deleted']);
    }

    public function test_deployment_create_update_delete_logged(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('af2');
        $item = $this->makePlantItem($project, $editor);
        Sanctum::actingAs($editor);

        $this->postJson("/api/projects/{$project->id}/plant-items/{$item->id}/deployments", ['on_site_from' => self::MONDAY])->assertCreated();
        $this->assertDatabaseHas('project_activities', ['activity_type' => 'plant_deployment_added']);

        $deployment = PlantDeployment::first();
        $this->putJson("/api/projects/{$project->id}/plant-items/{$item->id}/deployments/{$deployment->id}", ['notes' => 'x'])->assertOk();
        $this->assertDatabaseHas('project_activities', ['activity_type' => 'plant_deployment_updated']);

        $this->deleteJson("/api/projects/{$project->id}/plant-items/{$item->id}/deployments/{$deployment->id}")->assertNoContent();
        $this->assertDatabaseHas('project_activities', ['activity_type' => 'plant_deployment_deleted']);
    }

    public function test_closing_deployment_logged(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('af3');
        $item = $this->makePlantItem($project, $editor);
        $deployment = $this->makeDeployment($item, $project, $editor, ['off_site_at' => null]);

        Sanctum::actingAs($editor);
        $this->putJson(
            "/api/projects/{$project->id}/plant-items/{$item->id}/deployments/{$deployment->id}",
            ['off_site_at' => '2026-08-20']
        )->assertOk();

        $this->assertDatabaseHas('project_activities', ['activity_type' => 'plant_deployment_closed']);
    }

    // ── Friday Pack ──────────────────────────────────────────────────────

    public function test_deployment_spanning_whole_week_included(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('fp1');
        $item = $this->makePlantItem($project, $editor);
        $this->makeDeployment($item, $project, $editor, ['on_site_from' => '2026-08-03', 'off_site_at' => '2026-09-18']);
        $pack = $this->generatePack($project, $editor);

        $this->assertCount(1, $pack->snapshot_json['sections']['plant_equipment']['items']);
    }

    public function test_open_deployment_beginning_before_week_included(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('fp2');
        $item = $this->makePlantItem($project, $editor);
        $this->makeDeployment($item, $project, $editor, ['on_site_from' => '2026-08-03', 'off_site_at' => null]);
        $pack = $this->generatePack($project, $editor);

        $this->assertCount(1, $pack->snapshot_json['sections']['plant_equipment']['items']);
    }

    public function test_deployment_ending_before_monday_excluded(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('fp3');
        $item = $this->makePlantItem($project, $editor);
        $this->makeDeployment($item, $project, $editor, ['on_site_from' => '2026-08-01', 'off_site_at' => '2026-08-14']);
        $pack = $this->generatePack($project, $editor);

        $this->assertSame([], $pack->snapshot_json['sections']['plant_equipment']['items']);
    }

    public function test_deployment_beginning_after_friday_excluded(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('fp4');
        $item = $this->makePlantItem($project, $editor);
        $this->makeDeployment($item, $project, $editor, ['on_site_from' => '2026-08-24', 'off_site_at' => null]);
        $pack = $this->generatePack($project, $editor);

        $this->assertSame([], $pack->snapshot_json['sections']['plant_equipment']['items']);
    }

    public function test_deployment_touching_monday_included(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('fp5');
        $item = $this->makePlantItem($project, $editor);
        $this->makeDeployment($item, $project, $editor, ['on_site_from' => '2026-08-10', 'off_site_at' => self::MONDAY]);
        $pack = $this->generatePack($project, $editor);

        $this->assertCount(1, $pack->snapshot_json['sections']['plant_equipment']['items']);
    }

    public function test_deployment_touching_friday_included(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('fp6');
        $item = $this->makePlantItem($project, $editor);
        $this->makeDeployment($item, $project, $editor, ['on_site_from' => self::FRIDAY, 'off_site_at' => null]);
        $pack = $this->generatePack($project, $editor);

        $this->assertCount(1, $pack->snapshot_json['sections']['plant_equipment']['items']);
    }

    public function test_same_item_two_non_overlapping_in_week_periods_preserves_both(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('fp7');
        $item = $this->makePlantItem($project, $editor);
        $this->makeDeployment($item, $project, $editor, ['on_site_from' => self::MONDAY, 'off_site_at' => '2026-08-18']);
        $this->makeDeployment($item, $project, $editor, ['on_site_from' => '2026-08-20', 'off_site_at' => self::FRIDAY]);
        $pack = $this->generatePack($project, $editor);

        $items = $pack->snapshot_json['sections']['plant_equipment']['items'];
        $this->assertCount(1, $items, 'One item, not two — the two periods belong to the same PlantItem.');
        $this->assertCount(2, $items[0]['presence_periods'], 'Both distinct periods must be preserved, never merged.');
    }

    public function test_plant_item_status_alone_never_creates_presence(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('fp8');
        $this->makePlantItem($project, $editor, ['status' => 'active']);
        $pack = $this->generatePack($project, $editor);

        $this->assertSame([], $pack->snapshot_json['sections']['plant_equipment']['items']);
    }

    public function test_no_inspection_requirement_to_appear(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('fp9');
        $item = $this->makePlantItem($project, $editor);
        $this->makeDeployment($item, $project, $editor);
        $pack = $this->generatePack($project, $editor);

        // No inspection-related record exists anywhere in this codebase yet
        // (Statutory Inspections is not implemented) — presence alone is
        // sufficient for this item to appear.
        $this->assertCount(1, $pack->snapshot_json['sections']['plant_equipment']['items']);
    }

    public function test_source_count_semantics_distinct_items_not_deployment_rows(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('fp10');
        $item = $this->makePlantItem($project, $editor);
        $this->makeDeployment($item, $project, $editor, ['on_site_from' => self::MONDAY, 'off_site_at' => '2026-08-18']);
        $this->makeDeployment($item, $project, $editor, ['on_site_from' => '2026-08-20', 'off_site_at' => self::FRIDAY]);
        $pack = $this->generatePack($project, $editor);

        $section = $pack->snapshot_json['sections']['plant_equipment'];
        $this->assertSame(1, $section['source_count'], 'source_count = distinct Plant Items, not deployment rows.');
        $this->assertSame(2, $section['deployment_source_count']);
    }

    public function test_no_fabricated_confirmed_none_text(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('fp11');
        $pack = $this->generatePack($project, $editor);

        $section = $pack->snapshot_json['sections']['plant_equipment'];
        $this->assertSame([], $section['items']);
        $this->assertSame(0, $section['source_count']);
        $json = strtolower(json_encode($section));
        $this->assertStringNotContainsString('no plant used', $json);
        $this->assertStringNotContainsString('plant-free', $json);
    }

    public function test_snapshot_freezes_item_identity_and_deployment_periods(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('fp12');
        $item = $this->makePlantItem($project, $editor, ['name' => 'Original Name']);
        $this->makeDeployment($item, $project, $editor, ['on_site_from' => self::MONDAY, 'off_site_at' => null]);
        $pack = $this->generatePack($project, $editor);

        $item->update(['name' => 'Renamed After Generation']);

        $frozenName = $pack->fresh()->snapshot_json['sections']['plant_equipment']['items'][0]['name'];
        $this->assertSame('Original Name', $frozenName);
    }

    public function test_draft_regeneration_refreshes(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('fp13');
        $pack = $this->generatePack($project, $editor);
        $item = $this->makePlantItem($project, $editor);
        $this->makeDeployment($item, $project, $editor);

        $pack = app(FridayPackGenerationService::class)->generate($project, self::FRIDAY, $editor);

        $this->assertCount(1, $pack->snapshot_json['sections']['plant_equipment']['items']);
    }

    public function test_approved_sent_immutable(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('fp14');
        $item = $this->makePlantItem($project, $editor);
        $this->makeDeployment($item, $project, $editor);
        $pack = $this->generatePack($project, $editor);
        $pack->update(['status' => 'ready_for_review']);
        $pack->update(['status' => 'approved']);

        $this->expectException(\App\Support\FridayPack\FridayPackImmutableException::class);
        $pack->update(['snapshot_json' => array_merge($pack->snapshot_json, ['sections' => []])]);
    }

    public function test_scheduler_captures_actual_presence_only(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('fp15');
        $item = $this->makePlantItem($project, $editor);
        $this->makeDeployment($item, $project, $editor, ['on_site_from' => self::MONDAY, 'off_site_at' => null]);

        $pack = app(FridayPackGenerationService::class)->generateScheduledIfMissing($project, self::FRIDAY);

        $this->assertCount(1, $pack->snapshot_json['sections']['plant_equipment']['items']);
    }

    public function test_schema_version_remains_2(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('fp16');
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
        [, $editor, $project] = $this->makeOrgProjectAndEditor('fp17');
        $pack = $this->generatePack($project, $editor);

        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/pdf")->assertStatus(201);
    }

    // ── Feature / Nav ────────────────────────────────────────────────────

    public function test_feature_gate_works(): void
    {
        $this->assertTrue(\App\Support\FeatureAvailability\FeatureAvailabilityRegistry::isValid('project.plant_equipment'));
    }

    public function test_health_and_safety_nav_lists_only_implemented_modules(): void
    {
        // R1E.2E added project.statutory_inspections — updated here to
        // match (StatutoryInspectionTest is now the authoritative version
        // of this check).
        $registry = \App\Support\FeatureAvailability\FeatureAvailabilityRegistry::ALL;
        $this->assertContains('project.site_inductions', $registry);
        $this->assertContains('project.incidents', $registry);
        $this->assertContains('project.hs_inspections', $registry);
        $this->assertContains('project.plant_equipment', $registry);
        $this->assertContains('project.statutory_inspections', $registry);
        $this->assertNotContains('project.health_safety', $registry);
    }
}
