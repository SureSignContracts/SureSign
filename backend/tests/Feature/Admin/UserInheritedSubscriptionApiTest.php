<?php

namespace Tests\Feature\Admin;

use App\Models\Organization;
use App\Models\PricingPlan;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Billing\SubscriptionStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase G4A — the Users page's read-only inherited-subscription display.
 * Covers: the paginated list's lightweight per-organisation summary (no
 * N+1 across users of the same organisation), the single-user detail
 * endpoint, platform operators (no organisation), and authorization.
 */
class UserInheritedSubscriptionApiTest extends TestCase
{
    use RefreshDatabase;

    private function org(string $name): Organization
    {
        return Organization::create([
            'name' => $name,
            'slug' => strtolower($name) . '-' . random_int(1, 10000000),
            'timezone' => 'Europe/London',
        ]);
    }

    private function plan(string $code): PricingPlan
    {
        return PricingPlan::create(['code' => $code, 'slug' => $code, 'name' => ucfirst($code), 'status' => 'active']);
    }

    private function activeSubscription(Organization $org, PricingPlan $plan): Subscription
    {
        return Subscription::create([
            'organization_id' => $org->id, 'pricing_plan_id' => $plan->id, 'provider' => 'stripe', 'livemode' => false,
            'internal_reference' => 'SUB-USR-' . $org->id, 'status' => SubscriptionStatus::ACTIVE, 'billing_interval' => 'monthly',
            'currency' => 'GBP', 'unit_amount' => 79900, 'quantity' => 1, 'subtotal_amount' => 79900, 'tax_amount' => 0,
            'total_amount' => 79900, 'starts_at' => now()->subDays(30), 'activated_at' => now()->subDays(30),
            'plan_code_snapshot' => $plan->code, 'plan_name_snapshot' => $plan->name,
        ]);
    }

    private function actingAsSuperAdmin(): User
    {
        $user = User::factory()->create(['organization_id' => null]);
        $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_users_list_includes_lightweight_organisation_subscription_summary(): void
    {
        $this->actingAsSuperAdmin();
        $org = $this->org('Acme');
        $plan = $this->plan('professional');
        $this->activeSubscription($org, $plan);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole(Role::firstOrCreate(['name' => 'Client', 'guard_name' => 'web']));

        $response = $this->getJson('/api/users')->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', $user->id);

        $this->assertFalse($row['is_platform_operator']);
        $this->assertSame($org->name, $row['organization_name']);
        $this->assertSame('full', $row['organization_subscription']['access_mode']);
        $this->assertSame('Professional', $row['organization_subscription']['plan_name']);
    }

    public function test_users_list_query_count_does_not_scale_with_users_sharing_an_organisation(): void
    {
        $this->actingAsSuperAdmin();
        $org = $this->org('Acme');
        $this->activeSubscription($org, $this->plan('professional'));
        User::factory()->count(10)->create(['organization_id' => $org->id]);

        \Illuminate\Support\Facades\DB::flushQueryLog();
        \Illuminate\Support\Facades\DB::enableQueryLog();
        $this->getJson('/api/users')->assertOk();
        $queries = \Illuminate\Support\Facades\DB::getQueryLog();
        \Illuminate\Support\Facades\DB::disableQueryLog();

        // Every relation involved (roles/organization/liveSubscription/
        // pricingPlan) is eager-loaded and batched into exactly one query
        // each, regardless of how many of the 10 users share the same
        // organisation — a per-user N+1 here would instead scale linearly
        // with the row count (10+ extra queries).
        $organizationQueries = collect($queries)->filter(fn ($q) => str_contains($q['query'], 'organizations'));
        $this->assertLessThanOrEqual(1, $organizationQueries->count());
        $this->assertLessThanOrEqual(8, count($queries));
    }

    public function test_platform_operator_shows_no_fake_plan(): void
    {
        $this->actingAsSuperAdmin();
        $operator = User::factory()->create(['organization_id' => null]);
        $operator->assignRole(Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']));

        $response = $this->getJson('/api/users')->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', $operator->id);

        $this->assertTrue($row['is_platform_operator']);
        $this->assertNull($row['organization_subscription']);
    }

    public function test_super_admin_with_no_organisation_is_platform_operator(): void
    {
        $this->actingAsSuperAdmin();
        $operator = User::factory()->create(['organization_id' => null]);
        $operator->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));

        $response = $this->getJson('/api/users')->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', $operator->id);

        $this->assertTrue($row['is_platform_operator']);
        $this->assertNull($row['organization_subscription']);
    }

