<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationDomain;
use App\Models\User;
use App\Support\Organizations\DomainStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Organisation URL Branding, Phase 5 (Stage 3) — the entirely server-side
 * wrong-workspace decision (GET /auth/workspace-context). Never asserts
 * the frontend receives two organisation IDs to compare — only the
 * resulting workspace_state/authoritative_workspace_url/may_continue.
 */
class AuthenticatedWorkspaceContextTest extends TestCase
{
    use RefreshDatabase;

    private static int $orgCounter = 500;

    private function makeOrg(array $overrides = []): Organization
    {
        $n = ++self::$orgCounter;
        return Organization::create(array_merge([
            'name' => "Org {$n}", 'slug' => "org-{$n}", 'timezone' => 'Europe/London', 'is_active' => true,
        ], $overrides));
    }

    private function makeUser(?Organization $org, string $role = 'Client'): User
    {
        $user = User::factory()->create(['organization_id' => $org?->id]);
        $user->assignRole(Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']));
        return $user;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('suresign.frontend_url', 'https://app.suresigncontracts.app');
        Config::set('organisation_branding.root_domain', 'suresigncontracts.app');
    }

    public function test_no_host_header_resolves_platform_host_for_ordinary_user(): void
    {
        $org = $this->makeOrg(['url_slug' => 'org-a']);
        $user = $this->makeUser($org);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/auth/workspace-context');

        $response->assertOk();
        $response->assertJson([
            'workspace_state' => 'platform_host',
            'authoritative_workspace_url' => 'https://org-a.suresigncontracts.app',
            'may_continue' => true,
        ]);
    }

    public function test_matching_organisation_host_allows_continue(): void
    {
        $org = $this->makeOrg(['url_slug' => 'org-a']);
        $user = $this->makeUser($org);
        Sanctum::actingAs($user);

        $response = $this->withHeaders(['X-Suresign-Org-Host' => 'org-a.suresigncontracts.app'])
            ->getJson('/api/auth/workspace-context');

        $response->assertOk();
        $response->assertJson([
            'workspace_state' => 'matching_workspace',
            'may_continue' => true,
        ]);
    }

    public function test_wrong_organisation_host_is_blocked_and_never_exposes_the_other_organisation(): void
    {
        $orgA = $this->makeOrg(['url_slug' => 'org-a']);
        $orgB = $this->makeOrg(['url_slug' => 'org-b']);
        $userOfB = $this->makeUser($orgB);
        Sanctum::actingAs($userOfB);

        $response = $this->withHeaders(['X-Suresign-Org-Host' => 'org-a.suresigncontracts.app'])
            ->getJson('/api/auth/workspace-context');

        $response->assertOk();
        $response->assertJson([
            'workspace_state' => 'wrong_workspace',
            'may_continue' => false,
            // The authoritative URL sends them to THEIR OWN org (B), never org A's.
            'authoritative_workspace_url' => 'https://org-b.suresigncontracts.app',
        ]);
        $content = $response->json();
        $this->assertArrayNotHasKey('organisation_id', $content);
        // organisation_name must never leak org A's identity in a mismatch.
        $this->assertNull($content['organisation_name']);
    }

    public function test_active_custom_domain_matches_correctly(): void
    {
        $org = $this->makeOrg();
        OrganizationDomain::create([
            'organization_id' => $org->id,
            'hostname' => 'portal.customer.com',
            'status' => DomainStatus::ACTIVE,
            'verification_token' => 'tok',
        ]);
        $user = $this->makeUser($org);
        Sanctum::actingAs($user);

        $response = $this->withHeaders(['X-Suresign-Org-Host' => 'portal.customer.com'])
            ->getJson('/api/auth/workspace-context');

        $response->assertJson([
            'workspace_state' => 'matching_workspace',
            'authoritative_workspace_url' => 'https://portal.customer.com',
            'may_continue' => true,
        ]);
    }

    public function test_inactive_organisation_host_is_blocked_without_leaking_state(): void
    {
        $org = $this->makeOrg(['url_slug' => 'org-a', 'is_active' => false]);
        $user = $this->makeUser($org);
        Sanctum::actingAs($user);

        $response = $this->withHeaders(['X-Suresign-Org-Host' => 'org-a.suresigncontracts.app'])
            ->getJson('/api/auth/workspace-context');

        $response->assertJson(['workspace_state' => 'inactive_workspace', 'may_continue' => false]);
    }

    public function test_unknown_host_returns_host_not_found(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org);
        Sanctum::actingAs($user);

        $response = $this->withHeaders(['X-Suresign-Org-Host' => 'nobody.suresigncontracts.app'])
            ->getJson('/api/auth/workspace-context');

        $response->assertJson(['workspace_state' => 'host_not_found', 'may_continue' => false]);
    }

