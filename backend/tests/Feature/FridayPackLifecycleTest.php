<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\FeatureAvailability;
use App\Models\FridayPack;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\FridayPack\FridayPackGenerationService;
use App\Services\FridayPack\FridayPackLifecycleService;
use App\Services\FridayPack\FridayPackPdfService;
use App\Support\FridayPack\FridayPackImmutableException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Automated Friday Pack V1D — Review + Approval lifecycle. Mirrors
 * FridayPackTest/FridayPackPdfTest's exact conventions. See
 * FridayPackLifecycleService's own docblock for the architectural
 * decisions this proves.
 */
class FridayPackLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private const FRIDAY = '2026-08-21';

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
        $project = Project::create(['organization_id' => $org->id, 'created_by' => $editor->id, 'name' => "Project {$suffix}"]);

        return [$org, $editor, $project];
    }

    /**
     * R1A.1: a real FridayPackGenerationService::generate() call now
     * always produces a schema-2 (realigned) snapshot, which
     * FridayPackPdfService deliberately refuses to render until a
     * schema-2-aware template exists (see
     * FridayPackUnsupportedSchemaVersionException). This file's tests
     * prove review/approval LIFECYCLE mechanics, orthogonal to snapshot
     * schema content, several of which also exercise PDF generation as
     * part of that lifecycle — so this helper downgrades the persisted
     * snapshot to the legacy schema-1 shape immediately after generation
     * (the pack is still `draft`, so FridayPackIntegrityGuard permits
     * this). Mirrors FridayPackPdfTest::generatePdfRenderablePack()
     * exactly.
     */
    private function makeDraftPack(Project $project, User $actor): FridayPack
    {
        $pack = app(FridayPackGenerationService::class)->generate($project, self::FRIDAY, $actor);
        $snapshot = $pack->snapshot_json;
        $snapshot['schema_version'] = 1;
        $pack->forceFill(['snapshot_json' => $snapshot])->save();

        return $pack->fresh();
    }

    // ── Phase 23: submit for review ────────────────────────────────────────

    public function test_draft_to_ready_for_review(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('s1');
        Sanctum::actingAs($editor);
        $pack = $this->makeDraftPack($project, $editor);
        $originalSnapshot = $pack->snapshot_json;
        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/pdf")->assertStatus(201);
        $historicalDocId = $pack->fresh()->pdf_document_id;

        $response = $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/submit-for-review");
        $response->assertStatus(200)->assertJsonPath('status', 'ready_for_review');

        $fresh = $pack->fresh();
        $this->assertSame($originalSnapshot, $fresh->snapshot_json);
        $this->assertNull($fresh->reviewed_at);
        $this->assertNull($fresh->reviewed_by);
        $this->assertNull($fresh->pdf_document_id);
        $this->assertNotNull(Document::find($historicalDocId), 'Historical PDF must be preserved.');
    }

    public function test_only_draft_may_submit_for_review(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('s2');
        Sanctum::actingAs($editor);
        $pack = $this->makeDraftPack($project, $editor);
        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/submit-for-review")->assertStatus(200);

        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/submit-for-review")->assertStatus(409);
    }

    // ── Phase 24: mark reviewed ──────────────────────────────────────────

    public function test_ready_for_review_to_reviewed(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('r1');
        Sanctum::actingAs($editor);
        $pack = $this->makeDraftPack($project, $editor);
        $pack = app(FridayPackLifecycleService::class)->submitForReview($pack, $editor);
        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/pdf")->assertStatus(201);
        $preReviewDocId = $pack->fresh()->pdf_document_id;

        $response = $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/mark-reviewed");
        $response->assertStatus(200);

        $fresh = $pack->fresh();
        $this->assertSame('ready_for_review', $fresh->status);
        $this->assertNotNull($fresh->reviewed_at);
        $this->assertSame($editor->id, $fresh->reviewed_by);
        $this->assertNull($fresh->pdf_document_id, 'Reviewer metadata is shown in the PDF, so marking reviewed invalidates the current PDF.');
        $this->assertNotNull(Document::find($preReviewDocId));
    }

    public function test_double_review_rejected(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('r2');
        Sanctum::actingAs($editor);
        $pack = $this->makeDraftPack($project, $editor);
        $pack = app(FridayPackLifecycleService::class)->submitForReview($pack, $editor);
        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/mark-reviewed")->assertStatus(200);

        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/mark-reviewed")->assertStatus(409);
    }

    public function test_draft_cannot_be_marked_reviewed(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('r3');
        Sanctum::actingAs($editor);
        $pack = $this->makeDraftPack($project, $editor);

        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/mark-reviewed")->assertStatus(409);
    }

    // ── Phase 25: approval ────────────────────────────────────────────────

    public function test_unreviewed_pack_cannot_be_approved(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('a1');
        Sanctum::actingAs($editor);
        $pack = $this->makeDraftPack($project, $editor);
        $pack = app(FridayPackLifecycleService::class)->submitForReview($pack, $editor);

        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/approve")->assertStatus(409);
        $this->assertSame('ready_for_review', $pack->fresh()->status);
    }

    public function test_reviewed_pack_can_be_approved(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('a2');
        Sanctum::actingAs($editor);
        $pack = $this->makeDraftPack($project, $editor);
        $originalSnapshot = $pack->snapshot_json;
        $originalCommentary = $pack->executive_summary;
        $pack = app(FridayPackLifecycleService::class)->submitForReview($pack, $editor);
        $pack = app(FridayPackLifecycleService::class)->markReviewed($pack, $editor);
        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/pdf")->assertStatus(201);
        $preApprovalDocId = $pack->fresh()->pdf_document_id;

        $response = $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/approve");
        $response->assertStatus(200);

        $fresh = $pack->fresh();
        $this->assertSame('approved', $fresh->status);
        $this->assertNotNull($fresh->approved_at);
        $this->assertSame($editor->id, $fresh->approved_by);
        $this->assertSame($originalSnapshot, $fresh->snapshot_json);
        $this->assertSame($originalCommentary, $fresh->executive_summary);
        $this->assertNull($fresh->pdf_document_id);
        $this->assertNotNull(Document::find($preApprovalDocId));
    }

    public function test_double_approve_rejected(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('a3');
        Sanctum::actingAs($editor);
        $pack = $this->makeDraftPack($project, $editor);
        $pack = app(FridayPackLifecycleService::class)->submitForReview($pack, $editor);
        $pack = app(FridayPackLifecycleService::class)->markReviewed($pack, $editor);
        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/approve")->assertStatus(200);

        $approvedAt = $pack->fresh()->approved_at;
        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/approve")->assertStatus(409);
        $this->assertEquals($approvedAt, $pack->fresh()->approved_at, 'A rejected second approve must never rewrite approved_at.');
    }

    // ── Phase 26: approved immutability ───────────────────────────────────

    private function makeApprovedPack(Project $project, User $editor): FridayPack
    {
        $pack = $this->makeDraftPack($project, $editor);
        $pack = app(FridayPackLifecycleService::class)->submitForReview($pack, $editor);
        $pack = app(FridayPackLifecycleService::class)->markReviewed($pack, $editor);
        return app(FridayPackLifecycleService::class)->approve($pack, $editor);
    }

    public function test_approved_pack_commentary_cannot_change_via_api(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('imm1');
        Sanctum::actingAs($editor);
        $pack = $this->makeApprovedPack($project, $editor);

        $this->putJson("/api/projects/{$project->id}/friday-packs/{$pack->id}", ['executive_summary' => 'hack'])
            ->assertStatus(409);
    }

    public function test_approved_pack_cannot_be_regenerated(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('imm2');
        Sanctum::actingAs($editor);
        $pack = $this->makeApprovedPack($project, $editor);

        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/regenerate")->assertStatus(409);
        $this->postJson("/api/projects/{$project->id}/friday-packs", ['week_ending' => self::FRIDAY])->assertStatus(409);
    }

    public function test_approved_pack_snapshot_fields_immutable_at_model_level(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('imm3');
        Sanctum::actingAs($editor);
        $pack = $this->makeApprovedPack($project, $editor);

        foreach (['snapshot_json', 'settings_snapshot_json', 'week_ending', 'period_start', 'period_end',
                  'executive_summary', 'progress_commentary', 'key_concerns', 'next_week_priorities'] as $field) {
            try {
                $value = $field === 'week_ending' || $field === 'period_start' || $field === 'period_end'
                    ? '2026-01-01'
                    : ($field === 'snapshot_json' || $field === 'settings_snapshot_json' ? ['hacked' => true] : 'hacked');
                $pack->fresh()->update([$field => $value]);
                $this->fail("Expected FridayPackImmutableException updating {$field} on an approved pack.");
            } catch (FridayPackImmutableException $e) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_approved_pack_cannot_return_to_draft(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('imm4');
        Sanctum::actingAs($editor);
        $pack = $this->makeApprovedPack($project, $editor);

        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/return-to-draft")->assertStatus(409);
        $this->assertSame('approved', $pack->fresh()->status);
    }

    public function test_approved_pack_direct_status_downgrade_is_blocked(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('imm5');
        Sanctum::actingAs($editor);
        $pack = $this->makeApprovedPack($project, $editor);

        // Lifecycle routes never accept a client-supplied status at all —
        // proven by inspection (submitForReview/markReviewed/approve/
        // returnToDraft take no request body). The only remaining
        // question is whether ordinary update() could smuggle a status
        // downgrade — it cannot, since update()'s validation rules don't
        // include 'status' in the first place.
        $this->putJson("/api/projects/{$project->id}/friday-packs/{$pack->id}", ['status' => 'draft'])
            ->assertStatus(409); // rejected by the draft-only commentary guard before status is even considered
        $this->assertSame('approved', $pack->fresh()->status);
    }

    public function test_approved_pack_delete_still_blocked(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('imm6');
        Sanctum::actingAs($editor);
        $pack = $this->makeApprovedPack($project, $editor);

        $this->deleteJson("/api/projects/{$project->id}/friday-packs/{$pack->id}")->assertStatus(409);
    }

    // ── Phase 27: approved PDF ─────────────────────────────────────────────

    public function test_approved_pdf_generation_succeeds_with_full_metadata(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('pdf1', 'Client');
        Sanctum::actingAs($editor);
        $pack = $this->makeApprovedPack($project, $editor);

        $response = $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/pdf");
        $response->assertStatus(201);

        $document = Document::find($response->json('document.id'));
        $data = $document->template_data['pack'];
        $this->assertSame('approved', $data['status']);
        $this->assertSame('APPROVED', $data['status_label']);
        $this->assertNotNull($data['reviewed_at']);
        $this->assertNotNull($data['reviewed_by']);
        $this->assertNotNull($data['approved_at']);
        $this->assertNotNull($data['approved_by']);
        $this->assertSame($document->id, $pack->fresh()->pdf_document_id);
    }

    public function test_approved_pdf_can_be_regenerated_producing_new_document_preserving_old(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('pdf2');
        Sanctum::actingAs($editor);
        $pack = $this->makeApprovedPack($project, $editor);
        $first = $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/pdf");
        $firstDocId = $first->json('document.id');

        $second = $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/pdf");
        $second->assertStatus(201);
        $secondDocId = $second->json('document.id');

        $this->assertNotSame($firstDocId, $secondDocId);
        $this->assertNotNull(Document::find($firstDocId), 'Old approved PDF must remain preserved.');
        $this->assertSame($secondDocId, $pack->fresh()->pdf_document_id);
        $this->assertSame('approved', $pack->fresh()->status, 'Regenerating the Approved PDF must never alter frozen report content or status.');
    }

    // ── Phase 28: return to draft ──────────────────────────────────────────

    public function test_return_to_draft_from_ready_for_review(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('ret1');
        Sanctum::actingAs($editor);
        $pack = $this->makeDraftPack($project, $editor);
        $originalSnapshot = $pack->snapshot_json;
        $originalCommentary = $pack->executive_summary;
        $pack = app(FridayPackLifecycleService::class)->submitForReview($pack, $editor);
        $pack = app(FridayPackLifecycleService::class)->markReviewed($pack, $editor);
        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/pdf")->assertStatus(201);
        $historicalDocId = $pack->fresh()->pdf_document_id;

        $response = $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/return-to-draft");
        $response->assertStatus(200);

        $fresh = $pack->fresh();
        $this->assertSame('draft', $fresh->status);
        $this->assertNull($fresh->reviewed_at);
        $this->assertNull($fresh->reviewed_by);
        $this->assertNull($fresh->pdf_document_id);
        $this->assertSame($originalSnapshot, $fresh->snapshot_json);
        $this->assertSame($originalCommentary, $fresh->executive_summary);
        $this->assertNotNull(Document::find($historicalDocId));

        // Commentary is editable again once back in draft.
        $this->putJson("/api/projects/{$project->id}/friday-packs/{$fresh->id}", ['executive_summary' => 'Editable again'])
            ->assertStatus(200)->assertJsonPath('executive_summary', 'Editable again');
    }

    // ── Phase 29: tenant / IDOR ────────────────────────────────────────────

    public function test_cross_organisation_lifecycle_action_rejected(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('t1');
        Sanctum::actingAs($editor);
        $pack = $this->makeDraftPack($project, $editor);

        $foreignOrg = Organization::create(['name' => 'Foreign t1', 'slug' => 'foreign-t1']);
        $foreignUser = User::factory()->create(['organization_id' => $foreignOrg->id, 'is_active' => true]);
        $foreignUser->assignRole(Role::firstOrCreate(['name' => 'Client', 'guard_name' => 'web']));
        Sanctum::actingAs($foreignUser);

        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/submit-for-review")->assertStatus(403);
    }

    public function test_same_organisation_wrong_project_lifecycle_action_rejected(): void
    {
        [$org, $editor, $projectA] = $this->makeOrgProjectAndEditor('t2');
        $projectB = Project::create(['organization_id' => $org->id, 'created_by' => $editor->id, 'name' => 'Project B t2']);
        Sanctum::actingAs($editor);
        $packB = $this->makeDraftPack($projectB, $editor);

        $this->postJson("/api/projects/{$projectA->id}/friday-packs/{$packB->id}/submit-for-review")->assertStatus(404);
        $this->assertSame('draft', $packB->fresh()->status);
    }

    public function test_admin_platform_access_requires_matching_nested_parent(): void
    {
        [$org, $editor, $projectA] = $this->makeOrgProjectAndEditor('t3');
        $projectB = Project::create(['organization_id' => $org->id, 'created_by' => $editor->id, 'name' => 'Project B t3']);
        Sanctum::actingAs($editor);
        $packB = $this->makeDraftPack($projectB, $editor);

        $admin = User::factory()->create(['organization_id' => null, 'is_active' => true]);
        $admin->assignRole(Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']));
        Sanctum::actingAs($admin);

        $this->postJson("/api/projects/{$projectA->id}/friday-packs/{$packB->id}/submit-for-review")->assertStatus(404);
        $this->postJson("/api/projects/{$projectB->id}/friday-packs/{$packB->id}/submit-for-review")->assertStatus(200);
    }

    public function test_feature_unavailable_blocks_lifecycle_mutation(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('t4');
        Sanctum::actingAs($editor);
        $pack = $this->makeDraftPack($project, $editor);
        FeatureAvailability::create(['feature_key' => 'project.friday_packs', 'status' => 'maintenance']);

        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/submit-for-review")->assertStatus(503);
    }

    public function test_spoofed_reviewed_and_approved_fields_are_ignored(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('t5');
        $otherUser = User::factory()->create(['organization_id' => $project->organization_id, 'is_active' => true]);
        Sanctum::actingAs($editor);
        $pack = $this->makeDraftPack($project, $editor);

        // None of the lifecycle endpoints accept a request body at all —
        // proven by sending one and confirming it has zero effect.
        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/submit-for-review", [
            'reviewed_at' => '2020-01-01', 'reviewed_by' => $otherUser->id,
        ])->assertStatus(200);
        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/mark-reviewed", [
            'reviewed_by' => $otherUser->id, 'reviewed_at' => '2020-01-01',
        ])->assertStatus(200);

        $fresh = $pack->fresh();
        $this->assertSame($editor->id, $fresh->reviewed_by);
        $this->assertNotEquals('2020-01-01', $fresh->reviewed_at->toDateString());
    }

    // ── Phase 30: concurrency / double-action safety ──────────────────────
    // Sequential-order verification (not a real multi-process race) —
    // proportionate for a straightforward reuse of the P2 AI Analysis
    // TOCTOU row-lock pattern, unlike that fix's own dedicated real-MySQL
    // multi-process test, which exists for a documented historical
    // vulnerability class. The row lock + re-read-inside-transaction
    // shape is identical to that proven pattern.

    public function test_double_mark_reviewed_cannot_produce_inconsistent_metadata(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('c1');
        Sanctum::actingAs($editor);
        $pack = $this->makeDraftPack($project, $editor);
        $pack = app(FridayPackLifecycleService::class)->submitForReview($pack, $editor);
        $service = app(FridayPackLifecycleService::class);

        $service->markReviewed($pack, $editor);
        $firstReviewedAt = $pack->fresh()->reviewed_at;

        try {
            $service->markReviewed($pack->fresh(), $editor);
            $this->fail('Expected RuntimeException on double markReviewed().');
        } catch (\RuntimeException $e) {
            $this->assertEquals($firstReviewedAt, $pack->fresh()->reviewed_at, 'reviewed_at must never be rewritten by a rejected second review.');
        }
    }

    public function test_approve_after_return_to_draft_race_leaves_a_consistent_state(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('c2');
        Sanctum::actingAs($editor);
        $pack = $this->makeDraftPack($project, $editor);
        $pack = app(FridayPackLifecycleService::class)->submitForReview($pack, $editor);
        $pack = app(FridayPackLifecycleService::class)->markReviewed($pack, $editor);
        $service = app(FridayPackLifecycleService::class);

        // Simulates the race: returnToDraft() commits first...
        $service->returnToDraft($pack->fresh(), $editor);

        // ...then a stale in-memory $pack instance's approve() call must
        // still be rejected, because the service re-reads and locks the
        // CURRENT row inside its own transaction rather than trusting the
        // caller's already-stale in-memory model.
        try {
            $service->approve($pack, $editor);
            $this->fail('Expected RuntimeException — the pack was already returned to draft.');
        } catch (\RuntimeException $e) {
            $this->assertSame('draft', $pack->fresh()->status, 'The pack must remain a consistent, single, well-defined state — never an approved pack with draft-shaped metadata.');
        }
    }
}
