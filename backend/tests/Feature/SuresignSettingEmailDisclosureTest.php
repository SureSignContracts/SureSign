<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\SuresignSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Covers the M7 fix in SuresignSettingController::testEmail(): the 400/else
 * branches used to embed Brevo's raw response message/body directly in the
 * 422 JSON, and the catch block returned $e->getMessage() at 500. Both are
 * now generic, provider-neutral messages; the real detail is still logged.
 */
class SuresignSettingEmailDisclosureTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsSuperAdmin(): User
    {
        $org = Organization::create(['name' => 'Org', 'slug' => 'org-' . uniqid()]);
        $user = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_brevo_400_response_does_not_leak_the_raw_provider_message(): void
    {
        $this->actingAsSuperAdmin();
        SuresignSetting::instance()->update(['brevo_api_key' => 'fake-brevo-key']);

        Http::fake([
            'api.brevo.com/*' => Http::response([
                'code'    => 'invalid_parameter',
                'message' => 'sender.email must be a verified domain: internal-audit@example-corp.local',
            ], 400),
        ]);

        $response = $this->postJson('/api/admin/suresign-settings/test-email', ['to' => 'test@example.com']);

        $response->assertStatus(422)
            ->assertJson(['message' => 'Brevo rejected the test email request. Please check your email configuration.']);
        $response->assertJsonMissing(['message' => fn ($m) => str_contains((string) $m, 'example-corp.local')]);
        $this->assertStringNotContainsString('example-corp.local', $response->getContent());
    }

    public function test_brevo_unhandled_status_does_not_leak_the_raw_response_body(): void
    {
        $this->actingAsSuperAdmin();
        SuresignSetting::instance()->update(['brevo_api_key' => 'fake-brevo-key']);

        Http::fake([
            'api.brevo.com/*' => Http::response('internal server details: db=brevo-prod-7 trace=8f21', 503),
        ]);

        $response = $this->postJson('/api/admin/suresign-settings/test-email', ['to' => 'test@example.com']);

        $response->assertStatus(422)
            ->assertJson(['message' => 'Brevo returned an error while sending the test email.']);
        $this->assertStringNotContainsString('brevo-prod-7', $response->getContent());
        $this->assertStringNotContainsString('trace=8f21', $response->getContent());
    }

    public function test_thrown_exception_during_send_returns_a_generic_message_and_is_logged(): void
    {
        Log::spy();
        $user = $this->actingAsSuperAdmin();
        SuresignSetting::instance()->update(['brevo_api_key' => 'fake-brevo-key']);

        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('cURL error 6: Could not resolve host: api.brevo.com (internal DNS resolver failure)');
        });

        $response = $this->postJson('/api/admin/suresign-settings/test-email', ['to' => 'test@example.com']);

        $response->assertStatus(500)
            ->assertJson(['message' => 'The test email could not be sent.']);
        $this->assertStringNotContainsString('cURL error', $response->getContent());
        $this->assertStringNotContainsString('resolve host', $response->getContent());

        Log::shouldHaveReceived('error')
            ->withArgs(fn ($message, $context) =>
                $message === 'Brevo test email exception'
                && $context['user_id'] === $user->id
                && isset($context['exception'])
            )
            ->once();
    }

    public function test_missing_api_key_returns_the_existing_curated_message_unchanged(): void
    {
        $this->actingAsSuperAdmin();
        // brevo_api_key left empty -- pre-existing, deliberate business message.

        $response = $this->postJson('/api/admin/suresign-settings/test-email', ['to' => 'test@example.com']);

        $response->assertStatus(422)->assertJson(['message' => 'No Brevo API key configured.']);
    }
}