    public function test_super_admin_on_platform_host_is_normal(): void
    {
        $user = $this->makeUser(null, 'Super Admin');
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/auth/workspace-context');

        $response->assertJson([
            'workspace_state' => 'platform_host',
            'authoritative_workspace_url' => 'https://app.suresigncontracts.app',
            'may_continue' => true,
        ]);
    }

    public function test_super_admin_on_customer_host_is_blocked(): void
    {
        $org = $this->makeOrg(['url_slug' => 'org-a']);
        $user = $this->makeUser(null, 'Super Admin');
        Sanctum::actingAs($user);

        $response = $this->withHeaders(['X-Suresign-Org-Host' => 'org-a.suresigncontracts.app'])
            ->getJson('/api/auth/workspace-context');

        $response->assertJson([
            'workspace_state' => 'platform_staff_on_customer_host',
            'authoritative_workspace_url' => 'https://app.suresigncontracts.app',
            'may_continue' => false,
        ]);
    }

    public function test_admin_on_customer_host_is_blocked(): void
    {
        $org = $this->makeOrg(['url_slug' => 'org-a']);
        $user = $this->makeUser(null, 'Admin');
        Sanctum::actingAs($user);

        $response = $this->withHeaders(['X-Suresign-Org-Host' => 'org-a.suresigncontracts.app'])
            ->getJson('/api/auth/workspace-context');

        $response->assertJson(['workspace_state' => 'platform_staff_on_customer_host', 'may_continue' => false]);
    }

    public function test_user_without_organisation_on_customer_host_is_blocked(): void
    {
        $org = $this->makeOrg(['url_slug' => 'org-a']);
        $user = $this->makeUser(null);
        Sanctum::actingAs($user);

        $response = $this->withHeaders(['X-Suresign-Org-Host' => 'org-a.suresigncontracts.app'])
            ->getJson('/api/auth/workspace-context');

        $response->assertJson([
            'workspace_state' => 'wrong_workspace',
            'authoritative_workspace_url' => 'https://app.suresigncontracts.app',
            'may_continue' => false,
        ]);
    }

    public function test_historical_slug_host_resolves_to_matching_organisation(): void
    {
        $org = $this->makeOrg(['url_slug' => 'new-slug']);
        \App\Models\OrganizationUrlSlugHistory::create([
            'organization_id' => $org->id,
            'url_slug' => 'old-slug',
            'released_at' => now(),
        ]);
        $user = $this->makeUser($org);
        Sanctum::actingAs($user);

        $response = $this->withHeaders(['X-Suresign-Org-Host' => 'old-slug.suresigncontracts.app'])
            ->getJson('/api/auth/workspace-context');

        $response->assertJson([
            'workspace_state' => 'matching_workspace',
            // Redirects to the CURRENT canonical host, not the historic one.
            'authoritative_workspace_url' => 'https://new-slug.suresigncontracts.app',
        ]);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->getJson('/api/auth/workspace-context');

        $response->assertStatus(401);
    }
}
