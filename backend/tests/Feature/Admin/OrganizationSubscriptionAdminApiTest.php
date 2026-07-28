<?php

namespace Tests\Feature\Admin;

use App\Models\ActivityLog;
use App\Models\Organization;
use App\Models\PricingPlan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Entitlements\EntitlementSnapshotService;
use App\Support\Billing\SubscriptionStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase G4A — read-only Organisation Subscription Administration
 * (`GET /admin/organizations/{id}/subscription`... actually mounted at
 * `GET /organizations/{id}/subscription`, see routes/api.php). Covers every
 * state this phase's approved scope calls out: no subscription, active,
 * trialing, cancelled/expired, legacy/missing snapshot, and authorization
 * (Super Admin/Admin allowed, Client denied, guest denied).
 */
class OrganizationSubscriptionAdminApiTest extends TestCase
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

    private function actingAsRole(Organization $org, string $role): User
    {
        $user = User::factory()->create(['organization_id' => $role === 'Client' ? $org->id : null]);
        $user->assignRole(Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']));
        Sanctum::actingAs($user);

        return $user;
    }

    private function activeSubscription(Organization $org, PricingPlan $plan, string $status = SubscriptionStatus::ACTIVE): Subscription
    {
        return Subscription::create([
            'organization_id' => $org->id,
            'pricing_plan_id' => $plan->id,
            'provider' => 'stripe',
            'livemode' => false,
            'internal_reference' => 'SUB-ADMIN-' . $org->id . '-' . random_int(1, 999999),
            'status' => $status,
            'billing_interval' => 'monthly',
            'currency' => 'GBP',
            'unit_amount' => 79900,
            'quantity' => 1,
            'subtotal_amount' => 79900,
            'tax_amount' => 0,
            'total_amount' => 79900,
            'starts_at' => now()->subDays(30),
            'activated_at' => now()->subDays(30),
            'plan_code_snapshot' => $plan->code,
            'plan_name_snapshot' => $plan->name,
        ]);
    }

    public function test_super_admin_can_view_organisation_with_no_subscription(): void
    {
        $org = $this->org('Acme');
        $this->actingAsRole($org, 'Super Admin');

        $response = $this->getJson("/api/organizations/{$org->id}/subscription")->assertOk();

        $this->assertNull($response->json('data.subscription'));
        $this->assertNull($response->json('data.snapshot'));
        $this->assertSame('unknown', $response->json('data.health.overall'));
        $this->assertSame($org->id, $response->json('data.organization_detail.id'));
    }

    public function test_active_subscription_with_snapshot_is_reported_as_present(): void
    {
        $org = $this->org('Acme');
        $plan = $this->plan('professional');
        $subscription = $this->activeSubscription($org, $plan);
        app(EntitlementSnapshotService::class)->snapshotForActivation($subscription, CarbonImmutable::now());
        $this->actingAsRole($org, 'Super Admin');

        $response = $this->getJson("/api/organizations/{$org->id}/subscription")->assertOk();

        $this->assertSame('full', $response->json('data.subscription.access.mode'));
        $this->assertTrue($response->json('data.snapshot.exists'));
        $this->assertSame('expected_snapshot_present', $response->json('data.snapshot.integrity_classification'));
        $this->assertFalse($response->json('data.snapshot.requires_attention'));
    }

    public function test_trialing_subscription_reports_trial_access_mode_and_card(): void
    {
        $org = $this->org('Acme');
        $plan = $this->plan('professional');
        Subscription::create([
            'organization_id' => $org->id, 'pricing_plan_id' => $plan->id, 'provider' => 'stripe', 'livemode' => false,
            'internal_reference' => 'SUB-TRIAL-ADMIN', 'status' => SubscriptionStatus::TRIALING, 'billing_interval' => 'monthly',
            'currency' => 'GBP', 'unit_amount' => 79900, 'quantity' => 1, 'subtotal_amount' => 79900, 'tax_amount' => 0,
            'total_amount' => 79900, 'starts_at' => now()->subDays(4), 'trial_ends_at' => now()->addDays(3),
        ]);
        $this->actingAsRole($org, 'Super Admin');

        $response = $this->getJson("/api/organizations/{$org->id}/subscription")->assertOk();

        $this->assertSame('trial', $response->json('data.subscription.access.mode'));
        $this->assertNotNull($response->json('data.trial'));
        $this->assertSame(3, $response->json('data.trial.days_remaining'));
    }

    public function test_cancelled_subscription_reports_restricted_access_mode(): void
    {
        $org = $this->org('Acme');
        $plan = $this->plan('professional');
        $this->activeSubscription($org, $plan, SubscriptionStatus::CANCELLED);
        $this->actingAsRole($org, 'Super Admin');

        $response = $this->getJson("/api/organizations/{$org->id}/subscription")->assertOk();

        $this->assertSame('restricted', $response->json('data.subscription.access.mode'));
    }

    public function test_legacy_subscription_with_no_snapshot_is_flagged_as_legacy_fallback_not_an_error(): void
    {
        $org = $this->org('Acme');
        $plan = $this->plan('professional');
        $subscription = $this->activeSubscription($org, $plan);
        // No snapshot created — starts_at predates config('billing.entitlement_snapshot_introduced_at')
        // by default test config, so this should classify as legacy, not "requires attention".
        $subscription->forceFill(['starts_at' => CarbonImmutable::parse(config('billing.entitlement_snapshot_introduced_at'))->subYear()])->save();
        $this->actingAsRole($org, 'Super Admin');

        $response = $this->getJson("/api/organizations/{$org->id}/subscription")->assertOk();

        $this->assertFalse($response->json('data.snapshot.exists'));
        $this->assertTrue($response->json('data.snapshot.is_legacy_fallback'));
        $this->assertFalse($response->json('data.snapshot.requires_attention'));
    }

    public function test_recent_activity_reuses_existing_activity_log_rows(): void
    {
        $org = $this->org('Acme');
        $plan = $this->plan('professional');
        $subscription = $this->activeSubscription($org, $plan);
        ActivityLog::record(
            action: 'subscription.activated',
            description: 'Activated subscription',
            subject: $subscription,
            organizationId: $org->id,
        );
        $this->actingAsRole($org, 'Super Admin');

        $response = $this->getJson("/api/organizations/{$org->id}/subscription")->assertOk();

        $this->assertNotEmpty($response->json('data.recent_activity'));
        $this->assertSame('subscription.activated', $response->json('data.recent_activity.0.action'));
    }

    public function test_admin_role_can_also_view(): void
    {
        $org = $this->org('Acme');
        $this->actingAsRole($org, 'Admin');

        $this->getJson("/api/organizations/{$org->id}/subscription")->assertOk();
    }

    public function test_client_role_is_denied(): void
    {
        $org = $this->org('Acme');
        $this->actingAsRole($org, 'Client');

        $this->getJson("/api/organizations/{$org->id}/subscription")->assertForbidden();
    }

    public function test_guest_is_unauthorized(): void
    {
        $org = $this->org('Acme');

        $this->getJson("/api/organizations/{$org->id}/subscription")->assertUnauthorized();
    }

    public function test_organisation_isolation_returns_the_requested_organisations_own_data_only(): void
    {
        $orgA = $this->org('Acme');
        $orgB = $this->org('Globex');
        $plan = $this->plan('professional');
        $this->activeSubscription($orgA, $plan);
        $this->activeSubscription($orgB, $plan);
        $this->actingAsRole($orgA, 'Super Admin');

        $response = $this->getJson("/api/organizations/{$orgB->id}/subscription")->assertOk();

        $this->assertSame($orgB->id, $response->json('data.organization_detail.id'));
        $this->assertSame($orgB->name, $response->json('data.organization.name'));
    }
}
