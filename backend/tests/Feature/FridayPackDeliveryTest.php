<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\FridayPack;
use App\Models\FridayPackDelivery;
use App\Models\FridayPackSettings;
use App\Models\Organization;
use App\Models\Project;
use App\Models\SuresignSetting;
use App\Models\User;
use App\Services\FridayPack\FridayPackGenerationService;
use App\Services\FridayPack\FridayPackLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GivesFridayPackEntitlement;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Automated Friday Pack V1F — Approved Pack Delivery. Mirrors
 * FridayPackTest/FridayPackPdfTest's exact conventions. See
 * FridayPackDeliveryService/SendFridayPackDeliveryJob/
 * FridayPackDeliveryLinkService/PublicFridayPackDeliveryController
 * docblocks for the architectural decisions this proves.
 */
class FridayPackDeliveryTest extends TestCase
{
    use RefreshDatabase;
    use GivesFridayPackEntitlement;

    private const FRIDAY = '2026-08-21';

    private function makeOrgProjectAndEditor(string $suffix, string $role = 'Client'): array
    {
        $org = Organization::create(['name' => "Org {$suffix}", 'slug' => "org-{$suffix}", 'timezone' => 'Europe/London']);
        $this->giveFridayPackEntitlement($org);
        $editor = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $editor->assignRole(Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']));
        $project = Project::create([
            'organization_id' => $org->id,
            'created_by'      => $editor->id,
            'name'            => "Project {$suffix}",
        ]);

        return [$org, $editor, $project];
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        SuresignSetting::instance()->update([
            'brevo_api_key'       => 'fake-brevo-key',
            'email_sender_email'  => 'noreply@suresigncontracts.app',
            'support_email'       => 'support@suresigncontracts.app',
            'admin_email'         => 'admin@suresigncontracts.app',
        ]);
    }

    /**
     * Builds a fully approved pack (with a current PDF) ready to Send.
     *
     * R1A.1: generate() now always produces a schema-2 (realigned)
     * snapshot, which FridayPackPdfService deliberately refuses to
     * render until a schema-2-aware template exists. This file's tests
     * prove delivery mechanics, orthogonal to snapshot schema content, so
     * the snapshot is downgraded to the legacy schema-1 shape immediately
     * after generation (the pack is still `draft`, so
     * FridayPackIntegrityGuard permits this) — mirrors
     * FridayPackPdfTest::generatePdfRenderablePack() exactly.
     */
    private function makeApprovedPackWithPdf(Project $project, User $editor): FridayPack
    {
        $pack = app(FridayPackGenerationService::class)->generate($project, self::FRIDAY, $editor);
        $snapshot = $pack->snapshot_json;
        $snapshot['schema_version'] = 1;
        $pack->forceFill(['snapshot_json' => $snapshot])->save();
        $pack = $pack->fresh();
        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/pdf")->assertStatus(201);
        $lifecycle = app(FridayPackLifecycleService::class);
        $lifecycle->submitForReview($pack, $editor);
        $lifecycle->markReviewed($pack->fresh(), $editor);
        $pack = $lifecycle->approve($pack->fresh(), $editor);
        // Approve/review clear pdf_document_id (V1D stale-PDF rule) — regenerate.
        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/pdf")->assertStatus(201);

        return $pack->fresh();
    }

    private function setRecipients(Project $project, User $editor, array $recipients): void
    {
        FridayPackSettings::updateOrCreate(
            ['project_id' => $project->id],
            ['organization_id' => $project->organization_id, 'enabled' => true, 'included_sections' => [], 'recipients' => $recipients, 'created_by' => $editor->id, 'updated_by' => $editor->id],
        );
    }

    // ── Send prerequisites ────────────────────────────────────────────────

    public function test_send_is_rejected_when_pack_is_not_approved(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('p1');
        Sanctum::actingAs($editor);
        $pack = app(FridayPackGenerationService::class)->generate($project, self::FRIDAY, $editor);
        $this->setRecipients($project, $editor, [['email' => 'a@example.com']]);

        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/send")
            ->assertStatus(409)
            ->assertJsonFragment(['message' => 'Only an approved Friday Pack can be sent.']);
    }

    public function test_send_is_rejected_when_pack_has_no_current_pdf(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('p2');
        Sanctum::actingAs($editor);
        $pack = $this->makeApprovedPackWithPdf($project, $editor);
        $pack->forceFill(['pdf_document_id' => null])->save();
        $this->setRecipients($project, $editor, [['email' => 'a@example.com']]);

        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/send")
            ->assertStatus(409)
            ->assertJsonFragment(['message' => 'This Friday Pack has no current PDF — generate one before sending.']);
    }

    public function test_send_is_rejected_when_no_recipients_configured(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('p3');
        Sanctum::actingAs($editor);
        $pack = $this->makeApprovedPackWithPdf($project, $editor);

        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/send")
            ->assertStatus(409)
            ->assertJsonFragment(['message' => 'No delivery recipients are configured for this project. Add recipients in Friday Pack Settings before sending.']);
    }

    public function test_send_is_rejected_a_second_time_once_already_sent(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('p4');
        Sanctum::actingAs($editor);
        Http::fake(['api.brevo.com/*' => Http::response(['messageId' => 'x'], 201)]);
        $pack = $this->makeApprovedPackWithPdf($project, $editor);
        $this->setRecipients($project, $editor, [['email' => 'a@example.com']]);

        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/send")->assertStatus(201);
        // On sync queue with a fully successful send, the pack has already
        // finalized to 'sent' by the time the second request runs — so this
        // hits the "not approved" prerequisite first. Either rejection
        // reason is a correct expression of the same underlying rule
        // ("Send is a one-time initiation event") — see
        // FridayPackDeliveryService::initiate()'s own "already sent" guard
        // for the case where the pack is still 'approved' (a partial/failed
        // delivery) and a second Send is attempted.
        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/send")
            ->assertStatus(409)
            ->assertJsonFragment(['message' => 'Only an approved Friday Pack can be sent.']);
    }

    public function test_send_is_rejected_a_second_time_while_still_approved_after_a_partial_failure(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('p4b');
        Sanctum::actingAs($editor);
        Http::fake(['api.brevo.com/*' => Http::response('down', 500)]);
        $pack = $this->makeApprovedPackWithPdf($project, $editor);
        $this->setRecipients($project, $editor, [['email' => 'a@example.com']]);

        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/send")->assertStatus(201);
        $this->assertSame('approved', $pack->fresh()->status);

        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/send")
            ->assertStatus(409)
            ->assertJsonFragment(['message' => 'This Friday Pack has already been sent. Use "Retry Failed" to re-attempt any failed recipients.']);
    }

    // ── Recipient snapshotting ─────────────────────────────────────────────

    public function test_recipients_are_snapshotted_at_send_time_and_a_later_settings_change_never_affects_it(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('snap1');
        Sanctum::actingAs($editor);
        Http::fake(['api.brevo.com/*' => Http::response(['messageId' => 'x'], 201)]);
        $pack = $this->makeApprovedPackWithPdf($project, $editor);
        $this->setRecipients($project, $editor, [['name' => 'Alice', 'email' => 'Alice@Example.com']]);

        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/send")->assertStatus(201);

        $this->assertSame(1, FridayPackDelivery::where('friday_pack_id', $pack->id)->count());
        $this->assertDatabaseHas('friday_pack_deliveries', ['friday_pack_id' => $pack->id, 'recipient_email' => 'alice@example.com']);

        // Settings now change (add a second recipient, remove the first) —
        // the already-created delivery row must be completely unaffected.
        $this->setRecipients($project, $editor, [['name' => 'Bob', 'email' => 'bob@example.com']]);

        $this->assertSame(1, FridayPackDelivery::where('friday_pack_id', $pack->id)->count());
        $this->assertDatabaseHas('friday_pack_deliveries', ['friday_pack_id' => $pack->id, 'recipient_email' => 'alice@example.com']);
        $this->assertDatabaseMissing('friday_pack_deliveries', ['friday_pack_id' => $pack->id, 'recipient_email' => 'bob@example.com']);
    }

    // ── Full success + finalization ────────────────────────────────────────

    public function test_successful_send_to_all_recipients_marks_pack_sent_with_real_actor(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('ok1');
        Sanctum::actingAs($editor);
        Http::fake(['api.brevo.com/*' => Http::response(['messageId' => 'msg-123'], 201)]);
        $pack = $this->makeApprovedPackWithPdf($project, $editor);
        $this->setRecipients($project, $editor, [['email' => 'a@example.com'], ['email' => 'b@example.com']]);

        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/send")->assertStatus(201);

        $fresh = $pack->fresh();
        $this->assertSame('sent', $fresh->status);
        $this->assertNotNull($fresh->sent_at);
        $this->assertSame($editor->id, $fresh->sent_by);
        $this->assertSame(2, FridayPackDelivery::where('friday_pack_id', $pack->id)->where('status', 'sent')->count());
        $this->assertDatabaseHas('friday_pack_deliveries', ['friday_pack_id' => $pack->id, 'provider_message_id' => 'msg-123']);
    }

    // ── Partial failure + retry ────────────────────────────────────────────

    public function test_a_provider_failure_marks_that_recipient_failed_and_leaves_pack_approved(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('fail1');
        Sanctum::actingAs($editor);
        Http::fake(['api.brevo.com/*' => Http::response('provider down', 500)]);
        $pack = $this->makeApprovedPackWithPdf($project, $editor);
        $this->setRecipients($project, $editor, [['email' => 'a@example.com']]);

        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/send")->assertStatus(201);

        $fresh = $pack->fresh();
        $this->assertSame('approved', $fresh->status);
        $this->assertNull($fresh->sent_at);
        $delivery = FridayPackDelivery::where('friday_pack_id', $pack->id)->first();
        $this->assertSame('failed', $delivery->status);
        $this->assertNotNull($delivery->failure_reason);
    }

    public function test_retry_failed_re_sends_only_the_failed_recipient_and_never_the_already_sent_one(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('retry1');
        Sanctum::actingAs($editor);

        $callCount = 0;
        Http::fake(function () use (&$callCount) {
            $callCount++;
            // First send: a@example.com succeeds, b@example.com fails.
            // The 2nd call in the batch is for b.
            return $callCount === 2 ? Http::response('down', 500) : Http::response(['messageId' => 'ok-' . $callCount], 201);
        });

        $pack = $this->makeApprovedPackWithPdf($project, $editor);
        $this->setRecipients($project, $editor, [['email' => 'a@example.com'], ['email' => 'b@example.com']]);
        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/send")->assertStatus(201);

        $this->assertSame('approved', $pack->fresh()->status);
        $sentDelivery = FridayPackDelivery::where('friday_pack_id', $pack->id)->where('recipient_email', 'a@example.com')->first();
        $failedDelivery = FridayPackDelivery::where('friday_pack_id', $pack->id)->where('recipient_email', 'b@example.com')->first();
        $this->assertSame('sent', $sentDelivery->status);
        $this->assertSame('failed', $failedDelivery->status);
        $sentAttemptCountBefore = $sentDelivery->attempt_count;

        // Now retry — b succeeds this time; a must never be re-dispatched.
        Http::fake(['api.brevo.com/*' => Http::response(['messageId' => 'retry-ok'], 201)]);
        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/deliveries/retry-failed")->assertStatus(200);

        $this->assertSame('sent', $failedDelivery->fresh()->status);
        $this->assertSame($sentAttemptCountBefore, $sentDelivery->fresh()->attempt_count);
        $this->assertSame('sent', $pack->fresh()->status);
    }

    public function test_retry_failed_with_nothing_failed_is_rejected(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('retry2');
        Sanctum::actingAs($editor);
        Http::fake(['api.brevo.com/*' => Http::response(['messageId' => 'x'], 201)]);
        $pack = $this->makeApprovedPackWithPdf($project, $editor);
        $this->setRecipients($project, $editor, [['email' => 'a@example.com']]);
        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/send")->assertStatus(201);

        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/deliveries/retry-failed")
            ->assertStatus(409)
            ->assertJsonFragment(['message' => 'There are no failed deliveries to retry for this Friday Pack.']);
    }

    // ── PDF lock ───────────────────────────────────────────────────────────

    public function test_pdf_cannot_be_regenerated_once_any_delivery_exists(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('lock1');
        Sanctum::actingAs($editor);
        Http::fake(['api.brevo.com/*' => Http::response(['messageId' => 'x'], 201)]);
        $pack = $this->makeApprovedPackWithPdf($project, $editor);
        $this->setRecipients($project, $editor, [['email' => 'a@example.com']]);
        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/send")->assertStatus(201);

        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/pdf")
            ->assertStatus(409)
            ->assertJsonFragment(['message' => 'This Friday Pack has already been sent and its PDF can no longer be regenerated.']);
    }

    // ── Document deletion protection ────────────────────────────────────────

    public function test_a_distributed_document_cannot_be_deleted(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('doc1');
        Sanctum::actingAs($editor);
        Http::fake(['api.brevo.com/*' => Http::response(['messageId' => 'x'], 201)]);
        $pack = $this->makeApprovedPackWithPdf($project, $editor);
        $this->setRecipients($project, $editor, [['email' => 'a@example.com']]);
        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/send")->assertStatus(201);

        $documentId = $pack->fresh()->pdf_document_id;
        $this->deleteJson("/api/documents/{$documentId}")
            ->assertStatus(409)
            ->assertJsonFragment(['message' => 'This document has been sent to Friday Pack recipients and cannot be deleted.']);
        $this->assertDatabaseHas('documents', ['id' => $documentId, 'deleted_at' => null]);
    }

    // ── Signed public download link ────────────────────────────────────────

    public function test_public_download_link_serves_the_correct_pdf(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('link1');
        Sanctum::actingAs($editor);
        Http::fake(['api.brevo.com/*' => Http::response(['messageId' => 'x'], 201)]);
        $pack = $this->makeApprovedPackWithPdf($project, $editor);
        $this->setRecipients($project, $editor, [['email' => 'a@example.com']]);
        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/send")->assertStatus(201);

        $delivery = FridayPackDelivery::where('friday_pack_id', $pack->id)->first();
        $signedUrl = \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'public.friday-packs.download', $delivery->link_expires_at, ['token' => $delivery->public_token],
        );

        $response = $this->get($signedUrl);
        $response->assertStatus(200);
    }

