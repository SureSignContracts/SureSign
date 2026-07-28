<?php

namespace Tests\Feature\Billing;

use App\Models\ActivityLog;
use App\Models\BillingCustomer;
use App\Models\Organization;
use App\Models\User;
use App\Services\Billing\BillingPortalService;
use App\Services\Billing\BillingProviderInterface;
use App\Services\Billing\Exceptions\SubscriptionLifecycleConflictException;
use App\Services\Billing\FakeBillingProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingPortalServiceTest extends TestCase
{
    use RefreshDatabase;

    private BillingPortalService $portal;
    private FakeBillingProvider $provider;
    private Organization $org;
    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        config(['billing.portal_return_url' => 'https://app.suresigncontracts.test/app/settings/billing']);

        $this->portal = $this->app->make(BillingPortalService::class);
        $this->provider = $this->app->make(BillingProviderInterface::class);
        $this->org = Organization::create(['name' => 'Acme', 'slug' => 'acme-' . random_int(1, 10000000), 'timezone' => 'Europe/London']);
        $this->actor = User::factory()->create(['organization_id' => $this->org->id]);
    }

    public function test_creates_a_session_using_only_the_configured_trusted_return_url(): void
    {
        BillingCustomer::create([
            'organization_id' => $this->org->id,
            'provider' => 'stripe',
            'provider_customer_id' => 'cus_fake_1',
            'livemode' => false,
        ]);

        $session = $this->portal->createSession($this->org, $this->actor);

        $this->assertStringContainsString('return=https://app.suresigncontracts.test/app/settings/billing', $session['url']);
    }

    public function test_creates_a_restricted_configuration_with_cancellation_and_plan_change_disabled(): void
    {
        BillingCustomer::create([
            'organization_id' => $this->org->id,
            'provider' => 'stripe',
            'provider_customer_id' => 'cus_fake_1',
            'livemode' => false,
        ]);

        $this->portal->createSession($this->org, $this->actor);

        $this->assertCount(1, $this->provider->portalConfigurations);
        $configuration = array_values($this->provider->portalConfigurations)[0];

        $this->assertTrue($configuration['features']['payment_method_update']);
        $this->assertTrue($configuration['features']['invoice_history']);
        $this->assertTrue($configuration['features']['customer_update']);
        $this->assertSame(['address', 'phone', 'tax_id'], $configuration['features']['customer_update_allowed_fields']);
        $this->assertFalse($configuration['features']['subscription_cancel']);
        $this->assertFalse($configuration['features']['subscription_update']);
    }

    public function test_reuses_the_existing_restricted_configuration_across_sessions(): void
    {
        BillingCustomer::create([
            'organization_id' => $this->org->id,
            'provider' => 'stripe',
            'provider_customer_id' => 'cus_fake_1',
            'livemode' => false,
        ]);

        $this->portal->createSession($this->org, $this->actor);
        $this->portal->createSession($this->org, $this->actor);

        $this->assertCount(1, $this->provider->portalConfigurations);
    }

    public function test_passes_the_restricted_configuration_id_to_the_provider_session_call(): void
    {
        BillingCustomer::create([
            'organization_id' => $this->org->id,
            'provider' => 'stripe',
            'provider_customer_id' => 'cus_fake_1',
            'livemode' => false,
        ]);

        $session = $this->portal->createSession($this->org, $this->actor);

        $configurationId = array_key_first($this->provider->portalConfigurations);
        $this->assertStringContainsString("configuration={$configurationId}", $session['url']);
    }

    public function test_refuses_to_create_a_session_when_the_configuration_has_drifted_unsafe(): void
    {
        BillingCustomer::create([
            'organization_id' => $this->org->id,
            'provider' => 'stripe',
            'provider_customer_id' => 'cus_fake_1',
            'livemode' => false,
        ]);

        // First call creates the restricted configuration.
        $this->portal->createSession($this->org, $this->actor);

        // Simulate an operator/API mutation re-enabling cancellation on the
        // exact same configuration object — the drift this class must
        // fail closed on.
        $configurationId = array_key_first($this->provider->portalConfigurations);
        $this->provider->portalConfigurations[$configurationId]['features']['subscription_cancel'] = true;

        $this->expectException(SubscriptionLifecycleConflictException::class);
        $this->portal->createSession($this->org, $this->actor);
    }

    public function test_verify_restricted_configuration_reports_safety(): void
    {
        $result = $this->portal->verifyRestrictedConfiguration();

        $this->assertTrue($result['safe']);
        $this->assertFalse($result['reused']);

        $secondResult = $this->portal->verifyRestrictedConfiguration();
        $this->assertTrue($secondResult['reused']);
        $this->assertSame($result['configuration_id'], $secondResult['configuration_id']);
    }

    public function test_missing_billing_customer_is_rejected(): void
    {
        $this->expectException(SubscriptionLifecycleConflictException::class);
        $this->portal->createSession($this->org, $this->actor);
    }

    public function test_missing_return_url_configuration_is_rejected(): void
    {
        config(['billing.portal_return_url' => '']);

        BillingCustomer::create([
            'organization_id' => $this->org->id,
            'provider' => 'stripe',
            'provider_customer_id' => 'cus_fake_1',
            'livemode' => false,
        ]);

        $this->expectException(SubscriptionLifecycleConflictException::class);
        $this->portal->createSession($this->org, $this->actor);
    }

    public function test_livemode_mismatch_is_rejected(): void
    {
        BillingCustomer::create([
            'organization_id' => $this->org->id,
            'provider' => 'stripe',
            'provider_customer_id' => 'cus_fake_1',
            'livemode' => true,
        ]);

        $this->expectException(SubscriptionLifecycleConflictException::class);
        $this->portal->createSession($this->org, $this->actor);
    }

    public function test_session_creation_is_audited(): void
    {
        BillingCustomer::create([
            'organization_id' => $this->org->id,
            'provider' => 'stripe',
            'provider_customer_id' => 'cus_fake_1',
            'livemode' => false,
        ]);

        $this->portal->createSession($this->org, $this->actor);

        $this->assertSame(1, ActivityLog::where('action', 'billing.portal_session_created')->count());
    }
}
