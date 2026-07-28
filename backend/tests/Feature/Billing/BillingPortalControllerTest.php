<?php

namespace Tests\Feature\Billing;

use App\Models\BillingCustomer;
use App\Models\Organization;
use App\Models\User;
use App\Services\Billing\FakeBillingProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * POST /billing/portal (Slice E2) — restricted Stripe Customer Portal
 * session creation. Empty body only: no Stripe Customer ID, Organisation
 * ID, Portal Configuration ID, or return URL is ever accepted.
 */
class BillingPortalControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'billing.enabled' => true,
            'billing.portal_return_url' => 'https://app.suresigncontracts.test/app/settings/billing',
        ]);
    }

    private function org(): Organization
    {
        return Organization::create(['name' => 'Acme', 'slug' => 'acme-' . random_int(1, 10000000), 'timezone' => 'Europe/London']);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->postJson('/api/billing/portal')->assertUnauthorized();
    }

    public function test_creates_a_portal_session_and_returns_only_a_safe_url(): void
    {
        $org = $this->org();
        $user = User::factory()->create(['organization_id' => $org->id]);
        BillingCustomer::create([
            'organization_id' => $org->id, 'provider' => 'stripe',
            'provider_customer_id' => 'cus_fake_1', 'livemode' => false,
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/billing/portal');

        $response->assertOk();
        $this->assertArrayHasKey('url', $response->json());
        $this->assertIsString($response->json('url'));
        // No raw Stripe object, no customer id, no configuration id key at the top level.
        $this->assertSame(['url'], array_keys($response->json()));
    }

    public function test_ignores_a_caller_supplied_body(): void
    {
        $org = $this->org();
        $user = User::factory()->create(['organization_id' => $org->id]);
        BillingCustomer::create([
            'organization_id' => $org->id, 'provider' => 'stripe',
            'provider_customer_id' => 'cus_fake_1', 'livemode' => false,
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/billing/portal', [
            'customer_id' => 'cus_attacker_supplied',
            'configuration_id' => 'bpc_attacker_supplied',
            'return_url' => 'https://attacker.test/',
        ]);

        $response->assertOk();
        $this->assertStringNotContainsString('cus_attacker_supplied', $response->json('url'));
        $this->assertStringNotContainsString('attacker.test', $response->json('url'));
    }

    public function test_rejects_when_organisation_has_no_billing_customer(): void
    {
        $org = $this->org();
        $user = User::factory()->create(['organization_id' => $org->id]);

        Sanctum::actingAs($user);

        $this->postJson('/api/billing/portal')
            ->assertStatus(409)->assertJsonPath('code', 'portal_unavailable');
    }

    public function test_rejects_on_provider_mode_mismatch(): void
    {
        $org = $this->org();
        $user = User::factory()->create(['organization_id' => $org->id]);
        BillingCustomer::create([
            'organization_id' => $org->id, 'provider' => 'stripe',
            'provider_customer_id' => 'cus_fake_1', 'livemode' => true,
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/billing/portal')
            ->assertStatus(409)->assertJsonPath('code', 'portal_unavailable');
    }

    public function test_organisation_isolation(): void
    {
        $orgA = $this->org();
        BillingCustomer::create([
            'organization_id' => $orgA->id, 'provider' => 'stripe',
            'provider_customer_id' => 'cus_fake_a', 'livemode' => false,
        ]);

        $orgB = $this->org();
        $userB = User::factory()->create(['organization_id' => $orgB->id]);

        Sanctum::actingAs($userB);

        // Org B has no billing customer of its own — never resolves org A's.
        $this->postJson('/api/billing/portal')
            ->assertStatus(409)->assertJsonPath('code', 'portal_unavailable');
    }

    public function test_duplicate_requests_do_not_mutate_local_subscription_state(): void
    {
        $org = $this->org();
        $user = User::factory()->create(['organization_id' => $org->id]);
        BillingCustomer::create([
            'organization_id' => $org->id, 'provider' => 'stripe',
            'provider_customer_id' => 'cus_fake_1', 'livemode' => false,
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/billing/portal')->assertOk();
        $this->postJson('/api/billing/portal')->assertOk();

        $this->assertSame(0, \App\Models\Subscription::where('organization_id', $org->id)->count());
        $fake = $this->app->make(FakeBillingProvider::class);
        // Two Portal sessions requested, but only ONE restricted configuration ever created.
        $this->assertCount(1, $fake->portalConfigurations);
    }
}