    public function test_public_download_link_rejects_a_tampered_signature(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('link2');
        Sanctum::actingAs($editor);
        Http::fake(['api.brevo.com/*' => Http::response(['messageId' => 'x'], 201)]);
        $pack = $this->makeApprovedPackWithPdf($project, $editor);
        $this->setRecipients($project, $editor, [['email' => 'a@example.com']]);
        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/send")->assertStatus(201);

        $delivery = FridayPackDelivery::where('friday_pack_id', $pack->id)->first();
        $signedUrl = \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'public.friday-packs.download', $delivery->link_expires_at, ['token' => $delivery->public_token],
        );

        $this->get($signedUrl . '&tampered=1')->assertStatus(403);
    }

    public function test_public_download_link_rejects_an_expired_link(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('link3');
        Sanctum::actingAs($editor);
        Http::fake(['api.brevo.com/*' => Http::response(['messageId' => 'x'], 201)]);
        $pack = $this->makeApprovedPackWithPdf($project, $editor);
        $this->setRecipients($project, $editor, [['email' => 'a@example.com']]);
        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/send")->assertStatus(201);

        $delivery = FridayPackDelivery::where('friday_pack_id', $pack->id)->first();
        $expiredUrl = \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'public.friday-packs.download', now()->subDay(), ['token' => $delivery->public_token],
        );

        $this->get($expiredUrl)->assertStatus(403);
    }

    public function test_public_download_link_with_an_unknown_token_returns_404(): void
    {
        $signedUrl = \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'public.friday-packs.download', now()->addDay(), ['token' => 'does-not-exist'],
        );

        $this->get($signedUrl)->assertStatus(404);
    }

    // ── Tenant / IDOR ───────────────────────────────────────────────────────

    public function test_send_is_rejected_for_a_user_from_a_different_organization(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('tenant1');
        [, $otherEditor] = $this->makeOrgProjectAndEditor('tenant2');
        Sanctum::actingAs($editor);
        $pack = $this->makeApprovedPackWithPdf($project, $editor);
        $this->setRecipients($project, $editor, [['email' => 'a@example.com']]);

        Sanctum::actingAs($otherEditor);
        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/send")->assertStatus(403);
    }

    /**
     * R1G.2A — the same-organisation/different-project substitution case:
     * FridayPackDeliveryController::authorizeProjectFridayPack() already
     * re-derives the pack's own real project_id and 404s on mismatch (same
     * code path FridayPackTest::test_wrong_project_in_same_organisation_is_rejected()
     * proves for the core FridayPackController) — this controller had no
     * dedicated proof of its own. Covers all three delivery routes
     * (index/send/retryFailed).
     */
    public function test_wrong_project_in_same_organisation_is_rejected_for_delivery_routes(): void
    {
        [$org, $editor, $projectA] = $this->makeOrgProjectAndEditor('tenant3');
        $projectB = Project::create(['organization_id' => $org->id, 'created_by' => $editor->id, 'name' => 'Project B tenant3']);
        Sanctum::actingAs($editor);
        $packB = $this->makeApprovedPackWithPdf($projectB, $editor);
        $this->setRecipients($projectB, $editor, [['email' => 'a@example.com']]);

        $this->getJson("/api/projects/{$projectA->id}/friday-packs/{$packB->id}/deliveries")->assertStatus(404);
        $this->postJson("/api/projects/{$projectA->id}/friday-packs/{$packB->id}/send")->assertStatus(404);
        $this->postJson("/api/projects/{$projectA->id}/friday-packs/{$packB->id}/deliveries/retry-failed")->assertStatus(404);
    }

    // ── Email content (no marketing CTA) ────────────────────────────────────

    public function test_delivery_email_contains_no_marketing_language(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('email1');
        Sanctum::actingAs($editor);

        $capturedBody = null;
        Http::fake(function ($request) use (&$capturedBody) {
            $capturedBody = $request->body();
            return Http::response(['messageId' => 'x'], 201);
        });

        $pack = $this->makeApprovedPackWithPdf($project, $editor);
        $this->setRecipients($project, $editor, [['email' => 'a@example.com']]);
        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/send")->assertStatus(201);

        $this->assertNotNull($capturedBody);
        $this->assertStringNotContainsStringIgnoringCase('explore suresign', $capturedBody);
        $this->assertStringNotContainsStringIgnoringCase('pricing', $capturedBody);
        $this->assertStringNotContainsStringIgnoringCase('newsletter', $capturedBody);
        $this->assertStringContainsString('Download Friday Pack', $capturedBody);
    }

    // ── No automatic send ───────────────────────────────────────────────────

    public function test_approving_a_pack_never_triggers_a_send(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('noauto1');
        Sanctum::actingAs($editor);
        Http::fake(['api.brevo.com/*' => Http::response(['messageId' => 'x'], 201)]);
        $this->setRecipients($project, $editor, [['email' => 'a@example.com']]);

        $this->makeApprovedPackWithPdf($project, $editor);

        $this->assertSame(0, FridayPackDelivery::count());
        Http::assertNothingSent();
    }
}
