<?php

namespace Tests\Feature\Pricing;

use App\Models\Organization;
use App\Models\PricingPlan;
use App\Models\PricingPlanEntitlement;
use App\Models\User;
use App\Services\Entitlements\PlanEntitlementRepository;
use App\Support\Entitlements\Feature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase G2, Stage 6-7 — "Copy Existing Plan" and "Create Blank Plan".
 * Confirms commercial fields and entitlement rows are duplicated, Stripe
 * identity/popularity/publish state are never copied, and a blank plan
 * still gets the G1 conservative baseline.
 */
class PricingCopyPlanTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(string $role = 'Super Admin'): User
    {
        static $n = 0;
        $n++;

        $org  = Organization::create(['name' => "Org {$n}", 'slug' => "org-{$n}", 'timezone' => 'Europe/London']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole(Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']));

        return $user;
    }

    public function test_copy_plan_duplicates_commercial_fields_and_entitlements(): void
    {
        Sanctum::actingAs($this->makeAdmin());

        $source = PricingPlan::create([
            'code' => 'professional', 'slug' => 'professional', 'name' => 'Professional',
            'monthly_price' => 49.99, 'annual_price' => 499.99, 'currency' => 'GBP',
            'summary' => 'For growing teams', 'is_popular' => true, 'status' => 'active', 'published_at' => now(),
        ]);
        app(PlanEntitlementRepository::class)->initializeDefaultsForPlan($source);
        PricingPlanEntitlement::where('pricing_plan_id', $source->id)
            ->where('feature_key', Feature::MAX_ACTIVE_PROJECTS)
            ->update(['value' => json_encode(25)]);

        $response = $this->postJson("/api/admin/pricing/plans/{$source->id}/copy", [
            'code' => 'professional-copy',
            'slug' => 'professional-copy',
            'name' => 'Professional (Copy)',
        ])->assertCreated();

        $copyId = $response->json('data.id');
        $copy = PricingPlan::findOrFail($copyId);

        $this->assertEquals('49.99', (string) $copy->monthly_price);
        $this->assertEquals('For growing teams', $copy->summary);
        $this->assertEquals('draft', $copy->status);
        $this->assertNull($copy->published_at);
        $this->assertFalse($copy->is_popular);

        $copiedValue = PricingPlanEntitlement::where('pricing_plan_id', $copy->id)
            ->where('feature_key', Feature::MAX_ACTIVE_PROJECTS)->first();
        $this->assertEquals(25, $copiedValue->value);

        $this->assertEquals(
            PricingPlanEntitlement::where('pricing_plan_id', $source->id)->count(),
            PricingPlanEntitlement::where('pricing_plan_id', $copy->id)->count(),
        );
    }

    public function test_copy_plan_never_copies_stripe_provider_prices(): void
    {
        Sanctum::actingAs($this->makeAdmin());

        $source = PricingPlan::create(['code' => 'essential', 'slug' => 'essential', 'name' => 'Essential']);
        $source->providerPrices()->create([
            'provider' => 'stripe', 'billing_interval' => 'monthly', 'currency' => 'GBP',
            'provider_product_id' => 'prod_123', 'provider_price_id' => 'price_123',
            'livemode' => false, 'unit_amount' => 1999, 'is_active' => true,
        ]);

        $response = $this->postJson("/api/admin/pricing/plans/{$source->id}/copy", [
            'code' => 'essential-copy', 'slug' => 'essential-copy', 'name' => 'Essential (Copy)',
        ])->assertCreated();

        $copy = PricingPlan::findOrFail($response->json('data.id'));
        $this->assertEquals(0, $copy->providerPrices()->count());
    }

    public function test_copy_plan_requires_a_new_unique_code_and_slug(): void
    {
        Sanctum::actingAs($this->makeAdmin());

        $source = PricingPlan::create(['code' => 'essential', 'slug' => 'essential', 'name' => 'Essential']);

        $this->postJson("/api/admin/pricing/plans/{$source->id}/copy", [
            'code' => 'essential', 'slug' => 'new-slug', 'name' => 'Essential Duplicate',
        ])->assertStatus(422);
    }

    public function test_blank_plan_creation_still_gets_conservative_baseline(): void
    {
        Sanctum::actingAs($this->makeAdmin('Admin'));

        $response = $this->postJson('/api/admin/pricing/plans', [
            'code' => 'brand-new', 'slug' => 'brand-new', 'name' => 'Brand New',
        ])->assertCreated();

        $planId = $response->json('data.id');
        $nonDormant = array_values(array_filter(Feature::ALL, fn (string $k) => !Feature::isDormant($k)));

        $this->assertEquals(count($nonDormant), PricingPlanEntitlement::where('pricing_plan_id', $planId)->count());
        $this->assertDatabaseHas('pricing_plan_entitlements', [
            'pricing_plan_id' => $planId,
            'feature_key' => Feature::CUSTOM_BRANDING,
            'value' => json_encode(false),
        ]);
    }

    public function test_client_cannot_copy_plans(): void
    {
        Sanctum::actingAs($this->makeAdmin('Client'));

        $source = PricingPlan::create(['code' => 'essential', 'slug' => 'essential', 'name' => 'Essential']);

        $this->postJson("/api/admin/pricing/plans/{$source->id}/copy", [
            'code' => 'essential-copy', 'slug' => 'essential-copy', 'name' => 'Copy',
        ])->assertForbidden();
    }
}
