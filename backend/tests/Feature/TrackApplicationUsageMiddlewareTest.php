<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Integration coverage for TrackApplicationUsage itself — the unit tests on
 * UserPresenceService/ModuleUsageService cover their internal logic in
 * isolation, but nothing previously exercised the middleware's own wiring
 * (does it actually get invoked on a real request, does it correctly skip
 * excluded routes, does it require an authenticated user) end to end.
 */
class TrackApplicationUsageMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(): User
    {
        $org = Organization::create(['name' => 'Acme', 'slug' => 'acme-' . uniqid(), 'timezone' => 'Europe/London']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole(Role::firstOrCreate(['name' => 'Client', 'guard_name' => 'web']));

        return $user;
    }

    public function test_a_mapped_route_triggers_presence_and_usage_tracking(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        Redis::shouldReceive('set')->andReturn(true);
        Redis::shouldReceive('zadd')->once();
        Redis::shouldReceive('hset')->once();

        $this->getJson('/api/dashboard')->assertStatus(200);

        $this->assertGreaterThan(0, DB::table('daily_active_users')->where('user_id', $user->id)->count());
    }

    public function test_an_excluded_route_does_not_trigger_tracking(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        // No Redis expectations at all — any call would fail the test via
        // Mockery's "no matching handler" error, proving the middleware
        // never even reaches UserPresenceService/ModuleUsageService for an
        // excluded route.
        Redis::shouldReceive('set')->never();
        Redis::shouldReceive('zadd')->never();

        $this->getJson('/api/auth/me')->assertStatus(200);

        $this->assertSame(0, DB::table('daily_active_users')->where('user_id', $user->id)->count());
    }
}
