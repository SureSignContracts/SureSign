<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Services\Monitoring\ApplicationMonitoringService;
use App\Services\TimezoneResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApplicationMonitoringMetricsTest extends TestCase
{
    use RefreshDatabase;

    public function test_summary_degrades_gracefully_with_no_data(): void
    {
        $payload = app(ApplicationMonitoringService::class)->summary();

        $this->assertFalse($payload['presence']['available']); // Redis unreachable in this environment
        $this->assertNotEmpty($payload['warnings']);
        $this->assertContains('presence', $payload['unavailable_sources']);

        $this->assertSame(0, $payload['queue']['pending_jobs']);
        $this->assertSame(0, $payload['queue']['failed_jobs_total']);
        $this->assertSame('healthy', $payload['queue']['status']);

        $this->assertSame(0, $payload['ai']['pending']);
        $this->assertSame(0, $payload['documents']['uploaded_today']);
    }

    public function test_dau_wau_mau_are_distinct_user_counts(): void
    {
        $org = Organization::create(['name' => 'Acme', 'slug' => 'acme-' . uniqid(), 'timezone' => 'Europe/London']);
        $userA = User::factory()->create(['organization_id' => $org->id]);
        $userB = User::factory()->create(['organization_id' => $org->id]);

        $today = TimezoneResolver::today()->toDateString();
        $fourDaysAgo = TimezoneResolver::today()->subDays(4)->toDateString();
        $twentyDaysAgo = TimezoneResolver::today()->subDays(20)->toDateString();
        $fortyDaysAgo = TimezoneResolver::today()->subDays(40)->toDateString();

        // userA active today and 4 days ago (should not double-count for DAU).
        DB::table('daily_active_users')->insert([
            ['activity_date' => $today, 'user_id' => $userA->id, 'organization_id' => $org->id, 'created_at' => now(), 'updated_at' => now()],
            ['activity_date' => $fourDaysAgo, 'user_id' => $userA->id, 'organization_id' => $org->id, 'created_at' => now(), 'updated_at' => now()],
            ['activity_date' => $twentyDaysAgo, 'user_id' => $userB->id, 'organization_id' => $org->id, 'created_at' => now(), 'updated_at' => now()],
            ['activity_date' => $fortyDaysAgo, 'user_id' => $userB->id, 'organization_id' => $org->id, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $payload = app(ApplicationMonitoringService::class)->summary();

        $this->assertSame(1, $payload['active_users']['dau']); // userA only
        $this->assertSame(1, $payload['active_users']['wau']); // userA only (userB's most recent entry is 20 days ago)
        $this->assertSame(2, $payload['active_users']['mau']); // both users have an entry within 30 days
    }

    public function test_queue_and_ai_handle_empty_tables_without_error(): void
    {
        $payload = app(ApplicationMonitoringService::class)->summary();

        $this->assertIsArray($payload['queue']);
        $this->assertIsArray($payload['ai']);
        $this->assertArrayNotHasKey('exception', $payload);
    }

    public function test_endpoint_returns_partial_data_with_warnings_when_presence_unavailable(): void
    {
        $org = Organization::create(['name' => 'Org', 'slug' => 'org-' . uniqid(), 'timezone' => 'Europe/London']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/admin/application-monitoring')->assertStatus(200);

        $response->assertJsonPath('presence.available', false);
        $this->assertNotEmpty($response->json('warnings'));
    }
}
