<?php

namespace Tests\Feature\Billing;

use App\Models\Organization;
use App\Models\PricingPlan;
use App\Models\PricingPlanProviderPrice;
use App\Models\User;
use App\Services\Billing\Exceptions\PlanPriceMappingException;
use App\Services\Billing\FakeBillingProvider;
use App\Services\Billing\PlanPriceMappingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanPriceMappingServiceTest extends TestCase
{
    use RefreshDatabase;

    private PlanPriceMappingService $service;
    private FakeBillingProvider $fake;
    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = $this->app->make(PlanPriceMappingService::class);
        $this->fake = $this->app->make(FakeBillingProvider::class);

        $org = Organization::create(['name' => 'Acme', 'slug' => 'acme-' . random_int(1, 1000000), 'timezone' => 'Europe/London']);
        $this->actor = User::factory()->create(['organization_id' => $org->id]);
    }

    private function plan(array $overrides = []): PricingPlan
    {
        return PricingPlan::create(array_merge([
            'code' => 'pro-' . random_int(1, 1000000),
            'slug' => 'pro-' . random_int(1, 1000000),
            'name' => 'Professional',
            'monthly_price' => 29.99,
            'currency' => 'GBP',
        ], $overrides));
    }

    public function test_creates_a_product_and_price_mapping_when_none_exists(): void
    {
        $plan = $this->plan();

        $mapping = $this->service->syncPlanPrice($plan, 'monthly', 'GBP', '29.99', $this->actor);

        $this->assertSame(2999, $mapping->unit_amount);
        $this->assertSame('GBP', $mapping->currency);
        $this->assertSame('monthly', $mapping->billing_interval);
        $this->assertTrue($mapping->is_active);
        $this->assertFalse($mapping->livemode);
        $this->assertNotEmpty($mapping->provider_product_id);
        $this->assertNotEmpty($mapping->provider_price_id);
        $this->assertCount(1, $this->fake->products);
        $this->assertCount(1, $this->fake->prices);
    }

    public function test_repeated_synchronization_is_idempotent(): void
    {
        $plan = $this->plan();

        $first = $this->service->syncPlanPrice($plan, 'monthly', 'GBP', '29.99', $this->actor);
        $second = $this->service->syncPlanPrice($plan, 'monthly', 'GBP', '29.99', $this->actor);

        $this->assertTrue($first->is($second));
        $this->assertCount(1, $this->fake->products);
        $this->assertCount(1, $this->fake->prices);
        $this->assertDatabaseCount('pricing_plan_provider_prices', 1);
    }

    public function test_unchanged_pricing_reuses_the_existing_provider_price(): void
    {
        $plan = $this->plan();

        $first = $this->service->syncPlanPrice($plan, 'monthly', 'gbp', '29.99', $this->actor);
        $second = $this->service->syncPlanPrice($plan, 'monthly', 'GBP', 29.99, $this->actor);

        $this->assertSame($first->provider_price_id, $second->provider_price_id);
    }

    public function test_changed_amount_creates_a_new_immutable_provider_price_mapping(): void
    {
        $plan = $this->plan();

        $original = $this->service->syncPlanPrice($plan, 'monthly', 'GBP', '29.99', $this->actor);
        $updated = $this->service->syncPlanPrice($plan, 'monthly', 'GBP', '39.99', $this->actor);

        $this->assertNotSame($original->provider_price_id, $updated->provider_price_id);
        $this->assertSame(3999, $updated->unit_amount);

        $original->refresh();
        $this->assertFalse($original->is_active);
        $this->assertNotNull($original->effective_until);
        $this->assertTrue($updated->is_active);

        // The old provider Price is deactivated, never deleted.
        $this->assertArrayHasKey($original->provider_price_id, $this->fake->prices);
        $this->assertFalse($this->fake->prices[$original->provider_price_id]['active']);

        // Both mappings share the same Product — only one Product per plan.
        $this->assertSame($original->provider_product_id, $updated->provider_product_id);
        $this->assertCount(1, $this->fake->products);
    }

    public function test_changed_currency_creates_a_distinct_mapping(): void
    {
        $plan = $this->plan();

        $gbp = $this->service->syncPlanPrice($plan, 'monthly', 'GBP', '29.99', $this->actor);
        $usd = $this->service->syncPlanPrice($plan, 'monthly', 'USD', '34.99', $this->actor);

        $this->assertNotSame($gbp->provider_price_id, $usd->provider_price_id);
        $gbp->refresh();
        // A different currency is a distinct mapping, not a supersession —
        // the GBP mapping remains active alongside the new USD one.
        $this->assertTrue($gbp->is_active);
        $this->assertTrue($usd->is_active);
    }

    public function test_changed_interval_creates_a_distinct_mapping(): void
    {
        $plan = $this->plan();

        $monthly = $this->service->syncPlanPrice($plan, 'monthly', 'GBP', '29.99', $this->actor);
        $annual = $this->service->syncPlanPrice($plan, 'annual', 'GBP', '299.99', $this->actor);

        $this->assertNotSame($monthly->provider_price_id, $annual->provider_price_id);
        $monthly->refresh();
        $this->assertTrue($monthly->is_active);
        $this->assertTrue($annual->is_active);
    }

    public function test_historical_mapping_remains_available_after_a_price_change(): void
    {
        $plan = $this->plan();

        $original = $this->service->syncPlanPrice($plan, 'monthly', 'GBP', '29.99', $this->actor);
        $this->service->syncPlanPrice($plan, 'monthly', 'GBP', '39.99', $this->actor);

        $this->assertDatabaseHas('pricing_plan_provider_prices', ['id' => $original->id]);
        $this->assertNotNull(PricingPlanProviderPrice::find($original->id));
    }

    public function test_inactive_superseded_mapping_is_not_chosen_for_new_sale(): void
    {
        $plan = $this->plan();

        $this->service->syncPlanPrice($plan, 'monthly', 'GBP', '29.99', $this->actor);
        $latest = $this->service->syncPlanPrice($plan, 'monthly', 'GBP', '39.99', $this->actor);

        $resolved = $this->service->resolveActivePrice($plan, 'monthly', 'GBP');

        $this->assertTrue($resolved->is($latest));
    }

    public function test_resolve_active_price_returns_null_when_never_synced(): void
    {
        $plan = $this->plan();

        $this->assertNull($this->service->resolveActivePrice($plan, 'monthly', 'GBP'));
    }

    /**
     * No unique DB constraint enforces "at most one active mapping per
     * plan/interval/currency/mode" — syncPlanPrice()'s own supersede-
     * before-create flow never produces this, but a manual/out-of-band
     * row could. resolveActivePrice() must fail loudly rather than
     * silently picking the most recent one (Stripe Sandbox Product/Price
     * Mapping checkpoint, Stage 8).
     */
    public function test_resolve_active_price_throws_on_duplicate_active_mapping(): void
    {
        $plan = $this->plan();

        PricingPlanProviderPrice::create([
            'pricing_plan_id' => $plan->id,
            'provider' => 'stripe',
            'billing_interval' => 'monthly',
            'currency' => 'GBP',
            'provider_product_id' => 'prod_dup_1',
            'provider_price_id' => 'price_dup_1',
            'livemode' => false,
            'unit_amount' => 2999,
            'is_active' => true,
        ]);
        PricingPlanProviderPrice::create([
            'pricing_plan_id' => $plan->id,
            'provider' => 'stripe',
            'billing_interval' => 'monthly',
            'currency' => 'GBP',
            'provider_product_id' => 'prod_dup_2',
            'provider_price_id' => 'price_dup_2',
            'livemode' => false,
            'unit_amount' => 2999,
            'is_active' => true,
        ]);

        $this->expectException(PlanPriceMappingException::class);
        $this->expectExceptionMessage('Multiple active provider price mappings exist');

        $this->service->resolveActivePrice($plan, 'monthly', 'GBP');
    }

    public function test_test_live_mismatch_is_rejected_on_deactivation(): void
    {
        $plan = $this->plan();
        $mapping = $this->service->syncPlanPrice($plan, 'monthly', 'GBP', '29.99', $this->actor);

        $this->fake->livemode = true;

        $this->expectException(PlanPriceMappingException::class);
        $this->service->deactivateMapping($mapping, $this->actor);
    }

    public function test_test_live_mismatch_is_rejected_on_reconciliation(): void
    {
        $plan = $this->plan();
        $mapping = $this->service->syncPlanPrice($plan, 'monthly', 'GBP', '29.99', $this->actor);

        $this->fake->livemode = true;

        $this->expectException(PlanPriceMappingException::class);
        $this->service->reconcileMapping($mapping);
    }

    public function test_foreign_plan_mapping_is_rejected(): void
    {
        $planA = $this->plan();
        $planB = $this->plan();
        $mapping = $this->service->syncPlanPrice($planA, 'monthly', 'GBP', '29.99', $this->actor);

        $this->expectException(PlanPriceMappingException::class);
        $this->service->assertMappingBelongsToPlan($mapping, $planB);
    }

    public function test_reconcile_mapping_detects_a_provider_side_amount_mismatch(): void
    {
        $plan = $this->plan();
        $mapping = $this->service->syncPlanPrice($plan, 'monthly', 'GBP', '29.99', $this->actor);

        // Simulate provider-side drift without going through the service.
        $this->fake->prices[$mapping->provider_price_id]['unit_amount'] = 999999;

        $this->expectException(PlanPriceMappingException::class);
        $this->service->reconcileMapping($mapping);
    }

    public function test_reconcile_mapping_passes_when_provider_state_matches(): void
    {
        $plan = $this->plan();
        $mapping = $this->service->syncPlanPrice($plan, 'monthly', 'GBP', '29.99', $this->actor);

        $this->service->reconcileMapping($mapping);
        $this->assertTrue(true); // no exception thrown
    }

    public function test_rejects_archived_plan(): void
    {
        $plan = $this->plan(['status' => 'archived']);

        $this->expectException(PlanPriceMappingException::class);
        $this->service->syncPlanPrice($plan, 'monthly', 'GBP', '29.99', $this->actor);
    }

    public function test_rejects_unsupported_interval(): void
    {
        $plan = $this->plan();

        $this->expectException(PlanPriceMappingException::class);
        $this->service->syncPlanPrice($plan, 'weekly', 'GBP', '29.99', $this->actor);
    }

    public function test_rejects_invalid_currency(): void
    {
        $plan = $this->plan();

        $this->expectException(PlanPriceMappingException::class);
        $this->service->syncPlanPrice($plan, 'monthly', 'GB', '29.99', $this->actor);
    }

    public function test_uses_the_money_helper_and_never_mutates_pricing_management_columns(): void
    {
        $plan = $this->plan(['monthly_price' => 29.99]);

        $this->service->syncPlanPrice($plan, 'monthly', 'GBP', '29.99', $this->actor);

        $plan->refresh();
        // Pricing Management's own decimal display column is completely
        // untouched by the provider-price sync.
        $this->assertSame('29.99', (string) $plan->monthly_price);
    }

    public function test_deactivate_mapping_deactivates_locally_and_on_the_provider(): void
    {
        $plan = $this->plan();
        $mapping = $this->service->syncPlanPrice($plan, 'monthly', 'GBP', '29.99', $this->actor);

        $this->service->deactivateMapping($mapping, $this->actor);

        $mapping->refresh();
        $this->assertFalse($mapping->is_active);
        $this->assertFalse($this->fake->prices[$mapping->provider_price_id]['active']);
    }

    /**
     * Deactivation only ever prevents new sale — the historical local
     * mapping row remains queryable by ID (an existing subscription's
     * `provider_price_id` foreign key reference stays resolvable), and the
     * provider-side Price object is confirmed still present, only its
     * `active` flag flipped.
     */
    public function test_deactivated_mapping_remains_queryable_and_the_provider_price_is_not_deleted(): void
    {
        $plan = $this->plan();
        $mapping = $this->service->syncPlanPrice($plan, 'monthly', 'GBP', '29.99', $this->actor);
        $providerPriceId = $mapping->provider_price_id;

        $this->service->deactivateMapping($mapping, $this->actor);

        $this->assertDatabaseHas('pricing_plan_provider_prices', ['id' => $mapping->id, 'provider_price_id' => $providerPriceId]);
        $this->assertNotNull(PricingPlanProviderPrice::find($mapping->id));
        $this->assertArrayHasKey($providerPriceId, $this->fake->prices);
        $this->assertArrayHasKey($mapping->provider_product_id, $this->fake->products);
    }

    /**
     * Structural safety property, not an assumption in a comment:
     * BillingProviderInterface has no method capable of deleting a
     * provider Price or Product at all — confirmed by inspecting the
     * installed stripe-php SDK directly (Price does not use
     * ApiOperations\Delete and has no delete() method; deactivation
     * (`active = false`) is the only state-changing operation Stripe's own
     * API exposes for a Price — see StripeBillingProvider::deactivatePrice()).
     * PlanPriceMappingService can therefore never delete a Price/Product
     * even by mistake, because no such call is reachable through the
     * interface it depends on.
     */
    public function test_billing_provider_interface_has_no_price_or_product_delete_capability(): void
    {
        $methods = array_map(
            fn (\ReflectionMethod $m) => strtolower($m->getName()),
            (new \ReflectionClass(\App\Services\Billing\BillingProviderInterface::class))->getMethods()
        );

        foreach ($methods as $method) {
            if (str_contains($method, 'price') || str_contains($method, 'product')) {
                $this->assertStringNotContainsStringIgnoringCase('delete', $method, "Unexpected delete-capable method: {$method}");
                $this->assertStringNotContainsStringIgnoringCase('destroy', $method, "Unexpected delete-capable method: {$method}");
                $this->assertStringNotContainsStringIgnoringCase('remove', $method, "Unexpected delete-capable method: {$method}");
            }
        }
    }
}
