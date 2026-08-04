<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AppointmentType;
use App\Models\Organization;
use App\Models\OrganizationDomain;
use App\Models\OrganizationUrlSlugHistory;
use App\Models\User;
use App\Services\AppointmentPublicLinkService;
use App\Services\Organizations\DnsRecordLookup;
use App\Services\Organizations\DomainVerificationService;
use App\Services\Organizations\OrganisationHostResolver;
use App\Services\OrganisationUrlGenerator;
use App\Support\Organizations\DomainStatus;
use App\Support\Organizations\HostResolution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Organisation URL Branding, Phase 2 — slug history/redirect, customer-owned
 * domains, and the central OrganisationHostResolver.
 */
class OrganisationUrlBrandingPhase2Test extends TestCase
{
    use RefreshDatabase;

    private static int $orgCounter = 100;

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

    // ── Slug history: capture + reuse prevention ────────────────────────────

    public function test_changing_slug_records_history(): void
    {
        $superAdmin = $this->makeUser('Super Admin');
        $org = $this->makeOrg(['url_slug' => 'old-name']);
        Sanctum::actingAs($superAdmin);

        $this->putJson("/api/organizations/{$org->id}/url-slug", [
            'url_slug' => 'new-name',
            'reason' => 'Rebranding to new-name.',
            'confirmed' => true,
        ])->assertStatus(200);

        $this->assertDatabaseHas('organization_url_slug_history', [
            'organization_id' => $org->id,
            'url_slug' => 'old-name',
        ]);
        $this->assertSame('new-name', $org->fresh()->url_slug);
    }

    public function test_removing_slug_records_history(): void
    {
        $superAdmin = $this->makeUser('Super Admin');
        $org = $this->makeOrg(['url_slug' => 'gone-soon']);
        Sanctum::actingAs($superAdmin);

        $this->deleteJson("/api/organizations/{$org->id}/url-slug", [
            'reason' => 'No longer needed.',
            'confirmed' => true,
        ])->assertStatus(200);

        $this->assertDatabaseHas('organization_url_slug_history', [
            'organization_id' => $org->id,
            'url_slug' => 'gone-soon',
        ]);
    }

    public function test_same_organization_can_reclaim_its_own_historic_slug(): void
    {
        $superAdmin = $this->makeUser('Super Admin');
        $org = $this->makeOrg(['url_slug' => 'phoenix']);
        Sanctum::actingAs($superAdmin);

        $this->putJson("/api/organizations/{$org->id}/url-slug", ['url_slug' => 'temp', 'reason' => 'Temporary rename.', 'confirmed' => true])
            ->assertStatus(200);

        // Reclaim "phoenix" — must be allowed for the SAME organisation.
        $this->putJson("/api/organizations/{$org->id}/url-slug", ['url_slug' => 'phoenix', 'reason' => 'Reverting rename.', 'confirmed' => true])
            ->assertStatus(200);

        $this->assertSame('phoenix', $org->fresh()->url_slug);
    }

