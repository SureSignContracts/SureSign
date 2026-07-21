<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Services\Monitoring\ModuleUsageService;
use App\Services\TimezoneResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class ModuleUsageServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(): User
    {
        $org = Organization::create(['name' => 'Acme', 'slug' => 'acme-' . uniqid(), 'timezone' => 'Europe/London']);

        return User::factory()->create(['organization_id' => $org->id]);
    }

    public function test_first_visit_creates_aggregate_row_with_one_visit_and_one_unique_user(): void
    {
        $user = $this->makeUser();

        Redis::shouldReceive('set')->andReturn(true); // every throttle/dedup check "wins"

        (new ModuleUsageService())->recordVisit($user, 'contracts');

        $today = TimezoneResolver::today()->toDateString();
        $row = DB::table('module_usage_daily')
            ->where('usage_date', $today)->where('module_key', 'contracts')->where('organization_id', $user->organization_id)
            ->first();

        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row->total_visits);
        $this->assertSame(1, (int) $row->unique_users);
    }

    public function test_throttled_visit_within_window_does_not_write_to_database(): void
    {
        $user = $this->makeUser();

        // Throttle key already claimed — this call must be a pure no-op.
        Redis::shouldReceive('set')->andReturn(false);

        (new ModuleUsageService())->recordVisit($user, 'contracts');

        $this->assertSame(0, DB::table('module_usage_daily')->count());
        $this->assertSame(0, DB::table('daily_active_users')->count());
    }

    public function test_repeat_visits_increment_total_but_not_unique_users(): void
    {
        $user = $this->makeUser();
        $service = new ModuleUsageService();
        $today = TimezoneResolver::today()->toDateString();

        // Each Redis dedup key is claimed ("first" -> true) only once; a
        // second SETNX-style claim on the same key returns false, exactly
        // like real Redis. Per-prefix sequences avoid cross-key bleed:
        // the module throttle key is claimed on both visits below (its
        // 5-minute window has "passed" by visit 2), but the "unique today"
        // key is still held from visit 1, so only total_visits grows.
        Redis::shouldReceive('set')
            ->withArgs(fn ($key) => str_starts_with($key, 'monitoring:usage:daily-active:'))
            ->andReturn(true, false);
        Redis::shouldReceive('set')
            ->withArgs(fn ($key) => str_starts_with($key, 'monitoring:usage:throttle:'))
            ->andReturn(true, true);
        Redis::shouldReceive('set')
            ->withArgs(fn ($key) => str_starts_with($key, 'monitoring:usage:unique:'))
            ->andReturn(true, false);

        $service->recordVisit($user, 'contracts');
        $service->recordVisit($user, 'contracts');

        $row = DB::table('module_usage_daily')
            ->where('usage_date', $today)->where('module_key', 'contracts')->where('organization_id', $user->organization_id)
            ->first();

        $this->assertSame(2, (int) $row->total_visits);
        $this->assertSame(1, (int) $row->unique_users);
    }

    public function test_different_users_each_count_as_unique(): void
    {
        $org = Organization::create(['name' => 'Acme', 'slug' => 'acme-' . uniqid(), 'timezone' => 'Europe/London']);
        $userA = User::factory()->create(['organization_id' => $org->id]);
        $userB = User::factory()->create(['organization_id' => $org->id]);

        Redis::shouldReceive('set')->andReturn(true);

        $service = new ModuleUsageService();
        $service->recordVisit($userA, 'contracts');
        $service->recordVisit($userB, 'contracts');

        $today = TimezoneResolver::today()->toDateString();
        $row = DB::table('module_usage_daily')
            ->where('usage_date', $today)->where('module_key', 'contracts')->where('organization_id', $org->id)
            ->first();

        $this->assertSame(2, (int) $row->total_visits);
        $this->assertSame(2, (int) $row->unique_users);
    }

    public function test_same_user_counted_separately_per_module(): void
    {
        $user = $this->makeUser();

        Redis::shouldReceive('set')->andReturn(true);

        $service = new ModuleUsageService();
        $service->recordVisit($user, 'contracts');
        $service->recordVisit($user, 'variations');

        $this->assertSame(2, DB::table('module_usage_daily')->count());
        $this->assertSame(1, DB::table('daily_active_users')->count());
    }

    public function test_redis_failure_does_not_break_the_request(): void
    {
        $user = $this->makeUser();

        // No Redis mock — the real driver is unavailable in this
        // environment and must be swallowed, not thrown.
        (new ModuleUsageService())->recordVisit($user, 'contracts');

        $this->assertSame(0, DB::table('module_usage_daily')->count());
    }

    public function test_empty_usage_range_returns_empty_array(): void
    {
        $today = TimezoneResolver::today();
        $this->assertSame([], (new ModuleUsageService())->getUsageForRange($today, $today));
    }
}
