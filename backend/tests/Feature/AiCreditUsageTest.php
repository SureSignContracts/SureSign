<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\PricingPlan;
use App\Models\PricingPlanEntitlement;
use App\Models\Subscription;
use App\Models\SuresignSetting;
use App\Models\User;
use App\Services\AI\AiCreditLedgerService;
use App\Support\AI\AiCreditOperatingMode;
use App\Support\Billing\SubscriptionStatus;
use App\Support\Entitlements\Feature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase G4C.3E — the customer-facing "Monthly AI Usage" meter
 * (GET /billing/ai-credit-usage). Confirms: the raw allowance/used figures
 * never leak, the percentage is clamped at 100, reservations never inflate
 * it, the two feature flags are independent, and organisation isolation is
 * absolute (no id ever accepted from the caller).
 */
class AiCreditUsageTest extends TestCase
{
    use RefreshDatabase;

    private function essentialPlan(): PricingPlan
    {
        $plan = PricingPlan::create(['code' => 'essential', 'slug' => 'essential-' . uniqid(), 'name' => 'Essential']);
        PricingPlanEntitlement::create([
            'pricing_plan_id' => $plan->id,
            'feature_key' => Feature::AI_CREDITS_PER_MONTH,
            'is_applicable' => true,
            'is_unlimited' => false,
            'value' => 100,
        ]);

        return $plan;
    }

    private function orgWithActiveSubscription(PricingPlan $plan): array
    {
        $org = Organization::create(['name' => 'Org', 'slug' => 'org-' . uniqid()]);
        $user = User::factory()->create(['organization_id' => $org->id]);

        Subscription::create([
            'organization_id' => $org->id,
            'pricing_plan_id' => $plan->id,
            'provider' => 'none',
            'source' => 'manual',
            'internal_reference' => 'SUB-' . uniqid(),
            'status' => SubscriptionStatus::ACTIVE,
            'billing_interval' => 'monthly',
            'currency' => 'GBP',
            'quantity' => 1,
            // Before config('billing.entitlement_snapshot_introduced_at') so
            // FeatureGate classifies this fixture as legacy_pre_snapshot and
            // falls back to live PlanEntitlementRepository resolution — this
            // test has no SubscriptionEntitlementSnapshot row, deliberately,
            // to keep the fixture minimal.
            'starts_at' => '2020-01-01 00:00:00',
            'activated_at' => '2020-01-01 00:00:00',
            'current_period_starts_at' => now()->startOfMonth(),
            'current_period_ends_at' => now()->addMonth(),
            'plan_code_snapshot' => $plan->code,
            'plan_name_snapshot' => $plan->name,
            'last_transition_occurred_at' => now(),
        ]);

        return [$org, $user];
    }

    private function enableCustomerMeter(): void
    {
        config(['ai_credit_shadow.customer_meter_enabled' => true]);
    }

    public function test_meter_is_unavailable_when_feature_flag_disabled(): void
    {
        // Explicit, not relying on the environment's own default — this
        // local dev environment now has AI_CREDIT_CUSTOMER_METER_ENABLED=true
        // in .env for real testing, so this test must set false itself.
        config(['ai_credit_shadow.customer_meter_enabled' => false]);
        [$org, $user] = $this->orgWithActiveSubscription($this->essentialPlan());
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/billing/ai-credit-usage')->assertOk();

        $response->assertJsonPath('available', false);
        $response->assertJsonPath('usage_percent', null);
    }