    public function test_different_organization_cannot_claim_a_historic_slug(): void
    {
        $superAdmin = $this->makeUser('Super Admin');
        $orgA = $this->makeOrg(['url_slug' => 'star-affinity']);
        $orgB = $this->makeOrg();
        Sanctum::actingAs($superAdmin);

        $this->putJson("/api/organizations/{$orgA->id}/url-slug", ['url_slug' => 'star-affinity-new', 'reason' => 'Rebranding the organisation.', 'confirmed' => true])
            ->assertStatus(200);

        $this->putJson("/api/organizations/{$orgB->id}/url-slug", ['url_slug' => 'star-affinity', 'reason' => 'Trying to claim a released slug.', 'confirmed' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors('url_slug');
    }

    public function test_slug_history_endpoint_returns_history_for_super_admin(): void
    {
        $superAdmin = $this->makeUser('Super Admin');
        $org = $this->makeOrg(['url_slug' => 'first-name']);
        Sanctum::actingAs($superAdmin);
        $this->putJson("/api/organizations/{$org->id}/url-slug", ['url_slug' => 'second-name', 'reason' => 'Rename number one.', 'confirmed' => true]);

        $response = $this->getJson("/api/organizations/{$org->id}/url-slug-history");
        $response->assertStatus(200);
        $this->assertSame('first-name', $response->json('data.0.url_slug'));
    }

    // ── OrganisationHostResolver ─────────────────────────────────────────────

    public function test_resolver_resolves_current_organisation_slug(): void
    {
        Config::set('organisation_branding.root_domain', 'suresigncontracts.app');
        $org = $this->makeOrg(['url_slug' => 'star-affinity']);

        $resolution = app(OrganisationHostResolver::class)->resolve('star-affinity.suresigncontracts.app');

        $this->assertSame(HostResolution::TYPE_ORGANISATION, $resolution->type);
        $this->assertSame($org->id, $resolution->organization->id);
    }

    public function test_resolver_resolves_historic_slug_when_organization_still_active(): void
    {
        Config::set('organisation_branding.root_domain', 'suresigncontracts.app');
        $org = $this->makeOrg(['url_slug' => 'new-slug']);
        $org->urlSlugHistory()->create(['url_slug' => 'old-slug', 'released_at' => now()]);

        $resolution = app(OrganisationHostResolver::class)->resolve('old-slug.suresigncontracts.app');

        $this->assertSame(HostResolution::TYPE_HISTORIC_SLUG, $resolution->type);
        $this->assertSame($org->id, $resolution->organization->id);
    }

    public function test_resolver_does_not_resolve_historic_slug_for_inactive_organization(): void
    {
        Config::set('organisation_branding.root_domain', 'suresigncontracts.app');
        $org = $this->makeOrg(['url_slug' => 'new-slug', 'is_active' => false]);
        $org->urlSlugHistory()->create(['url_slug' => 'old-slug', 'released_at' => now()]);

        $resolution = app(OrganisationHostResolver::class)->resolve('old-slug.suresigncontracts.app');

        $this->assertSame(HostResolution::TYPE_NONE, $resolution->type);
    }

    public function test_resolver_returns_none_for_unknown_host(): void
    {
        Config::set('organisation_branding.root_domain', 'suresigncontracts.app');

        $resolution = app(OrganisationHostResolver::class)->resolve('nonexistent.suresigncontracts.app');

        $this->assertSame(HostResolution::TYPE_NONE, $resolution->type);
    }

    public function test_resolver_returns_none_for_platform_host(): void
    {
        Config::set('organisation_branding.root_domain', 'suresigncontracts.app');

        $resolution = app(OrganisationHostResolver::class)->resolve('app.suresigncontracts.app');

        // "app" is not any organisation's slug and not in history -> none.
        $this->assertSame(HostResolution::TYPE_NONE, $resolution->type);
    }

    public function test_resolver_resolves_active_customer_domain(): void
    {
        $org = $this->makeOrg();
        $org->domains()->create([
            'hostname' => 'contracts.customer.com', 'status' => DomainStatus::ACTIVE,
            'verification_token' => 'tok', 'verification_method' => 'txt',
        ]);

        $resolution = app(OrganisationHostResolver::class)->resolve('contracts.customer.com');

        $this->assertSame(HostResolution::TYPE_CUSTOMER_DOMAIN, $resolution->type);
        $this->assertSame($org->id, $resolution->organization->id);
    }

    public function test_resolver_ignores_non_active_customer_domain(): void
    {
        $org = $this->makeOrg();
        $org->domains()->create([
            'hostname' => 'contracts.customer.com', 'status' => DomainStatus::VERIFIED,
            'verification_token' => 'tok', 'verification_method' => 'txt',
        ]);

        $resolution = app(OrganisationHostResolver::class)->resolve('contracts.customer.com');

        $this->assertSame(HostResolution::TYPE_NONE, $resolution->type);
    }

    // ── URL generator priority: domain > slug > default ─────────────────────

    public function test_generator_prefers_active_domain_over_slug(): void
    {
        Config::set('organisation_branding.root_domain', 'suresigncontracts.app');
        Config::set('suresign.marketing_url', 'https://marketing.example.test');
        $org = $this->makeOrg(['url_slug' => 'star-affinity']);
        $org->domains()->create([
            'hostname' => 'contracts.star-affinity.co.uk', 'status' => DomainStatus::ACTIVE,
            'verification_token' => 'tok', 'verification_method' => 'txt',
        ]);

        $url = (new OrganisationUrlGenerator())->publicUrl($org->fresh(), '/foo');

        $this->assertSame('https://contracts.star-affinity.co.uk/foo', $url);
    }

    public function test_generator_falls_back_to_slug_when_no_active_domain(): void
    {
        Config::set('organisation_branding.root_domain', 'suresigncontracts.app');
        Config::set('suresign.marketing_url', 'https://marketing.example.test');
        $org = $this->makeOrg(['url_slug' => 'star-affinity']);
        $org->domains()->create([
            'hostname' => 'contracts.star-affinity.co.uk', 'status' => DomainStatus::PENDING,
            'verification_token' => 'tok', 'verification_method' => 'txt',
        ]);

        $url = (new OrganisationUrlGenerator())->publicUrl($org->fresh(), '/foo');

        $this->assertSame('https://star-affinity.suresigncontracts.app/foo', $url);
    }

    // ── Cross-host isolation with historic slug ─────────────────────────────

    public function test_public_appointment_action_passes_with_historic_slug_header_for_the_same_organization(): void
    {
        Config::set('organisation_branding.root_domain', 'suresigncontracts.app');
        $org = $this->makeOrg(['url_slug' => 'new-slug']);
        $org->urlSlugHistory()->create(['url_slug' => 'old-slug', 'released_at' => now()]);
        $appointment = $this->makeAppointment($org);
        $link = app(AppointmentPublicLinkService::class)->cancelApiUrl($appointment);
        $path = parse_url($link, PHP_URL_PATH) . '?' . parse_url($link, PHP_URL_QUERY);

        $this->withHeaders(['X-Suresign-Org-Host' => 'old-slug.suresigncontracts.app'])
            ->getJson($path)
            ->assertStatus(200);
    }

    // ── Domain lifecycle ─────────────────────────────────────────────────────

    public function test_super_admin_can_register_a_domain(): void
    {
        $superAdmin = $this->makeUser('Super Admin');
        $org = $this->makeOrg();
        Sanctum::actingAs($superAdmin);

        $response = $this->postJson("/api/organizations/{$org->id}/domains", [
            'hostname' => 'contracts.customer.com',
            'reason' => 'Customer requested BYOD.',
            'confirmed' => true,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('organization_domains', ['organization_id' => $org->id, 'hostname' => 'contracts.customer.com', 'status' => DomainStatus::PENDING]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'organization.domain_created']);
    }

    public function test_registering_a_domain_under_the_branded_root_domain_is_rejected(): void
    {
        Config::set('organisation_branding.root_domain', 'suresigncontracts.app');
        $superAdmin = $this->makeUser('Super Admin');
        $org = $this->makeOrg();
        Sanctum::actingAs($superAdmin);

        $this->postJson("/api/organizations/{$org->id}/domains", [
            'hostname' => 'someone.suresigncontracts.app',
            'reason' => 'Trying to register a reserved-space hostname.',
            'confirmed' => true,
        ])->assertStatus(422)->assertJsonValidationErrors('hostname');
    }

    public function test_duplicate_domain_is_rejected(): void
    {
        $superAdmin = $this->makeUser('Super Admin');
        $org = $this->makeOrg();
        $org->domains()->create(['hostname' => 'contracts.customer.com', 'status' => DomainStatus::PENDING, 'verification_token' => 'x', 'verification_method' => 'txt']);
        Sanctum::actingAs($superAdmin);

        $this->postJson("/api/organizations/{$org->id}/domains", [
            'hostname' => 'contracts.customer.com',
            'reason' => 'Trying to claim an already-claimed domain.',
            'confirmed' => true,
        ])->assertStatus(422)->assertJsonValidationErrors('hostname');
    }

    public function test_admin_role_cannot_register_a_domain(): void
    {
        $admin = $this->makeUser('Admin');
        $org = $this->makeOrg();
        Sanctum::actingAs($admin);

        $this->postJson("/api/organizations/{$org->id}/domains", [
            'hostname' => 'contracts.customer.com',
            'reason' => 'Attempting as Admin.',
            'confirmed' => true,
        ])->assertStatus(403);
    }

    public function test_verification_succeeds_when_dns_records_match(): void
    {
        $org = $this->makeOrg();
        Config::set('organisation_branding.cname_target', 'branded.suresigncontracts.app');
        $domain = $org->domains()->create(['hostname' => 'contracts.customer.com', 'status' => DomainStatus::PENDING, 'verification_token' => 'sekrit', 'verification_method' => 'txt']);

        $dns = $this->createMock(DnsRecordLookup::class);
        $dns->method('txt')->willReturn([['txt' => 'suresign-domain-verify=sekrit']]);
        $dns->method('cname')->willReturn([['target' => 'branded.suresigncontracts.app']]);

        $verified = (new DomainVerificationService($dns))->verify($domain);

        $this->assertTrue($verified);
        $this->assertSame(DomainStatus::VERIFIED, $domain->fresh()->status);
        $this->assertNotNull($domain->fresh()->verified_at);
    }

    public function test_verification_fails_when_txt_token_does_not_match(): void
    {
        $org = $this->makeOrg();
        Config::set('organisation_branding.cname_target', 'branded.suresigncontracts.app');
        $domain = $org->domains()->create(['hostname' => 'contracts.customer.com', 'status' => DomainStatus::PENDING, 'verification_token' => 'sekrit', 'verification_method' => 'txt']);

        $dns = $this->createMock(DnsRecordLookup::class);
        $dns->method('txt')->willReturn([['txt' => 'suresign-domain-verify=wrong-token']]);
        $dns->method('cname')->willReturn([['target' => 'branded.suresigncontracts.app']]);

        $verified = (new DomainVerificationService($dns))->verify($domain);

        $this->assertFalse($verified);
        $this->assertSame(DomainStatus::AWAITING_DNS, $domain->fresh()->status);
    }

    public function test_verification_never_throws_on_dns_lookup_failure(): void
    {
        $org = $this->makeOrg();
        $domain = $org->domains()->create(['hostname' => 'contracts.customer.com', 'status' => DomainStatus::PENDING, 'verification_token' => 'sekrit', 'verification_method' => 'txt']);

        $dns = $this->createMock(DnsRecordLookup::class);
        $dns->method('txt')->willThrowException(new \RuntimeException('DNS resolution failed'));
        $dns->method('cname')->willThrowException(new \RuntimeException('DNS resolution failed'));

        $verified = (new DomainVerificationService($dns))->verify($domain);

        $this->assertFalse($verified);
        $this->assertSame(DomainStatus::AWAITING_DNS, $domain->fresh()->status);
    }

    public function test_activate_requires_verified_status(): void
    {
        $superAdmin = $this->makeUser('Super Admin');
        $org = $this->makeOrg();
        $domain = $org->domains()->create(['hostname' => 'contracts.customer.com', 'status' => DomainStatus::PENDING, 'verification_token' => 'x', 'verification_method' => 'txt']);
        Sanctum::actingAs($superAdmin);

        $this->postJson("/api/organizations/{$org->id}/domains/{$domain->id}/activate", ['reason' => 'Trying to activate too early.', 'confirmed' => true])
            ->assertStatus(422);
    }

    public function test_full_domain_lifecycle_activate_disable_remove(): void
    {
        $superAdmin = $this->makeUser('Super Admin');
        $org = $this->makeOrg();
        $domain = $org->domains()->create(['hostname' => 'contracts.customer.com', 'status' => DomainStatus::VERIFIED, 'verification_token' => 'x', 'verification_method' => 'txt']);
        Sanctum::actingAs($superAdmin);

        $this->postJson("/api/organizations/{$org->id}/domains/{$domain->id}/activate", ['reason' => 'Ready to go live.', 'confirmed' => true])
            ->assertStatus(200);
        $this->assertSame(DomainStatus::ACTIVE, $domain->fresh()->status);

        $this->postJson("/api/organizations/{$org->id}/domains/{$domain->id}/disable", ['reason' => 'Customer reported an issue.', 'confirmed' => true])
            ->assertStatus(200);
        $this->assertSame(DomainStatus::DISABLED, $domain->fresh()->status);

        $this->postJson("/api/organizations/{$org->id}/domains/{$domain->id}/remove", ['reason' => 'Customer cancelled BYOD.', 'confirmed' => true])
            ->assertStatus(200);
        $this->assertSame(DomainStatus::REMOVED, $domain->fresh()->status);

        $this->assertDatabaseHas('activity_logs', ['action' => 'organization.domain_activated']);
        $this->assertDatabaseHas('activity_logs', ['action' => 'organization.domain_disabled']);
        $this->assertDatabaseHas('activity_logs', ['action' => 'organization.domain_removed']);
    }

    // ── Regression: signed URL never affected by the new header/resolver ───

    public function test_signed_url_still_validates_with_domain_resolution_in_play(): void
    {
        $org = $this->makeOrg();
        $org->domains()->create(['hostname' => 'contracts.customer.com', 'status' => DomainStatus::ACTIVE, 'verification_token' => 'x', 'verification_method' => 'txt']);
        $appointment = $this->makeAppointment($org);
        $link = app(AppointmentPublicLinkService::class)->cancelApiUrl($appointment);
        $path = parse_url($link, PHP_URL_PATH) . '?' . parse_url($link, PHP_URL_QUERY);

        $this->withHeaders(['X-Suresign-Org-Host' => 'contracts.customer.com'])
            ->getJson($path)
            ->assertStatus(200);
    }
}
