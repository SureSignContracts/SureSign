<?php

namespace Tests\Feature\Billing;

use App\Models\BillingCustomer;
use App\Models\Organization;
use App\Models\PricingPlan;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Billing\SubscriptionStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Confirms config('billing.enabled') = false (the shipped production
 * default until a deliberate go-live decision — see .env.example and
 * config/billing.php) actually keeps every mutating Billing endpoint and
 * the Stripe webhook dormant. Before App\Http\Middleware\
 * EnsureBillingIsEnabled existed, this flag was read by
 * App\Services\Billing\BillingProviderManager::isEnabled() but nothing ever
 * called it — Checkout/Portal/plan-change/cancellation were fully
 * reachable regardless of this setting as long as Stripe credentials
 * happened to be configured. This suite is the regression guard for that
 * gap.
 */
class BillingDormantStateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('billing.enabled', false);
    }

    private function org(): Organization
    {
        return Organization::create([
            'name' => 'Acme', 'slug' => 'acme-' . random_int(1, 10000000),
            'email' => 'billing@acme.test', 'timezone' => 'Europe/London',
        ]);
    }

    public function test_checkout_is_dormant(): void
    {
        $org = $this->org();
        $user = User::factory()->create(['organization_id' => $org->id]);
        PricingPlan::create(['code' => 'essential', 'slug' => 'essential', 'name' => 'Essential', 'status' => 'active']);

        Sanctum::actingAs($user);

        $this->postJson('/api/billing/checkout', ['plan_code' => 'essential', 'billing_interval' => 'monthly'])
            ->assertStatus(503)
            ->assertJsonPath('code', 'billing_disabled');
    }

    public function test_cancel_pending_checkout_is_dormant(): void
    {
        $org = $this->org();
        $user = User::factory()->create(['organization_id' => $org->id]);

        Sanctum::actingAs($user);

        $this->postJson('/api/billing/checkout/cancel-pending')
            ->assertStatus(503)
            ->assertJsonPath('code', 'billing_disabled');
    }

    public function test_plan_change_is_dormant(): void
    {
        $org = $this->org();
        $user = User::factory()->create(['organization_id' => $org->id]);

        Sanctum::actingAs($user);

        $this->postJson('/api/billing/plan-change', ['plan_code' => 'professional', 'billing_interval' => 'monthly'])
            ->assertStatus(503)
            ->assertJsonPath('code', 'billing_disabled');
    }

    public function test_subscription_cancel_is_dormant(): void
    {
        $org = $this->org();
        $user = User::factory()->create(['organization_id' => $org->id]);

        Sanctum::actingAs($user);

        $this->postJson('/api/billing/subscription/cancel')
            ->assertStatus(503)
            ->assertJsonPath('code', 'billing_disabled');
    }

    public function test_subscription_resume_is_dormant(): void
    {
        $org = $this->org();
        $user = User::factory()->create(['organization_id' => $org->id]);

        Sanctum::actingAs($user);

        $this->postJson('/api/billing/subscription/resume')
            ->assertStatus(503)
            ->assertJsonPath('code', 'billing_disabled');
    }

    public function test_portal_is_dormant(): void
    {
        $org = $this->org();
        $user = User::factory()->create(['organization_id' => $org->id]);
        BillingCustomer::create([
            'organization_id' => $org->id, 'provider' => 'stripe',
            'provider_customer_id' => 'cus_fake_1', 'livemode' => false,
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/billing/portal')
            ->assertStatus(503)
            ->assertJsonPath('code', 'billing_disabled');
    }

    public function test_read_only_billing_overview_stays_reachable_while_dormant(): void
    {
        $org = $this->org();
        $user = User::factory()->create(['organization_id' => $org->id]);

        Sanctum::actingAs($user);

        // Read-only endpoints are deliberately NOT gated — an organisation
        // with no subscription should still see "no subscription", not a
        // dead Billing page, while billing is dormant.
        $this->getJson('/api/billing/overview')->assertOk();
    }

    public function test_stripe_webhook_is_ignored_while_dormant(): void
    {
        $response = $this->postJson('/api/billing/webhooks/stripe', [], [
            'Stripe-Signature' => 'whatever',
        ]);

        $response->assertOk()->assertJsonPath('status', 'ignored');

        $this->assertDatabaseCount('billing_webhook_events', 0);
    }
}
