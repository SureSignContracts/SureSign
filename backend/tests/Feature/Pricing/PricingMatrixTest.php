<?php

namespace Tests\Feature\Pricing;

use App\Models\Organization;
use App\Models\PricingFeature;
use App\Models\PricingFeatureSection;
use App\Models\PricingPlan;
use App\Models\PricingPlanFeature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PricingMatrixTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsSuperAdmin(): void
    {
        $org  = Organization::create(['name' => 'Org', 'slug' => 'org', 'timezone' => 'Europe/London']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));

        Sanctum::actingAs($user);
    }

    public function test_new_feature_seeds_not_included_rows_for_every_active_plan(): void
    {
        $this->actingAsSuperAdmin();

        $active  = PricingPlan::create(['code' => 'active-plan', 'slug' => 'active-plan', 'name' => 'Active', 'status' => 'active', 'published_at' => now()]);
        $draft   = PricingPlan::create(['code' => 'draft-plan', 'slug' => 'draft-plan', 'name' => 'Draft', 'status' => 'draft']);
        $section = PricingFeatureSection::create(['name' => 'Section']);

        $response = $this->postJson('/api/admin/pricing/features', ['section_id' => $section->id, 'name' => 'New Feature'])
            ->assertCreated();

        $featureId = $response->json('data.id');

        $this->assertDatabaseHas('pricing_plan_features', [
            'plan_id' => $active->id, 'feature_id' => $featureId, 'status' => 'not_included',
        ]);
        $this->assertDatabaseMissing('pricing_plan_features', [
            'plan_id' => $draft->id, 'feature_id' => $featureId,
        ]);
    }

    public function test_bulk_matrix_update_applies_all_cells_transactionally(): void
    {
        $this->actingAsSuperAdmin();

        $planA   = PricingPlan::create(['code' => 'plan-a', 'slug' => 'plan-a', 'name' => 'Plan A', 'status' => 'active', 'published_at' => now()]);
        $planB   = PricingPlan::create(['code' => 'plan-b', 'slug' => 'plan-b', 'name' => 'Plan B', 'status' => 'active', 'published_at' => now()]);
        $section = PricingFeatureSection::create(['name' => 'Section']);
        $feature = PricingFeature::create(['section_id' => $section->id, 'name' => 'Feature']);

        $this->putJson('/api/admin/pricing/matrix', [
            'updates' => [
                ['plan_id' => $planA->id, 'feature_id' => $feature->id, 'status' => 'included'],
                ['plan_id' => $planB->id, 'feature_id' => $feature->id, 'status' => 'limited', 'value_text' => '5 users'],
            ],
        ])->assertOk();

        $this->assertDatabaseHas('pricing_plan_features', ['plan_id' => $planA->id, 'feature_id' => $feature->id, 'status' => 'included']);
        $this->assertDatabaseHas('pricing_plan_features', ['plan_id' => $planB->id, 'feature_id' => $feature->id, 'status' => 'limited', 'value_text' => '5 users']);
    }

    public function test_bulk_matrix_update_rejects_invalid_status(): void
    {
        $this->actingAsSuperAdmin();

        $plan    = PricingPlan::create(['code' => 'plan-a', 'slug' => 'plan-a', 'name' => 'Plan A']);
        $section = PricingFeatureSection::create(['name' => 'Section']);
        $feature = PricingFeature::create(['section_id' => $section->id, 'name' => 'Feature']);

        $this->putJson('/api/admin/pricing/matrix', [
            'updates' => [
                ['plan_id' => $plan->id, 'feature_id' => $feature->id, 'status' => 'sort-of-included'],
            ],
        ])->assertUnprocessable();
    }
}
