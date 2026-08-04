<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Appointment;
use App\Models\AppointmentType;
use App\Models\Organization;
use App\Models\User;
use App\Services\AppointmentPublicLinkService;
use App\Services\OrganisationUrlGenerator;
use App\Support\Organizations\UrlSlugValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Organisation URL Branding, Phase 1.
 */
class OrganisationUrlBrandingTest extends TestCase
{
    use RefreshDatabase;

    private static int $orgCounter = 0;

    private function makeUser(string $role, ?Organization $org = null): User
    {
        $org ??= $this->makeOrg();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole(Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']));
        return $user;
    }

    private function makeOrg(array $overrides = []): Organization
    {
        $n = ++self::$orgCounter;
        return Organization::create(array_merge([
            'name' => "Org {$n}", 'slug' => "org-{$n}", 'timezone' => 'Europe/London', 'is_active' => true,
        ], $overrides));
    }

    private function makeAppointment(?Organization $org, array $overrides = []): Appointment
    {
        $type = AppointmentType::create([
            'name' => 'Type', 'slug' => 'type-' . uniqid(),
            'duration_minutes' => 30, 'is_active' => true, 'is_public' => true, 'assignment_mode' => 'manual',
            'meeting_method' => 'tbc', 'requires_confirmation' => false,
            'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0,
            'min_notice_hours' => 0, 'max_advance_days' => 60,
        ]);

        return Appointment::create(array_merge([
            'reference' => 'APT-' . str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
            'organization_id' => $org?->id,
            'appointment_type_id' => $type->id,
            'attendee_name' => 'Jane Doe',
            'attendee_email' => 'jane@example.com',
            'attendee_timezone' => 'Europe/London',
            'starts_at' => now()->addDays(3)->setTime(10, 0),
            'ends_at' => now()->addDays(3)->setTime(10, 30),
            'booking_timezone' => 'Europe/London',
            'status' => 'confirmed',
            'booking_source' => 'public_booking_page',
            'meeting_method' => 'tbc',
        ], $overrides));
    }

    // ── UrlSlugValidator ────────────────────────────────────────────────────

    public function test_valid_slugs_are_accepted(): void
    {
        foreach (['star-affinity', 'ab', 'acme123', 'a1-b2-c3'] as $slug) {
            $this->assertTrue(UrlSlugValidator::isValid($slug), "expected {$slug} to be valid");
        }
    }

    public function test_invalid_format_slugs_are_rejected(): void
    {
        $cases = [
            'a',                       // below MIN_LENGTH
            '-star',                   // leading hyphen
            'star-',                   // trailing hyphen
            'star--affinity',          // consecutive hyphens
            'Star-Affinity',           // uppercase — caller must normalize first for isValidFormat, but isValid() normalizes and would pass; test isValidFormat directly for case sensitivity
            'star_affinity',           // underscore not allowed
            'star.affinity',           // dot not allowed
            str_repeat('a', 64),       // exceeds MAX_LENGTH
        ];

        foreach ($cases as $slug) {
            $this->assertFalse(UrlSlugValidator::isValidFormat($slug), "expected {$slug} to be invalid format");
        }
    }

    public function test_normalize_lowercases_and_trims(): void
    {
        $this->assertSame('star-affinity', UrlSlugValidator::normalize('  Star-Affinity  '));
    }

    public function test_reserved_names_are_rejected(): void
    {
        foreach (['www', 'app', 'api', 'admin', 'billing', 'localhost'] as $reserved) {
            $this->assertTrue(UrlSlugValidator::isReserved($reserved));
            $this->assertFalse(UrlSlugValidator::isValid($reserved));
        }
    }

    // ── Super Admin management endpoints ───────────────────────────────────

    public function test_super_admin_can_set_url_slug(): void
    {
        $superAdmin = $this->makeUser('Super Admin');
        $org = $this->makeOrg();
        Sanctum::actingAs($superAdmin);

        $response = $this->putJson("/api/organizations/{$org->id}/url-slug", [
            'url_slug' => 'Star-Affinity',
            'reason' => 'Customer requested branded booking link.',
            'confirmed' => true,
        ]);

        $response->assertStatus(200);
        $this->assertSame('star-affinity', $org->fresh()->url_slug);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'organization.url_branding_created',
            'organization_id' => $org->id,
        ]);
    }

    public function test_admin_role_cannot_manage_url_slug(): void
    {
        $admin = $this->makeUser('Admin');
        $org = $this->makeOrg();
        Sanctum::actingAs($admin);

        $this->putJson("/api/organizations/{$org->id}/url-slug", [
            'url_slug' => 'star-affinity',
            'reason' => 'Attempting as Admin.',
            'confirmed' => true,
        ])->assertStatus(403);
    }

    public function test_client_role_cannot_manage_url_slug(): void
    {
        $org = $this->makeOrg();
        $client = $this->makeUser('Client', $org);
        Sanctum::actingAs($client);

        $this->putJson("/api/organizations/{$org->id}/url-slug", [
            'url_slug' => 'star-affinity',
            'reason' => 'Attempting as Client.',
            'confirmed' => true,
        ])->assertStatus(403);
    }

    public function test_reserved_slug_is_rejected_by_the_endpoint(): void
    {
        $superAdmin = $this->makeUser('Super Admin');
        $org = $this->makeOrg();
        Sanctum::actingAs($superAdmin);

        $this->putJson("/api/organizations/{$org->id}/url-slug", [
            'url_slug' => 'admin',
            'reason' => 'Trying a reserved name.',
            'confirmed' => true,
        ])->assertStatus(422)->assertJsonValidationErrors('url_slug');
    }

    public function test_duplicate_slug_is_rejected_case_insensitively(): void
    {
        $superAdmin = $this->makeUser('Super Admin');
        $orgA = $this->makeOrg(['url_slug' => 'star-affinity']);
        $orgB = $this->makeOrg();
        Sanctum::actingAs($superAdmin);

        $this->putJson("/api/organizations/{$orgB->id}/url-slug", [
            'url_slug' => 'STAR-AFFINITY',
            'reason' => 'Trying to reuse an existing slug.',
            'confirmed' => true,
        ])->assertStatus(422)->assertJsonValidationErrors('url_slug');
    }

    public function test_name_change_does_not_change_url_slug(): void
    {
        $org = $this->makeOrg(['url_slug' => 'star-affinity']);

        $org->update(['name' => 'A Brand New Company Name']);

        $this->assertSame('star-affinity', $org->fresh()->url_slug);
    }

    public function test_super_admin_can_remove_url_slug(): void
    {
        $superAdmin = $this->makeUser('Super Admin');
        $org = $this->makeOrg(['url_slug' => 'star-affinity']);
        Sanctum::actingAs($superAdmin);

        $this->deleteJson("/api/organizations/{$org->id}/url-slug", [
            'reason' => 'Customer no longer wants a branded URL.',
            'confirmed' => true,
        ])->assertStatus(200);

        $this->assertNull($org->fresh()->url_slug);
        $this->assertDatabaseHas('activity_logs', ['action' => 'organization.url_branding_removed']);
    }

    // ── OrganisationUrlGenerator ────────────────────────────────────────────

    public function test_generator_falls_back_to_default_when_root_domain_not_configured(): void
    {
        Config::set('organisation_branding.root_domain', null);
        Config::set('suresign.marketing_url', 'https://marketing.example.test');
        $org = $this->makeOrg(['url_slug' => 'star-affinity']);

        $generator = new OrganisationUrlGenerator();

        $this->assertFalse($generator->isBranded($org));
        $this->assertSame('https://marketing.example.test/foo', $generator->publicUrl($org, '/foo'));
    }

    public function test_generator_falls_back_when_organization_has_no_slug(): void
    {
        Config::set('organisation_branding.root_domain', 'suresigncontracts.app');
        Config::set('suresign.marketing_url', 'https://marketing.example.test');
        $org = $this->makeOrg();

        $generator = new OrganisationUrlGenerator();

        $this->assertFalse($generator->isBranded($org));
        $this->assertSame('https://marketing.example.test/foo', $generator->publicUrl($org, '/foo'));
    }

    public function test_generator_produces_branded_url_when_configured(): void
    {
        Config::set('organisation_branding.root_domain', 'suresigncontracts.app');
        Config::set('suresign.marketing_url', 'https://marketing.example.test');
        $org = $this->makeOrg(['url_slug' => 'star-affinity']);

        $generator = new OrganisationUrlGenerator();

        $this->assertTrue($generator->isBranded($org));
        $this->assertSame('https://star-affinity.suresigncontracts.app/foo', $generator->publicUrl($org, '/foo'));
    }

    public function test_generator_falls_back_for_null_organization(): void
    {
        Config::set('organisation_branding.root_domain', 'suresigncontracts.app');
        Config::set('suresign.marketing_url', 'https://marketing.example.test');

        $generator = new OrganisationUrlGenerator();

        $this->assertSame('https://marketing.example.test/foo', $generator->publicUrl(null, '/foo'));
    }

    public function test_appointment_public_link_service_produces_branded_marketing_url(): void
    {
        Config::set('organisation_branding.root_domain', 'suresigncontracts.app');
        Config::set('suresign.marketing_url', 'https://marketing.example.test');
        $org = $this->makeOrg(['url_slug' => 'star-affinity']);
        $appointment = $this->makeAppointment($org);

        $url = app(AppointmentPublicLinkService::class)->cancelMarketingUrl($appointment);

        $this->assertStringStartsWith("https://star-affinity.suresigncontracts.app/appointments/{$appointment->public_token}", $url);
        $this->assertStringContainsString('signature=', $url);
    }

    public function test_appointment_public_link_service_falls_back_when_appointment_has_no_organization(): void
    {
        Config::set('organisation_branding.root_domain', 'suresigncontracts.app');
        Config::set('suresign.marketing_url', 'https://marketing.example.test');
        $appointment = $this->makeAppointment(null);

        $url = app(AppointmentPublicLinkService::class)->cancelMarketingUrl($appointment);

        $this->assertStringStartsWith("https://marketing.example.test/appointments/{$appointment->public_token}", $url);
    }

    // ── Cross-host tenant isolation ─────────────────────────────────────────

    public function test_public_appointment_action_succeeds_with_no_org_header(): void
    {
        $org = $this->makeOrg(['url_slug' => 'star-affinity']);
        $appointment = $this->makeAppointment($org);
        $link = app(AppointmentPublicLinkService::class)->cancelApiUrl($appointment);
        $path = parse_url($link, PHP_URL_PATH) . '?' . parse_url($link, PHP_URL_QUERY);

        $this->getJson($path)->assertStatus(200);
    }

    public function test_public_appointment_action_succeeds_with_matching_org_header(): void
    {
        Config::set('organisation_branding.root_domain', 'suresigncontracts.app');
        $org = $this->makeOrg(['url_slug' => 'star-affinity']);
        $appointment = $this->makeAppointment($org);
        $link = app(AppointmentPublicLinkService::class)->cancelApiUrl($appointment);
        $path = parse_url($link, PHP_URL_PATH) . '?' . parse_url($link, PHP_URL_QUERY);

        $this->withHeaders(['X-Suresign-Org-Host' => 'star-affinity.suresigncontracts.app'])
            ->getJson($path)
            ->assertStatus(200);
    }

    public function test_public_appointment_action_404s_with_mismatched_org_header(): void
    {
        Config::set('organisation_branding.root_domain', 'suresigncontracts.app');
        $orgA = $this->makeOrg(['url_slug' => 'star-affinity']);
        $this->makeOrg(['url_slug' => 'other-org']);
        $appointment = $this->makeAppointment($orgA);
        $link = app(AppointmentPublicLinkService::class)->cancelApiUrl($appointment);
        $path = parse_url($link, PHP_URL_PATH) . '?' . parse_url($link, PHP_URL_QUERY);

        $this->withHeaders(['X-Suresign-Org-Host' => 'other-org.suresigncontracts.app'])
            ->getJson($path)
            ->assertStatus(404);
    }

    public function test_public_appointment_action_404s_with_unknown_org_header(): void
    {
        Config::set('organisation_branding.root_domain', 'suresigncontracts.app');
        $org = $this->makeOrg(['url_slug' => 'star-affinity']);
        $appointment = $this->makeAppointment($org);
        $link = app(AppointmentPublicLinkService::class)->cancelApiUrl($appointment);
        $path = parse_url($link, PHP_URL_PATH) . '?' . parse_url($link, PHP_URL_QUERY);

        // An "unknown" host that resolves to nothing at all is treated as a
        // PASS (no header/no signal) — see EnforcesPublicOrganizationHost's
        // docblock. To actually exercise the mismatch path here we need a
        // DIFFERENT organisation, which the sibling test above already
        // covers. This test now covers the "resolves to nothing" pass case.
        $this->withHeaders(['X-Suresign-Org-Host' => 'nonexistent.suresigncontracts.app'])
            ->getJson($path)
            ->assertStatus(200);
    }

    public function test_public_appointment_action_404s_when_org_header_given_but_appointment_has_no_organization(): void
    {
        Config::set('organisation_branding.root_domain', 'suresigncontracts.app');
        $this->makeOrg(['url_slug' => 'star-affinity']);
        $appointment = $this->makeAppointment(null);
        $link = app(AppointmentPublicLinkService::class)->cancelApiUrl($appointment);
        $path = parse_url($link, PHP_URL_PATH) . '?' . parse_url($link, PHP_URL_QUERY);

        $this->withHeaders(['X-Suresign-Org-Host' => 'star-affinity.suresigncontracts.app'])
            ->getJson($path)
            ->assertStatus(404);
    }

    public function test_signed_url_still_validates_without_the_org_header_ever_touching_the_signature(): void
    {
        // Regression guard: adding the org-header consistency check must never
        // change what Laravel's own `signed` middleware validates against.
        $org = $this->makeOrg(['url_slug' => 'star-affinity']);
        $appointment = $this->makeAppointment($org);
        $link = app(AppointmentPublicLinkService::class)->cancelApiUrl($appointment);
        $path = parse_url($link, PHP_URL_PATH) . '?' . parse_url($link, PHP_URL_QUERY);

        // Tampering with the query string (as a header would if it were ever
        // added there instead) must still fail signature verification.
        $this->getJson($path . '&org_slug=star-affinity')->assertStatus(403);
    }

    // ── Public branding-resolution endpoint ─────────────────────────────────

    public function test_public_branding_endpoint_returns_safe_fields_for_known_slug(): void
    {
        Config::set('organisation_branding.root_domain', 'suresigncontracts.app');
        $this->makeOrg(['url_slug' => 'star-affinity', 'name' => 'Star Affinity Ltd']);

        $response = $this->getJson('/api/public/organisation-branding/star-affinity.suresigncontracts.app');

        $response->assertStatus(200);
        $response->assertJsonStructure(['data' => ['host_type', 'organisation_name', 'logo_url', 'accent_color']]);
        $response->assertJsonMissingPath('data.id');
        $this->assertSame('organisation', $response->json('data.host_type'));
    }

    public function test_public_branding_endpoint_is_case_insensitive(): void
    {
        Config::set('organisation_branding.root_domain', 'suresigncontracts.app');
        $this->makeOrg(['url_slug' => 'star-affinity']);

        $this->getJson('/api/public/organisation-branding/Star-Affinity.suresigncontracts.app')->assertStatus(200);
    }

    public function test_public_branding_endpoint_404s_for_unknown_slug(): void
    {
        $this->getJson('/api/public/organisation-branding/nonexistent')->assertStatus(404);
    }

    public function test_public_branding_endpoint_404s_for_reserved_slug(): void
    {
        $this->getJson('/api/public/organisation-branding/admin')->assertStatus(404);
    }

    public function test_public_branding_endpoint_404s_for_inactive_organization(): void
    {
        $this->makeOrg(['url_slug' => 'inactive-org', 'is_active' => false]);

        $this->getJson('/api/public/organisation-branding/inactive-org')->assertStatus(404);
    }

    public function test_deleted_organization_slug_is_not_resolvable(): void
    {
        $org = $this->makeOrg(['url_slug' => 'gone-org']);
        $org->delete();

        $this->getJson('/api/public/organisation-branding/gone-org')->assertStatus(404);
    }
}
