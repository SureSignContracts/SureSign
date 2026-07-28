<?php

namespace Tests\Feature\Pricing;

use App\Models\Organization;
use App\Models\PricingPlan;
use App\Models\User;
use App\Services\Pricing\PricingManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PricingPopularPlanTest extends TestCase
{
    use RefreshDatabase;

    private function makeSuperAdmin(): User
    {
        $org  = Organization::create(['name' => 'Org', 'slug' => 'org', 'timezone' => 'Europe/London']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));

        return $user;
    }

    public function test_marking_a_second_plan_popular_clears_the_first(): void
    {
        $actor = $this->makeSuperAdmin();
        $service = app(PricingManagementService::class);

        $planA = PricingPlan::create(['code' => 'plan-a', 'slug' => 'plan-a', 'name' => 'Plan A', 'is_popular' => true]);
        $planB = PricingPlan::create(['code' => 'plan-b', 'slug' => 'plan-b', 'name' => 'Plan B']);

        $service->updatePlan($planB, ['is_popular' => true], $actor);

        $this->assertFalse($planA->fresh()->is_popular);
        $this->assertTrue($planB->fresh()->is_popular);
    }

    public function test_creating_a_popular_plan_clears_existing_popular_plan(): void
    {
        $actor = $this->makeSuperAdmin();
        $service = app(PricingManagementService::class);

        $planA = PricingPlan::create(['code' => 'plan-a', 'slug' => 'plan-a', 'name' => 'Plan A', 'is_popular' => true]);

        $planB = $service->createPlan(['code' => 'plan-b', 'slug' => 'plan-b', 'name' => 'Plan B', 'is_popular' => true], $actor);

        $this->assertFalse($planA->fresh()->is_popular);
        $this->assertTrue($planB->fresh()->is_popular);
    }
}
