<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Services\Monitoring\UserPresenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserPresenceServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(): User
    {
        $org = Organization::create(['name' => 'Acme', 'slug' => 'acme-' . uniqid(), 'timezone' => 'Europe/London']);
        $user = User::factory()->create(['organization_id' => $org->id, 'name' => 'Jane Doe', 'email' => 'jane@example.com']);
        $user->assignRole(Role::firstOrCreate(['name' => 'Client', 'guard_name' => 'web']));

        return $user;
    }

    public function test_meaningful_activity_records_presence(): void
    {
        $user = $this->makeUser();

        Redis::shouldReceive('set')->once()
            ->withArgs(fn ($key) => str_contains($key, 'monitoring:presence:throttle:' . $user->id))
            ->andReturn(true);
        Redis::shouldReceive('zadd')->once();
        Redis::shouldReceive('hset')->once();

        (new UserPresenceService())->recordActivity($user, 'dashboard');
    }

    public function test_refresh_is_throttled(): void
    {
        $user = $this->makeUser();

        // Throttle key already claimed by another request in the last 60s.
        Redis::shouldReceive('set')
            ->withArgs(fn ($key) => str_contains($key, 'monitoring:presence:throttle:'))
            ->andReturn(false);
        Redis::shouldReceive('zadd')->never();
        Redis::shouldReceive('hset')->never();

        (new UserPresenceService())->recordActivity($user, 'dashboard');
    }

    public function test_redis_failure_does_not_throw(): void
    {
        $user = $this->makeUser();

        // No Redis mock configured — the real (unavailable) driver throws,
        // and recordActivity must swallow it rather than bubble up.
        (new UserPresenceService())->recordActivity($user, 'dashboard');

        $this->assertTrue(true);
    }

    public function test_presence_unavailable_is_distinct_from_zero_online(): void
    {
        $service = new UserPresenceService();

        // Real driver is unreachable in this environment (no redis
        // extension installed) — isAvailable() must report false, and
        // getOnlineUsers() must return null (unknown), not an empty array
        // (which would mean "zero users online").
        $this->assertFalse($service->isAvailable());
        $this->assertNull($service->getOnlineUsers());
        $this->assertNull($service->getOnlineCount());
    }

    public function test_online_users_are_decoded_from_presence_payload(): void
    {
        $service = new UserPresenceService();
        $now = time();

        Redis::shouldReceive('zrangebyscore')
            ->withArgs(fn ($key, $min) => $key === 'monitoring:presence:index' && str_starts_with((string) $min, '-inf'))
            ->andReturn([]); // nothing stale to prune

        Redis::shouldReceive('zrangebyscore')
            ->withArgs(fn ($key, $min, $max) => $key === 'monitoring:presence:index' && $max === '+inf' && is_numeric($min))
            ->andReturn(['5']);

        Redis::shouldReceive('hmget')->once()->andReturn([json_encode([
            'user_id' => 5, 'name' => 'Jane Doe', 'email' => 'jane@example.com',
            'role' => 'Client', 'organization_id' => 1, 'organization_name' => 'Acme',
            'module_key' => 'dashboard', 'last_active_at' => $now,
        ])]);

        $users = $service->getOnlineUsers();

        $this->assertIsArray($users);
        $this->assertCount(1, $users);
        $this->assertSame('jane@example.com', $users[0]['email']);
        $this->assertArrayNotHasKey('ip_address', $users[0]);
        $this->assertArrayNotHasKey('token', $users[0]);
    }
}
