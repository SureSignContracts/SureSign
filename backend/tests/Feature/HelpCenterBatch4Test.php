<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\PlatformAnnouncement;
use App\Models\SuresignSetting;
use App\Models\User;
use App\Services\KnowledgeBaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class HelpCenterBatch4Test extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $role = 'Client'): User
    {
        $org  = Organization::create(['name' => 'Org', 'slug' => 'org-' . uniqid()]);
        $user = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $user->assignRole(Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']));

        return $user;
    }

    // ── Knowledge Base ───────────────────────────────────────────────────────

    public function test_knowledge_base_index_includes_only_curated_public_categories(): void
    {
        Sanctum::actingAs($this->makeUser());

        $response = $this->getJson('/api/knowledge-base')->assertStatus(200);

        $categories = collect($response->json('data'))->pluck('category')->all();
        $this->assertNotEmpty($categories);
        foreach ($categories as $category) {
            $this->assertArrayHasKey($category, KnowledgeBaseService::CATEGORIES);
        }
    }

    public function test_knowledge_base_never_exposes_internal_docs(): void
    {
        Sanctum::actingAs($this->makeUser());

        $response = $this->getJson('/api/knowledge-base')->assertStatus(200);

        $urls = collect($response->json('data'))->flatMap(fn ($c) => collect($c['articles'])->pluck('url'));
        foreach ($urls as $url) {
            $this->assertStringStartsWith(KnowledgeBaseService::DOCS_BASE_URL, $url);
            $this->assertStringNotContainsString('internal-docs', $url);
            $this->assertStringNotContainsString('super-admin', $url);
        }
    }

    public function test_knowledge_base_search_returns_correct_category_and_link(): void
    {
        Sanctum::actingAs($this->makeUser());

        $response = $this->getJson('/api/knowledge-base?q=variation')->assertStatus(200);

        $categories = collect($response->json('data'))->pluck('category')->all();
        $this->assertContains('variations', $categories);
        $variationCategory = collect($response->json('data'))->firstWhere('category', 'variations');
        $this->assertStringStartsWith('https://docs.suresigncontracts.app/', $variationCategory['articles'][0]['url']);
    }

    public function test_knowledge_base_search_with_no_matches_returns_empty(): void
    {
        Sanctum::actingAs($this->makeUser());

        $response = $this->getJson('/api/knowledge-base?q=zzz_no_such_topic_zzz')->assertStatus(200);

        $this->assertSame([], $response->json('data'));
    }

    public function test_knowledge_base_returns_suggested_categories_for_a_route(): void
    {
        Sanctum::actingAs($this->makeUser());

        $response = $this->getJson('/api/knowledge-base?route=' . urlencode('/app/projects/1/commercial'))->assertStatus(200);

        $this->assertContains('commercial', $response->json('suggested_categories'));
    }

    public function test_knowledge_base_requires_authentication(): void
    {
        $this->getJson('/api/knowledge-base')->assertStatus(401);
    }

    // ── System Status ────────────────────────────────────────────────────────

    public function test_system_status_requires_authentication(): void
    {
        $this->getJson('/api/system-status')->assertStatus(401);
    }

    public function test_system_status_exposes_no_infrastructure_details(): void
    {
        Cache::flush();
        Sanctum::actingAs($this->makeUser());

        $response = $this->getJson('/api/system-status')->assertStatus(200);
        $body = $response->getContent();

        foreach (['mysql', 'redis', 'nginx', 'docker', '3306', '6379', 'localhost', config('app.key')] as $leak) {
            $this->assertStringNotContainsString((string) $leak, $body);
        }
        // Only customer-safe labels, never raw exception messages.
        $this->assertStringNotContainsString('Exception', $body);
        $this->assertStringNotContainsString('SQLSTATE', $body);
    }

    public function test_system_status_never_claims_ai_analysis_is_operational_without_a_live_check(): void
    {
        Cache::flush();
        SuresignSetting::instance()->update(['ai_enabled' => true, 'anthropic_api_key' => 'fake-key']);
        Sanctum::actingAs($this->makeUser());

        $response = $this->getJson('/api/system-status')->assertStatus(200);

        $ai = collect($response->json('components'))->firstWhere('name', 'Automated Contract Analysis');
        $this->assertSame('unavailable', $ai['status']);
    }

    public function test_system_status_email_delivery_is_unavailable_when_not_configured(): void
    {
        Cache::flush();
        SuresignSetting::instance()->update(['brevo_api_key' => null]);
        Sanctum::actingAs($this->makeUser());

        $response = $this->getJson('/api/system-status')->assertStatus(200);

        $email = collect($response->json('components'))->firstWhere('name', 'Email Delivery');
        $this->assertSame('unavailable', $email['status']);
    }

    public function test_system_status_reports_operational_email_when_brevo_check_succeeds(): void
    {
        Cache::flush();
        SuresignSetting::instance()->update(['brevo_api_key' => 'fake-brevo-key']);
        Http::fake(['api.brevo.com/v3/account' => Http::response(['plan' => []], 200)]);
        Sanctum::actingAs($this->makeUser());

        $response = $this->getJson('/api/system-status')->assertStatus(200);

        $email = collect($response->json('components'))->firstWhere('name', 'Email Delivery');
        $this->assertSame('operational', $email['status']);
        Http::assertSentCount(1);
    }

    public function test_system_status_response_is_cached_and_does_not_repeat_the_external_call(): void
    {
        Cache::flush();
        SuresignSetting::instance()->update(['brevo_api_key' => 'fake-brevo-key']);
        Http::fake(['api.brevo.com/v3/account' => Http::response(['plan' => []], 200)]);
        Sanctum::actingAs($this->makeUser());

        $this->getJson('/api/system-status')->assertStatus(200);
        $this->getJson('/api/system-status')->assertStatus(200);
        $this->getJson('/api/system-status')->assertStatus(200);

        Http::assertSentCount(1);
    }

    // ── Emergency / Known-Issue Banner ──────────────────────────────────────

    public function test_active_banner_is_visible_to_a_client(): void
    {
        $admin = $this->makeUser('Admin');
        PlatformAnnouncement::create([
            'title' => 'Scheduled maintenance', 'message' => 'Tonight at 10pm.',
            'severity' => 'maintenance', 'is_active' => true,
            'starts_at' => now()->subHour(), 'ends_at' => now()->addHour(), 'created_by' => $admin->id,
        ]);

        Sanctum::actingAs($this->makeUser());
        $response = $this->getJson('/api/platform-announcements/active')->assertStatus(200);

        $this->assertNotNull($response->json('data'));
        $this->assertSame('Scheduled maintenance', $response->json('data.title'));
    }

    public function test_inactive_banner_is_hidden(): void
    {
        $admin = $this->makeUser('Admin');
        PlatformAnnouncement::create([
            'title' => 'Hidden', 'message' => 'msg', 'severity' => 'information', 'is_active' => false,
            'starts_at' => now()->subHour(), 'created_by' => $admin->id,
        ]);

        Sanctum::actingAs($this->makeUser());
        $response = $this->getJson('/api/platform-announcements/active')->assertStatus(200);

        $this->assertNull($response->json('data'));
    }

    public function test_future_banner_is_hidden(): void
    {
        $admin = $this->makeUser('Admin');
        PlatformAnnouncement::create([
            'title' => 'Not yet', 'message' => 'msg', 'severity' => 'information', 'is_active' => true,
            'starts_at' => now()->addDay(), 'created_by' => $admin->id,
        ]);

        Sanctum::actingAs($this->makeUser());
        $response = $this->getJson('/api/platform-announcements/active')->assertStatus(200);

        $this->assertNull($response->json('data'));
    }

    public function test_expired_banner_is_hidden(): void
    {
        $admin = $this->makeUser('Admin');
        PlatformAnnouncement::create([
            'title' => 'Expired', 'message' => 'msg', 'severity' => 'outage', 'is_active' => true,
            'starts_at' => now()->subDays(2), 'ends_at' => now()->subDay(), 'created_by' => $admin->id,
        ]);

        Sanctum::actingAs($this->makeUser());
        $response = $this->getJson('/api/platform-announcements/active')->assertStatus(200);

        $this->assertNull($response->json('data'));
    }

    public function test_client_cannot_manage_banners(): void
    {
        Sanctum::actingAs($this->makeUser());

        $this->getJson('/api/admin/platform-announcements')->assertStatus(403);
        $this->postJson('/api/admin/platform-announcements', [
            'title' => 'x', 'message' => 'x', 'severity' => 'information', 'starts_at' => now()->toIso8601String(),
        ])->assertStatus(403);
    }

    public function test_authorized_platform_operator_can_manage_banners(): void
    {
        $admin = $this->makeUser('Admin');
        Sanctum::actingAs($admin);

        $create = $this->postJson('/api/admin/platform-announcements', [
            'title' => 'New banner', 'message' => 'Details here', 'severity' => 'degraded_service',
            'is_active' => true, 'starts_at' => now()->toIso8601String(),
        ])->assertStatus(201);

        $id = $create->json('data.id');

        $this->putJson("/api/admin/platform-announcements/{$id}", [
            'title' => 'Updated banner', 'message' => 'Details here', 'severity' => 'outage',
            'is_active' => true, 'starts_at' => now()->toIso8601String(),
        ])->assertStatus(200)->assertJsonFragment(['title' => 'Updated banner']);

        $this->getJson('/api/admin/platform-announcements')->assertStatus(200);
        $this->deleteJson("/api/admin/platform-announcements/{$id}")->assertStatus(200);
        $this->assertDatabaseMissing('platform_announcements', ['id' => $id]);
    }

    public function test_super_admin_can_also_manage_banners(): void
    {
        Sanctum::actingAs($this->makeUser('Super Admin'));

        $this->postJson('/api/admin/platform-announcements', [
            'title' => 'x', 'message' => 'x', 'severity' => 'information', 'starts_at' => now()->toIso8601String(),
        ])->assertStatus(201);
    }

    public function test_banner_content_is_returned_as_plain_text_and_unsafe_link_schemes_are_rejected(): void
    {
        $admin = $this->makeUser('Admin');
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/admin/platform-announcements', [
            'title' => '<script>alert(1)</script>', 'message' => 'Safe message',
            'severity' => 'information', 'starts_at' => now()->toIso8601String(),
            'link_url' => 'javascript:alert(1)',
        ]);

        $response->assertStatus(422);
    }

    public function test_banner_rejects_a_protocol_relative_link(): void
    {
        // "//evil.com" would otherwise pass a naive "starts with /" check on
        // both the backend and the frontend, and browsers resolve it to
        // https://evil.com — found during the Batch 5 security review.
        $admin = $this->makeUser('Admin');
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/admin/platform-announcements', [
            'title' => 'Title', 'message' => 'Message', 'severity' => 'information',
            'starts_at' => now()->toIso8601String(), 'link_url' => '//evil.com',
        ]);

        $response->assertStatus(422);
    }

    public function test_banner_accepts_a_safe_internal_link(): void
    {
        $admin = $this->makeUser('Admin');
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/admin/platform-announcements', [
            'title' => 'Title', 'message' => 'Message', 'severity' => 'information',
            'is_active' => true, 'starts_at' => now()->toIso8601String(), 'link_url' => '/app/help',
        ]);

        $response->assertStatus(201)->assertJsonFragment(['link_url' => '/app/help']);
    }

    public function test_title_and_message_are_stored_and_returned_verbatim_not_html_rendered(): void
    {
        $admin = $this->makeUser('Admin');
        PlatformAnnouncement::create([
            'title' => 'Plain <b>text</b> title', 'message' => 'Plain message', 'severity' => 'information',
            'is_active' => true, 'starts_at' => now()->subHour(), 'created_by' => $admin->id,
        ]);

        Sanctum::actingAs($this->makeUser());
        $response = $this->getJson('/api/platform-announcements/active')->assertStatus(200);

        // Returned as-is (React/the frontend renders it as text, never HTML) —
        // the API itself doesn't need to strip tags, but must not add any.
        $this->assertSame('Plain <b>text</b> title', $response->json('data.title'));
    }
}