    /**
     * Invitation & First-Time Account Setup follow-up — is_platform_operator
     * must be role-based, never inferred from organization_id === null. An
     * invited Client legitimately has no organisation until they complete
     * onboarding, and must never be labelled/treated as a platform operator
     * in the Admin Users list because of that.
     */
    public function test_client_with_no_organisation_is_not_platform_operator(): void
    {
        $this->actingAsSuperAdmin();
        $client = User::factory()->create(['organization_id' => null]);
        $client->assignRole(Role::firstOrCreate(['name' => 'Client', 'guard_name' => 'web']));

        $response = $this->getJson('/api/users')->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', $client->id);

        $this->assertFalse($row['is_platform_operator']);
        $this->assertNull($row['organization_name']);
        $this->assertNull($row['organization_subscription']);
    }

    public function test_client_with_organisation_is_not_platform_operator_and_shows_real_organisation(): void
    {
        $this->actingAsSuperAdmin();
        $org = $this->org('Bravo');
        $client = User::factory()->create(['organization_id' => $org->id]);
        $client->assignRole(Role::firstOrCreate(['name' => 'Client', 'guard_name' => 'web']));

        $response = $this->getJson('/api/users')->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', $client->id);

        $this->assertFalse($row['is_platform_operator']);
        $this->assertSame('Bravo', $row['organization_name']);
    }

    public function test_single_user_subscription_endpoint_returns_inherited_organisation_data(): void
    {
        $this->actingAsSuperAdmin();
        $org = $this->org('Acme');
        $this->activeSubscription($org, $this->plan('professional'));
        $user = User::factory()->create(['organization_id' => $org->id]);

        $response = $this->getJson("/api/users/{$user->id}/subscription")->assertOk();

        $this->assertFalse($response->json('data.is_platform_operator'));
        $this->assertSame('full', $response->json('data.subscription.access.mode'));
    }

    public function test_single_user_subscription_endpoint_reports_platform_operator_for_super_admin_with_no_organisation(): void
    {
        $this->actingAsSuperAdmin();
        $operator = User::factory()->create(['organization_id' => null]);
        $operator->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));

        $response = $this->getJson("/api/users/{$operator->id}/subscription")->assertOk();

        $this->assertTrue($response->json('data.is_platform_operator'));
    }

    public function test_single_user_subscription_endpoint_reports_platform_operator_for_admin_with_no_organisation(): void
    {
        $this->actingAsSuperAdmin();
        $operator = User::factory()->create(['organization_id' => null]);
        $operator->assignRole(Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']));

        $response = $this->getJson("/api/users/{$operator->id}/subscription")->assertOk();

        $this->assertTrue($response->json('data.is_platform_operator'));
    }

    /**
     * Invitation & First-Time Account Setup follow-up — a Client can now
     * legitimately have organization_id === null (invited, not yet
     * onboarded). is_platform_operator must be role-based, never inferred
     * from organisation nullity, or an invited Client would be wrongly
     * reported as a platform operator here too.
     */
    public function test_single_user_subscription_endpoint_does_not_report_platform_operator_for_client_with_no_organisation(): void
    {
        $this->actingAsSuperAdmin();
        $client = User::factory()->create(['organization_id' => null]);
        $client->assignRole(Role::firstOrCreate(['name' => 'Client', 'guard_name' => 'web']));

        $response = $this->getJson("/api/users/{$client->id}/subscription")->assertOk();

        $this->assertFalse($response->json('data.is_platform_operator'));
    }

    public function test_client_cannot_access_user_subscription_endpoint(): void
    {
        $org = $this->org('Acme');
        $client = User::factory()->create(['organization_id' => $org->id]);
        $client->assignRole(Role::firstOrCreate(['name' => 'Client', 'guard_name' => 'web']));
        Sanctum::actingAs($client);

        $this->getJson("/api/users/{$client->id}/subscription")->assertForbidden();
    }

    public function test_guest_is_unauthorized(): void
    {
        $user = User::factory()->create();

        $this->getJson("/api/users/{$user->id}/subscription")->assertUnauthorized();
    }
}
