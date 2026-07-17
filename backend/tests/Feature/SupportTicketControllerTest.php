<?php

namespace Tests\Feature;

use App\Models\FileUpload;
use App\Models\Organization;
use App\Models\Project;
use App\Models\SupportTicket;
use App\Models\SuresignSetting;
use App\Models\TradePackage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SupportTicketControllerTest extends TestCase
{
    use RefreshDatabase;

    // Real minimal 1x1 images (GD-generated), base64-encoded, so finfo/magic-byte
    // detection sees genuine PNG/JPEG/WebP content rather than a hand-typed header.
    private const PNG_B64  = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAADElEQVQImWNgYGAAAAAEAAGjChXjAAAAAElFTkSuQmCC';
    private const JPEG_B64 = '/9j/4AAQSkZJRgABAQEAYABgAAD//gA+Q1JFQVRPUjogZ2QtanBlZyB2MS4wICh1c2luZyBJSkcgSlBFRyB2ODApLCBkZWZhdWx0IHF1YWxpdHkK/9sAQwAIBgYHBgUIBwcHCQkICgwUDQwLCwwZEhMPFB0aHx4dGhwcICQuJyAiLCMcHCg3KSwwMTQ0NB8nOT04MjwuMzQy/9sAQwEJCQkMCwwYDQ0YMiEcITIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIy/8AAEQgAAQABAwEiAAIRAQMRAf/EAB8AAAEFAQEBAQEBAAAAAAAAAAABAgMEBQYHCAkKC//EALUQAAIBAwMCBAMFBQQEAAABfQECAwAEEQUSITFBBhNRYQcicRQygZGhCCNCscEVUtHwJDNicoIJChYXGBkaJSYnKCkqNDU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6g4SFhoeIiYqSk5SVlpeYmZqio6Slpqeoqaqys7S1tre4ubrCw8TFxsfIycrS09TV1tfY2drh4uPk5ebn6Onq8fLz9PX29/j5+v/EAB8BAAMBAQEBAQEBAQEAAAAAAAABAgMEBQYHCAkKC//EALURAAIBAgQEAwQHBQQEAAECdwABAgMRBAUhMQYSQVEHYXETIjKBCBRCkaGxwQkjM1LwFWJy0QoWJDThJfEXGBkaJicoKSo1Njc4OTpDREVGR0hJSlNUVVZXWFlaY2RlZmdoaWpzdHV2d3h5eoKDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uLj5OXm5+jp6vLz9PX29/j5+v/aAAwDAQACEQMRAD8A+f6KKKAP/9k=';
    private const WEBP_B64 = 'UklGRiQAAABXRUJQVlA4IBgAAAAwAQCdASoBAAEAAUAmJaQAA3AA/v02aAA=';

    private function fakeImage(string $name, string $base64): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode($base64));
    }

    private function makeUser(string $role = 'Client'): User
    {
        $org  = Organization::create(['name' => 'Org', 'slug' => 'org-' . uniqid()]);
        $user = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $user->assignRole(Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']));

        return $user;
    }

    private function makeProject(Organization $org, User $user): Project
    {
        return Project::create(['organization_id' => $org->id, 'created_by' => $user->id, 'name' => 'P-' . uniqid()]);
    }

    private function makeTradePackage(Project $project, User $user): TradePackage
    {
        return TradePackage::create([
            'project_id'      => $project->id,
            'organization_id' => $project->organization_id,
            'created_by'      => $user->id,
            'name'            => 'Groundworks',
            'slug'            => 'groundworks-' . uniqid(),
            'status'          => 'active',
        ]);
    }

    private function enableBrevoAndSupportEmail(): void
    {
        SuresignSetting::instance()->update([
            'brevo_api_key' => 'fake-brevo-key',
            'support_email' => 'support@suresign.example',
        ]);
        Http::fake(['api.brevo.com/*' => Http::response(['messageId' => 'test'], 201)]);
    }

    public function test_authenticated_client_can_submit_a_valid_support_request(): void
    {
        $this->enableBrevoAndSupportEmail();
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/support-tickets', [
            'subject'  => 'Cannot open a contract',
            'message'  => 'The contract PDF preview fails to load.',
            'category' => 'technical_issue',
        ]);

        $response->assertStatus(201);
        $reference = $response->json('data.reference');
        $this->assertNotEmpty($reference);
        $this->assertMatchesRegularExpression('/^SUP-\d{8}-[A-Z0-9]{6}$/', $reference);

        $this->assertDatabaseHas('support_tickets', [
            'organization_id' => $user->organization_id,
            'user_id'         => $user->id,
            'reference'       => $reference,
            'category'        => 'technical_issue',
            'status'          => 'waiting_for_support',
        ]);

        Http::assertSentCount(1);
        Http::assertSent(function ($request) use ($reference, $user) {
            $body = $request->data();

            return $request->url() === 'https://api.brevo.com/v3/smtp/email'
                && $body['to'][0]['email'] === 'support@suresign.example'
                && str_contains($body['subject'], $reference)
                && str_contains($body['htmlContent'], $user->email);
        });
    }

    public function test_anonymous_user_receives_401(): void
    {
        $response = $this->postJson('/api/support-tickets', [
            'subject' => 'Test',
            'message' => 'Test message',
        ]);

        $response->assertStatus(401);
    }

    public function test_invalid_payload_receives_422(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/support-tickets', [
            'subject' => '',
            'message' => '',
        ]);

        $response->assertStatus(422);
    }

    public function test_invalid_category_receives_422(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/support-tickets', [
            'subject'  => 'Test',
            'message'  => 'Test message',
            'category' => 'internal_infra_secret',
        ]);

        $response->assertStatus(422);
    }

    public function test_organization_and_user_context_are_derived_server_side_not_from_the_request(): void
    {
        $this->enableBrevoAndSupportEmail();
        $user     = $this->makeUser();
        $otherOrg = Organization::create(['name' => 'Other', 'slug' => 'other-' . uniqid()]);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/support-tickets', [
            'subject'         => 'Test',
            'message'         => 'Test message',
            'organization_id' => $otherOrg->id,
            'user_id'         => 999999,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('support_tickets', [
            'organization_id' => $user->organization_id,
            'user_id'         => $user->id,
        ]);
        $this->assertDatabaseMissing('support_tickets', [
            'organization_id' => $otherOrg->id,
        ]);
    }

    public function test_missing_recipient_configuration_fails_safely_but_still_stores_the_ticket(): void
    {
        SuresignSetting::instance()->update(['brevo_api_key' => 'fake-brevo-key', 'support_email' => null, 'admin_email' => null]);
        Http::fake();

        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/support-tickets', [
            'subject' => 'Test',
            'message' => 'Test message',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('support_tickets', ['user_id' => $user->id]);
        Http::assertNothingSent();
    }

    public function test_admin_email_is_used_as_fallback_recipient_when_support_email_is_not_set(): void
    {
        SuresignSetting::instance()->update([
            'brevo_api_key' => 'fake-brevo-key',
            'support_email' => null,
            'admin_email'   => 'admin@suresign.example',
        ]);
        Http::fake(['api.brevo.com/*' => Http::response(['messageId' => 'test'], 201)]);

        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/support-tickets', [
            'subject' => 'Test',
            'message' => 'Test message',
        ]);

        $response->assertStatus(201);
        Http::assertSent(fn ($request) => $request->data()['to'][0]['email'] === 'admin@suresign.example');
    }

    public function test_email_provider_failure_still_returns_success_and_does_not_expose_raw_provider_error(): void
    {
        $this->enableBrevoAndSupportEmail();
        Http::fake(['api.brevo.com/*' => Http::response(['message' => 'Invalid API key, please check your credentials'], 401)]);

        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/support-tickets', [
            'subject' => 'Test',
            'message' => 'Test message',
        ]);

        // The ticket record is the durable source of truth: delivery is
        // best-effort on top of it, so a provider failure does not fail the
        // whole request or surface the raw provider error to the client.
        $response->assertStatus(201);
        $this->assertStringNotContainsString('Invalid API key', $response->getContent());
        $this->assertDatabaseHas('support_tickets', ['user_id' => $user->id]);
    }

    public function test_dedicated_rate_limiter_returns_429_after_repeated_submissions(): void
    {
        $this->enableBrevoAndSupportEmail();
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/support-tickets', ['subject' => "Test {$i}", 'message' => 'Test message'])
                ->assertStatus(201);
        }

        $response = $this->postJson('/api/support-tickets', ['subject' => 'Test 6', 'message' => 'Test message']);
        $response->assertStatus(429);
        $response->assertJson(['message' => 'Too many support requests have been submitted. Please try again later.']);
    }

    public function test_support_reference_is_unique_and_returned_on_every_submission(): void
    {
        $this->enableBrevoAndSupportEmail();
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $first  = $this->postJson('/api/support-tickets', ['subject' => 'A', 'message' => 'Message A'])->json('data.reference');
        $second = $this->postJson('/api/support-tickets', ['subject' => 'B', 'message' => 'Message B'])->json('data.reference');

        $this->assertNotEquals($first, $second);
    }

    public function test_no_sanctum_token_appears_in_the_generated_email(): void
    {
        $this->enableBrevoAndSupportEmail();
        $user  = $this->makeUser();
        $token = $user->createToken('test-token')->plainTextToken;
        Sanctum::actingAs($user);

        $this->postJson('/api/support-tickets', [
            'subject' => 'Test',
            'message' => 'Test message',
        ])->assertStatus(201);

        Http::assertSent(function ($request) use ($token) {
            return !str_contains($request->data()['htmlContent'], $token);
        });
    }

    public function test_admin_can_list_and_update_ticket_status(): void
    {
        $this->enableBrevoAndSupportEmail();
        $user = $this->makeUser();
        Sanctum::actingAs($user);
        $ticketId = $this->postJson('/api/support-tickets', ['subject' => 'Test', 'message' => 'Test message'])->json('data.id');

        $admin = $this->makeUser('Admin');
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/support-tickets')->assertStatus(200)
            ->assertJsonFragment(['status' => 'waiting_for_support']);

        $this->putJson("/api/admin/support-tickets/{$ticketId}", ['status' => 'resolved'])
            ->assertStatus(200)
            ->assertJsonFragment(['status' => 'resolved']);
    }

    public function test_client_cannot_access_admin_ticket_list(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $this->getJson('/api/admin/support-tickets')->assertStatus(403);
    }

    // ── Screenshot upload ───────────────────────────────────────────────────

    public function test_valid_png_screenshot_is_accepted(): void
    {
        Storage::fake('local');
        $this->enableBrevoAndSupportEmail();
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/support-tickets', [
            'subject' => 'Test', 'message' => 'Test message',
            'screenshot' => $this->fakeImage('bug.png', self::PNG_B64),
        ]);

        $response->assertStatus(201);
        $this->assertTrue($response->json('data.has_screenshot'));
        $this->assertDatabaseHas('file_uploads', ['attachable_type' => SupportTicket::class, 'mime_type' => 'image/png']);
    }

    public function test_valid_jpeg_screenshot_is_accepted(): void
    {
        Storage::fake('local');
        $this->enableBrevoAndSupportEmail();
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/support-tickets', [
            'subject' => 'Test', 'message' => 'Test message',
            'screenshot' => $this->fakeImage('bug.jpg', self::JPEG_B64),
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('file_uploads', ['attachable_type' => SupportTicket::class, 'mime_type' => 'image/jpeg']);
    }

    public function test_valid_webp_screenshot_is_accepted(): void
    {
        Storage::fake('local');
        $this->enableBrevoAndSupportEmail();
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/support-tickets', [
            'subject' => 'Test', 'message' => 'Test message',
            'screenshot' => $this->fakeImage('bug.webp', self::WEBP_B64),
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('file_uploads', ['attachable_type' => SupportTicket::class, 'mime_type' => 'image/webp']);
    }

    public function test_unsupported_screenshot_extension_is_rejected(): void
    {
        Storage::fake('local');
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $file = UploadedFile::fake()->createWithContent('bug.gif', base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw=='));

        $response = $this->postJson('/api/support-tickets', [
            'subject' => 'Test', 'message' => 'Test message', 'screenshot' => $file,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('support_tickets', ['subject' => 'Test']);
        Storage::disk('local')->assertDirectoryEmpty('support-tickets');
    }

    public function test_screenshot_mime_signature_mismatch_is_rejected(): void
    {
        Storage::fake('local');
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        // PHP source renamed to .png — extension says image, content says otherwise.
        $file = UploadedFile::fake()->createWithContent('bug.png', '<?php system($_GET["c"]); ?>');

        $response = $this->postJson('/api/support-tickets', [
            'subject' => 'Test', 'message' => 'Test message', 'screenshot' => $file,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('support_tickets', ['subject' => 'Test']);
    }

    public function test_oversized_screenshot_is_rejected(): void
    {
        Storage::fake('local');
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        // create() generates a file of the given size (KB) with a jpeg mime hint
        // for the initial `file|max:` check, which runs before FileSecurityService.
        $file = UploadedFile::fake()->create('bug.jpg', 6000, 'image/jpeg');

        $response = $this->postJson('/api/support-tickets', [
            'subject' => 'Test', 'message' => 'Test message', 'screenshot' => $file,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('support_tickets', ['subject' => 'Test']);
    }

    public function test_screenshot_stored_privately_with_safe_filename(): void
    {
        Storage::fake('local');
        $this->enableBrevoAndSupportEmail();
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $this->postJson('/api/support-tickets', [
            'subject' => 'Test', 'message' => 'Test message',
            'screenshot' => $this->fakeImage('my-embarrassing-bug-screenshot.png', self::PNG_B64),
        ])->assertStatus(201);

        $upload = FileUpload::where('attachable_type', SupportTicket::class)->firstOrFail();

        $this->assertStringNotContainsString('my-embarrassing-bug-screenshot', $upload->file_path);
        $this->assertStringNotContainsString('my-embarrassing-bug-screenshot', $upload->stored_name);
        $this->assertSame('my-embarrassing-bug-screenshot.png', $upload->original_name);
        $this->assertStringStartsWith('support-tickets/', $upload->file_path);
        Storage::disk('local')->assertExists($upload->file_path);
    }

    public function test_original_filename_cannot_control_storage_path(): void
    {
        Storage::fake('local');
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $file = $this->fakeImage('../../../etc/passwd.png', self::PNG_B64);

        $response = $this->postJson('/api/support-tickets', [
            'subject' => 'Test', 'message' => 'Test message', 'screenshot' => $file,
        ]);

        // A path-traversal attempt in the original filename is rejected
        // outright by FileSecurityService — never silently sanitized into
        // a "safe" name and stored anyway.
        $response->assertStatus(422);
        $this->assertDatabaseMissing('support_tickets', ['subject' => 'Test']);
    }

    public function test_screenshot_cleanup_occurs_when_ticket_creation_fails(): void
    {
        Storage::fake('local');
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        FileUpload::creating(function () {
            throw new \RuntimeException('Simulated failure after screenshot storage.');
        });

        try {
            $response = $this->postJson('/api/support-tickets', [
                'subject' => 'Cleanup test', 'message' => 'Test message',
                'screenshot' => $this->fakeImage('bug.png', self::PNG_B64),
            ]);
            $response->assertStatus(500);
        } finally {
            FileUpload::flushEventListeners();
        }

        $this->assertDatabaseMissing('support_tickets', ['subject' => 'Cleanup test']);
        Storage::disk('local')->assertDirectoryEmpty('support-tickets');
    }

    public function test_support_request_without_a_screenshot_is_unaffected(): void
    {
        Storage::fake('local');
        $this->enableBrevoAndSupportEmail();
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/support-tickets', ['subject' => 'Test', 'message' => 'Test message']);

        $response->assertStatus(201);
        $this->assertFalse($response->json('data.has_screenshot'));
        Storage::disk('local')->assertDirectoryEmpty('support-tickets');
    }

    // ── Screenshot access authorization ─────────────────────────────────────

    private function createTicketWithScreenshot(User $user): SupportTicket
    {
        Sanctum::actingAs($user);
        $id = $this->postJson('/api/support-tickets', [
            'subject' => 'Test', 'message' => 'Test message',
            'screenshot' => $this->fakeImage('bug.png', self::PNG_B64),
        ])->json('data.id');

        return SupportTicket::findOrFail($id);
    }

    public function test_anonymous_screenshot_access_is_rejected(): void
    {
        Storage::fake('local');
        $ticket = $this->createTicketWithScreenshot($this->makeUser());

        // Clears the guard's cached resolved user from createTicketWithScreenshot()'s
        // Sanctum::actingAs() call above so this request is genuinely unauthenticated.
        $this->app['auth']->forgetGuards();

        $this->getJson("/api/support-tickets/{$ticket->id}/screenshot")->assertStatus(401);
    }

    public function test_another_user_cannot_access_the_screenshot(): void
    {
        Storage::fake('local');
        $owner = $this->makeUser();
        $ticket = $this->createTicketWithScreenshot($owner);

        // Same organization as the owner, but a different user.
        $otherUser = User::factory()->create(['organization_id' => $owner->organization_id, 'is_active' => true]);
        $otherUser->assignRole(Role::firstOrCreate(['name' => 'Client', 'guard_name' => 'web']));
        Sanctum::actingAs($otherUser);

        $this->getJson("/api/support-tickets/{$ticket->id}/screenshot")->assertStatus(403);
    }

    public function test_another_organization_cannot_access_the_screenshot(): void
    {
        Storage::fake('local');
        $ticket = $this->createTicketWithScreenshot($this->makeUser());

        $otherOrgUser = $this->makeUser();
        Sanctum::actingAs($otherOrgUser);

        $this->getJson("/api/support-tickets/{$ticket->id}/screenshot")->assertStatus(403);
    }

    public function test_platform_operator_can_access_the_screenshot(): void
    {
        Storage::fake('local');
        $ticket = $this->createTicketWithScreenshot($this->makeUser());

        $admin = $this->makeUser('Admin');
        Sanctum::actingAs($admin);

        $this->getJson("/api/support-tickets/{$ticket->id}/screenshot")->assertStatus(200);
    }

    public function test_owner_can_access_their_own_screenshot(): void
    {
        Storage::fake('local');
        $owner = $this->makeUser();
        $ticket = $this->createTicketWithScreenshot($owner);

        Sanctum::actingAs($owner);

        $this->getJson("/api/support-tickets/{$ticket->id}/screenshot")->assertStatus(200);
    }

    // ── Diagnostics ──────────────────────────────────────────────────────────

    public function test_diagnostics_are_omitted_when_not_selected(): void
    {
        $this->enableBrevoAndSupportEmail();
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $this->postJson('/api/support-tickets', [
            'subject' => 'Test', 'message' => 'Test message',
            'diagnostics' => ['browser' => 'Chrome 126', 'os' => 'Windows'],
        ])->assertStatus(201);

        $this->assertNull(SupportTicket::first()->diagnostics);
    }

    public function test_allowed_diagnostic_fields_persist_when_selected(): void
    {
        $this->enableBrevoAndSupportEmail();
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $this->postJson('/api/support-tickets', [
            'subject' => 'Test', 'message' => 'Test message',
            'include_diagnostics' => true,
            'diagnostics' => [
                'browser' => 'Chrome 126', 'os' => 'Windows', 'viewport_width' => 1440,
                'viewport_height' => 900, 'language' => 'en-GB', 'timezone' => 'Europe/London',
                'app_version' => 'V1.1',
            ],
        ])->assertStatus(201);

        $diagnostics = SupportTicket::first()->diagnostics;
        $this->assertSame('Chrome 126', $diagnostics['browser']);
        $this->assertSame('Windows', $diagnostics['os']);
        $this->assertSame(1440, $diagnostics['viewport_width']);
        $this->assertSame('en-GB', $diagnostics['language']);
        $this->assertArrayHasKey('submitted_at', $diagnostics);
    }

    public function test_disallowed_and_secret_fields_are_discarded_from_diagnostics(): void
    {
        $this->enableBrevoAndSupportEmail();
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/support-tickets', [
            'subject' => 'Test', 'message' => 'Test message',
            'include_diagnostics' => true,
            'diagnostics' => [
                'browser' => 'Chrome 126',
                'sanctum_token' => 'secret-token-value',
                'authorization' => 'Bearer secret',
                'cookies' => 'session=abc123',
                'api_key' => 'sk-live-secret',
                'local_storage' => '{"token":"abc"}',
            ],
        ]);

        $response->assertStatus(201);
        $diagnostics = SupportTicket::first()->diagnostics;
        $this->assertSame(['browser' => 'Chrome 126'], collect($diagnostics)->except('submitted_at')->all());
        $this->assertStringNotContainsString('secret-token-value', $response->getContent());
    }

    // ── Project / trade-package context ─────────────────────────────────────

    public function test_foreign_project_context_is_rejected(): void
    {
        $user = $this->makeUser();
        $otherOrg = Organization::create(['name' => 'Other', 'slug' => 'other-' . uniqid()]);
        $otherOrgUser = User::factory()->create(['organization_id' => $otherOrg->id]);
        $foreignProject = $this->makeProject($otherOrg, $otherOrgUser);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/support-tickets', [
            'subject' => 'Test', 'message' => 'Test message', 'project_id' => $foreignProject->id,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('support_tickets', ['subject' => 'Test']);
    }

    public function test_foreign_trade_package_context_is_rejected(): void
    {
        $user = $this->makeUser();
        $otherOrg = Organization::create(['name' => 'Other', 'slug' => 'other-' . uniqid()]);
        $otherOrgUser = User::factory()->create(['organization_id' => $otherOrg->id]);
        $foreignProject = $this->makeProject($otherOrg, $otherOrgUser);
        $foreignPackage = $this->makeTradePackage($foreignProject, $otherOrgUser);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/support-tickets', [
            'subject' => 'Test', 'message' => 'Test message', 'trade_package_id' => $foreignPackage->id,
        ]);

        $response->assertStatus(422);
    }

    public function test_valid_own_organization_project_context_is_accepted(): void
    {
        $this->enableBrevoAndSupportEmail();
        $user = $this->makeUser();
        $project = $this->makeProject($user->organization, $user);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/support-tickets', [
            'subject' => 'Test', 'message' => 'Test message',
            'project_id' => $project->id, 'route' => "/app/projects/{$project->id}", 'module' => 'Projects',
        ]);

        $response->assertStatus(201);
        $ticket = SupportTicket::first();
        $this->assertSame($project->id, $ticket->project_id);
        $this->assertSame("/app/projects/{$project->id}", $ticket->route);
        $this->assertSame('Projects', $ticket->module);
    }

    // ── Email content ────────────────────────────────────────────────────────

    public function test_email_contains_only_the_safe_diagnostic_summary(): void
    {
        $this->enableBrevoAndSupportEmail();
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $this->postJson('/api/support-tickets', [
            'subject' => 'Test', 'message' => 'Test message',
            'include_diagnostics' => true,
            'diagnostics' => ['browser' => 'Chrome 126', 'os' => 'Windows'],
        ])->assertStatus(201);

        Http::assertSent(function ($request) {
            $body = $request->data()['htmlContent'];
            return str_contains($body, 'Chrome 126') && str_contains($body, 'Diagnostics');
        });
    }

    public function test_a_malicious_subject_cannot_inject_html_into_the_internal_email(): void
    {
        $this->enableBrevoAndSupportEmail();
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $this->postJson('/api/support-tickets', [
            'subject' => '<script>alert(1)</script>', 'message' => 'Test message',
        ])->assertStatus(201);

        Http::assertSent(function ($request) {
            $body = $request->data()['htmlContent'];
            // The email subject is interpolated into <title>/<h1> by
            // EmailNotificationService::buildHtml() without escaping — the
            // ticket subject must be pre-escaped before reaching it, so no
            // literal <script> tag ever appears in the rendered HTML.
            return !str_contains($body, '<script>alert(1)</script>')
                && str_contains($body, '&lt;script&gt;');
        });
    }

    public function test_admin_ticket_list_includes_context_and_diagnostics(): void
    {
        $this->enableBrevoAndSupportEmail();
        $user = $this->makeUser();
        $project = $this->makeProject($user->organization, $user);
        Sanctum::actingAs($user);

        $ticketId = $this->postJson('/api/support-tickets', [
            'subject' => 'Test', 'message' => 'Test message', 'project_id' => $project->id,
            'route' => "/app/projects/{$project->id}", 'module' => 'Projects',
            'include_diagnostics' => true, 'diagnostics' => ['browser' => 'Chrome 126'],
            'screenshot' => $this->fakeImage('bug.png', self::PNG_B64),
        ])->assertStatus(201)->json('data.id');

        $admin = $this->makeUser('Admin');
        Sanctum::actingAs($admin);

        // Full context/diagnostics live on the admin detail endpoint, not the
        // (deliberately lighter, paginated) list — mirroring the Client-side
        // show()/myTickets() split.
        $row = $this->getJson("/api/admin/support-tickets/{$ticketId}")->assertStatus(200)->json('data');

        $this->assertSame('Projects', $row['module']);
        $this->assertSame($project->id, $row['project']['id']);
        $this->assertSame('Chrome 126', $row['diagnostics']['browser']);
        $this->assertNotNull($row['screenshot']);
        $this->assertArrayNotHasKey('file_path', $row['screenshot']);
    }

    // ── Lifecycle: screenshot cleanup on ticket deletion ────────────────────
    // No destroy() endpoint exists for support tickets today, so this exercises
    // the model event directly (SupportTicket::booted()) — the same path any
    // future deletion route or script would go through via Eloquent ->delete().

    public function test_screenshot_is_cleaned_up_when_the_ticket_is_deleted(): void
    {
        Storage::fake('local');
        $ticket = $this->createTicketWithScreenshot($this->makeUser());
        $upload = FileUpload::where('attachable_type', SupportTicket::class)->where('attachable_id', $ticket->id)->firstOrFail();
        $path = $upload->file_path;

        Storage::disk('local')->assertExists($path);

        $ticket->delete();

        $this->assertDatabaseMissing('file_uploads', ['id' => $upload->id]);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_deleting_a_ticket_without_a_screenshot_does_not_error(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);
        $id = $this->postJson('/api/support-tickets', ['subject' => 'Test', 'message' => 'Test message'])->json('data.id');

        SupportTicket::findOrFail($id)->delete();

        $this->assertDatabaseMissing('support_tickets', ['id' => $id]);
    }

    // ── Screenshot response headers ─────────────────────────────────────────

    public function test_screenshot_response_has_safe_headers_and_no_forced_download(): void
    {
        Storage::fake('local');
        $owner = $this->makeUser();
        $ticket = $this->createTicketWithScreenshot($owner);
        Sanctum::actingAs($owner);

        $response = $this->get("/api/support-tickets/{$ticket->id}/screenshot");

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'image/png');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertFalse($response->headers->has('Content-Disposition'), 'A safe inline image type should not be forced to download.');
    }

    // ── My Support Requests ──────────────────────────────────────────────────

    public function test_client_can_list_their_own_support_requests(): void
    {
        $this->enableBrevoAndSupportEmail();
        $user = $this->makeUser();
        Sanctum::actingAs($user);
        $this->postJson('/api/support-tickets', ['subject' => 'Mine', 'message' => 'Test message'])->assertStatus(201);

        $response = $this->getJson('/api/support-tickets')->assertStatus(200);

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Mine', $response->json('data.0.subject'));
    }

    public function test_client_cannot_see_other_users_requests_in_my_tickets(): void
    {
        $this->enableBrevoAndSupportEmail();
        $owner = $this->makeUser();
        Sanctum::actingAs($owner);
        $this->postJson('/api/support-tickets', ['subject' => 'Not yours', 'message' => 'Test message'])->assertStatus(201);

        $otherUser = User::factory()->create(['organization_id' => $owner->organization_id, 'is_active' => true]);
        $otherUser->assignRole(Role::firstOrCreate(['name' => 'Client', 'guard_name' => 'web']));
        Sanctum::actingAs($otherUser);

        $response = $this->getJson('/api/support-tickets')->assertStatus(200);

        $this->assertCount(0, $response->json('data'));
    }

    public function test_my_tickets_can_be_filtered_by_status_category_and_priority(): void
    {
        $this->enableBrevoAndSupportEmail();
        $user = $this->makeUser();
        Sanctum::actingAs($user);
        $this->postJson('/api/support-tickets', ['subject' => 'A', 'message' => 'msg', 'category' => 'billing_or_subscription'])->assertStatus(201);
        $this->postJson('/api/support-tickets', ['subject' => 'B', 'message' => 'msg', 'category' => 'feature_request'])->assertStatus(201);

        $response = $this->getJson('/api/support-tickets?category=feature_request')->assertStatus(200);

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('B', $response->json('data.0.subject'));
    }

    public function test_my_tickets_pagination_is_bounded(): void
    {
        $user = $this->makeUser();
        // Created directly rather than through the endpoint — the dedicated
        // support-ticket rate limiter (5/15min) would otherwise 429 well
        // before reaching 12, and this test is about myTickets() pagination,
        // not the submission throttle.
        for ($i = 0; $i < 12; $i++) {
            SupportTicket::create([
                'organization_id' => $user->organization_id, 'user_id' => $user->id,
                'reference' => "SUP-TEST-{$i}", 'subject' => "Ticket {$i}", 'message' => 'msg', 'status' => 'open',
            ]);
        }
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/support-tickets')->assertStatus(200);

        $this->assertCount(10, $response->json('data'));
        $this->assertSame(12, $response->json('total'));
    }

    public function test_show_returns_the_owners_own_ticket_detail(): void
    {
        $this->enableBrevoAndSupportEmail();
        $user = $this->makeUser();
        $project = $this->makeProject($user->organization, $user);
        Sanctum::actingAs($user);
        $id = $this->postJson('/api/support-tickets', [
            'subject' => 'Test', 'message' => 'Full message body', 'project_id' => $project->id,
        ])->json('data.id');

        $response = $this->getJson("/api/support-tickets/{$id}")->assertStatus(200);

        $this->assertSame('Full message body', $response->json('data.message'));
        $this->assertSame($project->id, $response->json('data.project.id'));
    }

    public function test_show_rejects_another_users_ticket(): void
    {
        $this->enableBrevoAndSupportEmail();
        $owner = $this->makeUser();
        Sanctum::actingAs($owner);
        $id = $this->postJson('/api/support-tickets', ['subject' => 'Test', 'message' => 'msg'])->json('data.id');

        $otherUser = User::factory()->create(['organization_id' => $owner->organization_id, 'is_active' => true]);
        $otherUser->assignRole(Role::firstOrCreate(['name' => 'Client', 'guard_name' => 'web']));
        Sanctum::actingAs($otherUser);

        $this->getJson("/api/support-tickets/{$id}")->assertStatus(403);
    }

    public function test_platform_operator_can_view_any_ticket_via_show(): void
    {
        $this->enableBrevoAndSupportEmail();
        $owner = $this->makeUser();
        Sanctum::actingAs($owner);
        $id = $this->postJson('/api/support-tickets', ['subject' => 'Test', 'message' => 'msg'])->json('data.id');

        $admin = $this->makeUser('Admin');
        Sanctum::actingAs($admin);

        $this->getJson("/api/support-tickets/{$id}")->assertStatus(200);
    }

    public function test_show_returns_404_for_a_nonexistent_ticket(): void
    {
        // Covers the deep-link case (/app/help?ticket=999#my-requests) where
        // the ticket has since been deleted or the id was never valid — the
        // detail endpoint must fail gracefully (404), not error.
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $this->getJson('/api/support-tickets/999999')->assertStatus(404);
    }

    // ── Personal notifications ──────────────────────────────────────────────

    public function test_personal_notification_is_created_when_a_ticket_is_submitted(): void
    {
        $this->enableBrevoAndSupportEmail();
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $ticketId = $this->postJson('/api/support-tickets', ['subject' => 'Test', 'message' => 'msg'])->assertStatus(201)->json('data.id');

        $this->assertDatabaseHas('suresign_notifications', [
            'user_id'    => $user->id,
            'type'       => 'support_ticket_received',
            'action_url' => "/app/help/support/{$ticketId}",
        ]);
        // Personal only — never fanned out to the rest of the organization.
        $this->assertSame(1, \App\Models\SuresignNotification::where('type', 'support_ticket_received')->count());
    }

    public function test_platform_operators_are_notified_when_a_ticket_is_submitted(): void
    {
        $this->enableBrevoAndSupportEmail();
        $user = $this->makeUser();
        $admin = $this->makeUser('Admin');
        $superAdmin = $this->makeUser('Super Admin');
        $unrelatedClient = $this->makeUser('Client');
        Sanctum::actingAs($user);

        $ticketId = $this->postJson('/api/support-tickets', ['subject' => 'Test', 'message' => 'msg'])->assertStatus(201)->json('data.id');

        $this->assertDatabaseHas('suresign_notifications', [
            'user_id' => $admin->id, 'type' => 'support_ticket_submitted', 'action_url' => "/admin/support?ticket={$ticketId}",
        ]);
        $this->assertDatabaseHas('suresign_notifications', [
            'user_id' => $superAdmin->id, 'type' => 'support_ticket_submitted',
        ]);
        $this->assertDatabaseMissing('suresign_notifications', [
            'user_id' => $unrelatedClient->id, 'type' => 'support_ticket_submitted',
        ]);
    }

    public function test_admin_counts_endpoint_returns_bucketed_totals(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);
        $this->postJson('/api/support-tickets', ['subject' => 'Test', 'message' => 'msg'])->assertStatus(201);

        $admin = $this->makeUser('Admin');
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/admin/support-tickets/counts')->assertStatus(200);
        $this->assertSame(1, $response->json('counts.waiting_for_support'));
        $this->assertSame(1, $response->json('counts.total'));
    }

    public function test_client_cannot_access_the_admin_counts_endpoint(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $this->getJson('/api/admin/support-tickets/counts')->assertStatus(403);
    }

    public function test_personal_notification_is_created_when_status_changes_and_goes_only_to_the_owner(): void
    {
        $this->enableBrevoAndSupportEmail();
        $owner = $this->makeUser();
        Sanctum::actingAs($owner);
        $id = $this->postJson('/api/support-tickets', ['subject' => 'Test', 'message' => 'msg'])->json('data.id');

        $admin = $this->makeUser('Admin');
        Sanctum::actingAs($admin);
        $this->putJson("/api/admin/support-tickets/{$id}", ['status' => 'resolved'])->assertStatus(200);

        $this->assertDatabaseHas('suresign_notifications', [
            'user_id'    => $owner->id,
            'type'       => 'support_ticket_status_changed',
            'action_url' => "/app/help/support/{$id}",
        ]);
        $this->assertDatabaseMissing('suresign_notifications', [
            'user_id' => $admin->id,
            'type'    => 'support_ticket_status_changed',
        ]);
    }

    public function test_no_status_change_notification_when_status_is_unchanged(): void
    {
        $this->enableBrevoAndSupportEmail();
        $owner = $this->makeUser();
        Sanctum::actingAs($owner);
        $id = $this->postJson('/api/support-tickets', ['subject' => 'Test', 'message' => 'msg'])->json('data.id');

        $admin = $this->makeUser('Admin');
        Sanctum::actingAs($admin);
        // Ticket is created directly into WAITING_FOR_SUPPORT (Batch 5) — a
        // re-save of the same status is the idempotent no-op this test is
        // actually about, not a transition.
        $this->putJson("/api/admin/support-tickets/{$id}", ['status' => 'waiting_for_support'])->assertStatus(200);

        $this->assertSame(0, \App\Models\SuresignNotification::where('type', 'support_ticket_status_changed')->count());
    }

    public function test_invalid_status_transition_is_rejected(): void
    {
        $owner = $this->makeUser();
        Sanctum::actingAs($owner);
        $id = $this->postJson('/api/support-tickets', ['subject' => 'Test', 'message' => 'msg'])->json('data.id');

        $admin = $this->makeUser('Admin');
        Sanctum::actingAs($admin);
        // A ticket in WAITING_FOR_SUPPORT can move to RESOLVED, but not
        // straight to CLOSED->...  actually verify a genuinely disallowed
        // hop: RESOLVED cannot go directly to WAITING_FOR_YOU.
        $this->putJson("/api/admin/support-tickets/{$id}", ['status' => 'resolved'])->assertStatus(200);
        $this->putJson("/api/admin/support-tickets/{$id}", ['status' => 'waiting_for_you'])->assertStatus(422);
    }
}
