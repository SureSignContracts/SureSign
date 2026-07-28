<?php

namespace Tests\Feature\Pricing;

use App\Models\Organization;
use App\Models\PricingPlan;
use App\Models\User;
use App\Services\Pricing\PricingManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PricingCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The 'array' cache store used in tests is a single in-memory table
        // shared across every test in the process — RefreshDatabase resets
        // the database between tests but not this, so a payload cached by an
        // earlier test would otherwise leak into this one.
        Cache::flush();
    }

    private function makeSuperAdmin(): User
    {
        $org  = Organization::create(['name' => 'Org', 'slug' => 'org', 'timezone' => 'Europe/London']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));

        return $user;
    }

    public function test_public_payload_is_cached_between_calls(): void
    {
        $service = app(PricingManagementService::class);

        $first = $service->publicPayload();
        PricingPlan::create(['code' => 'sneaky', 'slug' => 'sneaky', 'name' => 'Sneaky', 'status' => 'active', 'published_at' => now()]);
        $second = $service->publicPayload();

        // Written directly to the DB bypassing the service, so the cached
        // payload should NOT yet reflect it.
        $this->assertEquals($first, $second);
    }

    public function test_admin_write_busts_the_cache_and_next_request_reflects_change(): void
    {
        $actor   = $this->makeSuperAdmin();
        $service = app(PricingManagementService::class);

        $before = $service->publicPayload();
        $this->assertCount(0, $before['plans']);

        $plan = $service->createPlan(['code' => 'starter', 'slug' => 'starter', 'name' => 'Starter'], $actor);
        $service->publishPlan($plan, $actor);

        $after = $service->publicPayload();
        $this->assertCount(1, $after['plans']);
    }
}