    public function test_meter_is_unavailable_with_no_subscription(): void
    {
        $this->enableCustomerMeter();
        $org = Organization::create(['name' => 'Org', 'slug' => 'org-' . uniqid()]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/billing/ai-credit-usage')->assertOk();

        $response->assertJsonPath('available', false);
    }

    public function test_meter_is_unavailable_when_allowance_not_configured(): void
    {
        $this->enableCustomerMeter();
        // A plan code with no ai_credits_per_month row at all (defaults to
        // not-applicable/zero via FeatureGate — never a guessed number).
        $plan = PricingPlan::create(['code' => 'unconfigured', 'slug' => 'unconfigured-' . uniqid(), 'name' => 'Unconfigured']);
        [$org, $user] = $this->orgWithActiveSubscription($plan);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/billing/ai-credit-usage')->assertOk();

        $response->assertJsonPath('available', false);
    }

    public function test_meter_reports_correct_percentage_from_settled_usage_only(): void
    {
        $this->enableCustomerMeter();
        [$org, $user] = $this->orgWithActiveSubscription($this->essentialPlan());
        $ledger = app(AiCreditLedgerService::class);

        // 60 settled (should count) + 20 still-open reserve (must NOT count).
        $ledger->reserve($org->id, 'contract_analysis', 'TestSubject', 1, 60, 'Reserve', 'reserve-1');
        $ledger->settle('TestSubject', 1, 'Settle', 'settle-1');
        $ledger->reserve($org->id, 'contract_analysis', 'TestSubject', 2, 20, 'Reserve (still open)', 'reserve-2');

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/billing/ai-credit-usage')->assertOk();

        $response->assertJsonPath('available', true);
        $response->assertJsonPath('usage_percent', 60);
        $response->assertJsonPath('status', 'healthy');
    }

    public function test_usage_percent_clamps_at_100_when_actual_usage_exceeds_allowance(): void
    {
        $this->enableCustomerMeter();
        [$org, $user] = $this->orgWithActiveSubscription($this->essentialPlan());
        $ledger = app(AiCreditLedgerService::class);

        $ledger->reserve($org->id, 'contract_analysis', 'TestSubject', 1, 140, 'Reserve', 'reserve-1');
        $ledger->settle('TestSubject', 1, 'Settle', 'settle-1');

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/billing/ai-credit-usage')->assertOk();

        $response->assertJsonPath('usage_percent', 100);
        $response->assertJsonPath('status', 'exceeded');

        // The raw over-100% figure must never appear anywhere in the response body.
        $this->assertStringNotContainsString('140', $response->getContent());
    }

    public function test_raw_allowance_and_used_figures_never_appear_in_the_response(): void
    {
        $this->enableCustomerMeter();
        [$org, $user] = $this->orgWithActiveSubscription($this->essentialPlan());
        $ledger = app(AiCreditLedgerService::class);
        $ledger->reserve($org->id, 'contract_analysis', 'TestSubject', 1, 60, 'Reserve', 'reserve-1');
        $ledger->settle('TestSubject', 1, 'Settle', 'settle-1');

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/billing/ai-credit-usage')->assertOk();

        $keys = array_keys($response->json());
        sort($keys);
        $this->assertSame(['available', 'enforcement_enabled', 'resets_at', 'status', 'usage_percent'], $keys);
    }

    public function test_customer_meter_flag_is_independent_of_operating_mode(): void
    {
        $this->enableCustomerMeter();
        SuresignSetting::instance()->update(['ai_credit_operating_mode' => AiCreditOperatingMode::SHADOW]);
        [, $user] = $this->orgWithActiveSubscription($this->essentialPlan());
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/billing/ai-credit-usage')->assertOk();

        $response->assertJsonPath('available', true);
        $response->assertJsonPath('enforcement_enabled', false);
    }

    public function test_enforcement_enabled_field_reflects_the_enforced_operating_mode(): void
    {
        $this->enableCustomerMeter();
        SuresignSetting::instance()->update(['ai_credit_operating_mode' => AiCreditOperatingMode::ENFORCED]);
        [, $user] = $this->orgWithActiveSubscription($this->essentialPlan());
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/billing/ai-credit-usage')->assertOk();

        $response->assertJsonPath('enforcement_enabled', true);
    }

    public function test_organisation_isolation_no_cross_organisation_leakage(): void
    {
        $this->enableCustomerMeter();
        $plan = $this->essentialPlan();
        [$orgA, $userA] = $this->orgWithActiveSubscription($plan);
        [$orgB, $userB] = $this->orgWithActiveSubscription($plan);

        $ledger = app(AiCreditLedgerService::class);
        $ledger->reserve($orgA->id, 'contract_analysis', 'TestSubject', 1, 90, 'Reserve A', 'reserve-a-1');
        $ledger->settle('TestSubject', 1, 'Settle A', 'settle-a-1');
        $ledger->reserve($orgB->id, 'contract_analysis', 'TestSubject', 2, 10, 'Reserve B', 'reserve-b-1');
        $ledger->settle('TestSubject', 2, 'Settle B', 'settle-b-1');

        Sanctum::actingAs($userB);

        // No organisation_id parameter exists on this endpoint at all — confirm
        // attempting one has zero effect; the response is always the caller's own.
        $response = $this->getJson("/api/billing/ai-credit-usage?organization_id={$orgA->id}")->assertOk();

        $response->assertJsonPath('usage_percent', 10);
    }
}
