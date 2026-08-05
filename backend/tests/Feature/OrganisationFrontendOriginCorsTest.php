<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationDomain;
use App\Services\Organizations\OrganisationFrontendOriginResolver;
use App\Support\Organizations\DomainStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Organisation URL Branding, Phase 5 (Stage 2A) — dynamic CORS origin
 * validation for customer-owned domains. Does NOT re-test the existing
 * static allowlist/wildcard-subdomain CORS behaviour (already covered by
 * config/cors.php's own straightforward env-driven logic) — this is
 * scoped to the one new dynamic case: an active, verified custom domain.
 */
class OrganisationFrontendOriginCorsTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrg(array $overrides = []): Organization
    {
        return Organization::create(array_merge([
            'name' => 'Custom Domain Co', 'slug' => 'custom-domain-co',
            'timezone' => 'Europe/London', 'is_active' => true,
        ], $overrides));
    }

    public function test_active_verified_custom_domain_origin_is_allowed(): void
    {
        $org = $this->makeOrg();
        OrganizationDomain::create([
            'organization_id' => $org->id,
            'hostname' => 'portal.customer.com',
            'status' => DomainStatus::ACTIVE,
            'verification_token' => 'tok',
        ]);

        $resolver = app(OrganisationFrontendOriginResolver::class);

        $this->assertTrue($resolver->isActiveCustomDomainOrigin('https://portal.customer.com'));
    }

    public function test_disabled_custom_domain_origin_is_rejected(): void
    {
        $org = $this->makeOrg();
        OrganizationDomain::create([
            'organization_id' => $org->id,
            'hostname' => 'portal.customer.com',
            'status' => DomainStatus::DISABLED,
            'verification_token' => 'tok',
        ]);

        $resolver = app(OrganisationFrontendOriginResolver::class);

        $this->assertFalse($resolver->isActiveCustomDomainOrigin('https://portal.customer.com'));
    }

    public function test_removed_custom_domain_origin_is_rejected(): void
    {
        $org = $this->makeOrg();
        OrganizationDomain::create([
            'organization_id' => $org->id,
            'hostname' => 'portal.customer.com',
            'status' => DomainStatus::REMOVED,
            'verification_token' => 'tok',
        ]);

        $resolver = app(OrganisationFrontendOriginResolver::class);

        $this->assertFalse($resolver->isActiveCustomDomainOrigin('https://portal.customer.com'));
    }

    public function test_unknown_hostname_origin_is_rejected(): void
    {
        $resolver = app(OrganisationFrontendOriginResolver::class);

        $this->assertFalse($resolver->isActiveCustomDomainOrigin('https://nobody-owns-this.example.com'));
    }

    public function test_non_https_origin_is_rejected_even_if_active(): void
    {
        $org = $this->makeOrg();
        OrganizationDomain::create([
            'organization_id' => $org->id,
            'hostname' => 'portal.customer.com',
            'status' => DomainStatus::ACTIVE,
            'verification_token' => 'tok',
        ]);

        $resolver = app(OrganisationFrontendOriginResolver::class);

        $this->assertFalse($resolver->isActiveCustomDomainOrigin('http://portal.customer.com'));
    }

    public function test_malformed_origin_with_path_is_rejected(): void
    {
        $org = $this->makeOrg();
        OrganizationDomain::create([
            'organization_id' => $org->id,
            'hostname' => 'portal.customer.com',
            'status' => DomainStatus::ACTIVE,
            'verification_token' => 'tok',
        ]);

        $resolver = app(OrganisationFrontendOriginResolver::class);

        $this->assertFalse($resolver->isActiveCustomDomainOrigin('https://portal.customer.com/some/path'));
    }

    public function test_organisation_subdomain_is_not_treated_as_customer_domain(): void
    {
        Config::set('organisation_branding.root_domain', 'suresigncontracts.app');
        $this->makeOrg(['url_slug' => 'custom-domain-co']);

        $resolver = app(OrganisationFrontendOriginResolver::class);

        // This method answers the customer-domain question only — SureSign
        // wildcard subdomains are already handled by config/cors.php's own
        // static pattern, deliberately outside this resolver's scope.
        $this->assertFalse($resolver->isActiveCustomDomainOrigin('https://custom-domain-co.suresigncontracts.app'));
    }

    public function test_result_is_cached_and_reflects_prior_state_until_invalidated(): void
    {
        $org = $this->makeOrg();
        $domain = OrganizationDomain::create([
            'organization_id' => $org->id,
            'hostname' => 'portal.customer.com',
            'status' => DomainStatus::ACTIVE,
            'verification_token' => 'tok',
        ]);

        $resolver = app(OrganisationFrontendOriginResolver::class);
        $this->assertTrue($resolver->isActiveCustomDomainOrigin('https://portal.customer.com'));

        $domain->update(['status' => DomainStatus::DISABLED]);

        // Still true — cached, not yet invalidated.
        $this->assertTrue($resolver->isActiveCustomDomainOrigin('https://portal.customer.com'));

        Cache::forget('frontend-origin:portal.customer.com');

        $this->assertFalse($resolver->isActiveCustomDomainOrigin('https://portal.customer.com'));
    }

    public function test_cors_headers_present_on_actual_request_from_active_custom_domain(): void
    {
        $org = $this->makeOrg();
        OrganizationDomain::create([
            'organization_id' => $org->id,
            'hostname' => 'portal.customer.com',
            'status' => DomainStatus::ACTIVE,
            'verification_token' => 'tok',
        ]);

        $response = $this->withHeaders(['Origin' => 'https://portal.customer.com'])
            ->getJson('/api/guest-settings');

        $response->assertHeader('Access-Control-Allow-Origin', 'https://portal.customer.com');
    }

    public function test_no_cors_headers_for_hostile_origin(): void
    {
        $response = $this->withHeaders(['Origin' => 'https://hostile-attacker.example.com'])
            ->getJson('/api/guest-settings');

        $response->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_preflight_options_request_from_active_custom_domain_is_answered(): void
    {
        $org = $this->makeOrg();
        OrganizationDomain::create([
            'organization_id' => $org->id,
            'hostname' => 'portal.customer.com',
            'status' => DomainStatus::ACTIVE,
            'verification_token' => 'tok',
        ]);

        $response = $this->withHeaders([
            'Origin' => 'https://portal.customer.com',
            'Access-Control-Request-Method' => 'GET',
        ])->options('/api/guest-settings');

        $response->assertStatus(204);
        $response->assertHeader('Access-Control-Allow-Origin', 'https://portal.customer.com');
    }
}
