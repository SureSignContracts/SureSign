<?php

namespace Tests\Feature\Pricing;

use App\Models\Organization;
use App\Models\PricingFeature;
use App\Models\PricingFeatureSection;
use App\Models\PricingPlan;
use App\Models\PricingPlanFeature;
use App\Models\User;
use App\Services\Pricing\PricingManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PricingPlanLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function makeSuperAdmin(): User
    {
        $org  = Organization::create(['name' => 'Org', 'slug' => 'org', 'timezone' => 'Europe/London']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));

        return $user;
    }

    private function service(): PricingManagementService
    {
        return app(PricingManagementService::class);
    }

    public function test_new_plan_defaults_to_draft(): void
    {
        $plan = PricingPlan::create(['code' => 'starter', 'slug' => 'starter', 'name' => 'Starter']);

        $this->assertEquals('draft', $plan->status);
        $this->assertNull($plan->published_at);
    }

    public function test_publish_sets_status_active_and_published_at(): void
    {
        $plan  = PricingPlan::create(['code' => 'starter', 'slug' => 'starter', 'name' => 'Starter']);
        $actor = $this->makeSuperAdmin();

        $published = $this->service()->publishPlan($plan, $actor);

        $this->assertEquals('active', $published->status);
        $this->assertNotNull($published->published_at);
    }

    public function test_archive_sets_status_archived_and_hides_from_public_scope(): void
    {
        $plan  = PricingPlan::create(['code' => 'starter', 'slug' => 'starter', 'name' => 'Starter', 'status' => 'active', 'published_at' => now()]);
        $actor = $this->makeSuperAdmin();

        $this->service()->archivePlan($plan, $actor);

        $this->assertEquals(0, PricingPlan::active()->count());
    }

    public function test_never_published_plan_with_no_comparison_rows_is_hard_deleted(): void
    {
        $plan  = PricingPlan::create(['code' => 'starter', 'slug' => 'starter', 'name' => 'Starter']);
        $actor = $this->makeSuperAdmin();

        $deleted = $this->service()->deleteOrArchivePlan($plan, $actor);

        $this->assertTrue($deleted);
        $this->assertSoftDeleted('pricing_plans', ['id' => $plan->id]);
    }

    public function test_published_plan_is_archived_instead_of_deleted(): void
    {
        $plan  = PricingPlan::create(['code' => 'starter', 'slug' => 'starter', 'name' => 'Starter', 'status' => 'active', 'published_at' => now()]);
        $actor = $this->makeSuperAdmin();

        $deleted = $this->service()->deleteOrArchivePlan($plan, $actor);

        $this->assertFalse($deleted);
        $this->assertEquals('archived', $plan->fresh()->status);
        $this->assertDatabaseHas('pricing_plans', ['id' => $plan->id, 'status' => 'archived']);
    }

    public function test_plan_with_comparison_rows_is_archived_instead_of_deleted(): void
    {
        $plan    = PricingPlan::create(['code' => 'starter', 'slug' => 'starter', 'name' => 'Starter']);
        $section = PricingFeatureSection::create(['name' => 'Section']);
        $feature = PricingFeature::create(['section_id' => $section->id, 'name' => 'Feature']);
        PricingPlanFeature::create(['plan_id' => $plan->id, 'feature_id' => $feature->id, 'status' => 'included']);
        $actor = $this->makeSuperAdmin();

        $deleted = $this->service()->deleteOrArchivePlan($plan, $actor);

        $this->assertFalse($deleted);
        $this->assertEquals('archived', $plan->fresh()->status);
    }
}
