<?php

namespace Tests\Feature\Admin;

use App\Models\ActivityLog;
use App\Models\Organization;
use App\Models\PricingPlan;
use App\Models\PricingPlanEntitlement;
use App\Models\Subscription;
use App\Models\SubscriptionEntitlementSnapshot;
use App\Models\User;
use App\Services\Entitlements\FeatureGate;
use App\Support\Billing\BillingProviders;
use App\Support\Billing\SubscriptionSource;
use App\Support\Billing\SubscriptionStatus;
use App\Support\Entitlements\Feature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase G4B.2 — Manual & Complimentary Subscription Assignment. Covers
 * assignment, termination, the terminate-then-reassign correction
 * workflow, provider invariants, conflict protection, snapshot/audit
 * creation, authorization, and Stripe isolation.
 */
class OrganizationSubscriptionAssignmentApiTest extends TestCase
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

    private function plan(string $code, string $status = 'active'): PricingPlan
    {
        return PricingPlan::create(['code' => $code, 'slug' => $code, 'name' => ucfirst($code), 'status' => $status, 'currency' => 'GBP']);
    }

    private function actingAsRole(Organization $org, string $role): User
    {
        $user = User::factory()->create(['organization_id' => $role === 'Client' ? $org->id : null]);
        $user->assignRole(Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']));
        Sanctum::actingAs($user);

        return $user;
    }

    private function assignPayload(array $overrides = []): array
    {
        return array_merge([
            'plan_code' => 'professional',
            'billing_interval' => 'monthly',
            'reason' => 'Approved pilot access for the construction administration trial.',
            'confirmed' => true,
        ], $overrides);
    }

    // ─── Authorization ──────────────────────────────────────────────────

    public function test_super_admin_can_assign_manual_subscription(): void
    {
        $org = $this->org('Acme');
        $this->plan('professional');
        $this->actingAsRole($org, 'Super Admin');

        $response = $this->postJson("/api/organizations/{$org->id}/subscriptions/assign-manual", $this->assignPayload());

        $response->assertCreated();
        $this->assertSame('manual', $response->json('data.subscription_source'));
    }

    public function test_admin_cannot_assign_subscription(): void
    {
        $org = $this->org('Acme');
        $this->plan('professional');
        $this->actingAsRole($org, 'Admin');

        $this->postJson("/api/organizations/{$org->id}/subscriptions/assign-manual", $this->assignPayload())
            ->assertForbidden();
    }

    public function test_client_cannot_assign_subscription(): void
    {
        $org = $this->org('Acme');
        $this->plan('professional');
        $this->actingAsRole($org, 'Client');

        $this->postJson("/api/organizations/{$org->id}/subscriptions/assign-manual", $this->assignPayload())
            ->assertForbidden();
    }

    public function test_guest_cannot_assign_subscription(): void
    {
        $org = $this->org('Acme');
        $this->plan('professional');

        $this->postJson("/api/organizations/{$org->id}/subscriptions/assign-manual", $this->assignPayload())
            ->assertUnauthorized();
    }

    // ─── Validation ─────────────────────────────────────────────────────

    public function test_unsupported_plan_code_is_rejected(): void
    {
        $org = $this->org('Acme');
        $this->actingAsRole($org, 'Super Admin');

        $this->postJson("/api/organizations/{$org->id}/subscriptions/assign-manual", $this->assignPayload(['plan_code' => 'bogus']))
            ->assertStatus(422)
            ->assertJsonPath('code', 'plan_not_assignable');
    }

    public function test_inactive_plan_is_rejected_even_if_it_exists(): void
    {
        $org = $this->org('Acme');
        $this->plan('starter', 'archived');
        $this->actingAsRole($org, 'Super Admin');

        $this->postJson("/api/organizations/{$org->id}/subscriptions/assign-manual", $this->assignPayload(['plan_code' => 'starter']))
            ->assertStatus(422)
            ->assertJsonPath('code', 'plan_not_assignable');
    }

    public function test_marketing_hidden_but_commercially_active_plan_is_still_assignable(): void
    {
        $org = $this->org('Acme');
        $plan = $this->plan('professional');
        $plan->update(['is_visible' => false, 'published_at' => null]);
        $this->actingAsRole($org, 'Super Admin');

        $this->postJson("/api/organizations/{$org->id}/subscriptions/assign-manual", $this->assignPayload())
            ->assertCreated();
    }

    public function test_invalid_billing_interval_is_rejected(): void
    {
        $org = $this->org('Acme');
        $this->plan('professional');
        $this->actingAsRole($org, 'Super Admin');

        $this->postJson("/api/organizations/{$org->id}/subscriptions/assign-manual", $this->assignPayload(['billing_interval' => 'weekly']))
            ->assertStatus(422);
    }

    public function test_missing_reason_is_rejected(): void
    {
        $org = $this->org('Acme');
        $this->plan('professional');
        $this->actingAsRole($org, 'Super Admin');

        $this->postJson("/api/organizations/{$org->id}/subscriptions/assign-manual", $this->assignPayload(['reason' => '']))
            ->assertStatus(422);
    }

    public function test_too_short_reason_is_rejected(): void
    {
        $org = $this->org('Acme');
        $this->plan('professional');
        $this->actingAsRole($org, 'Super Admin');

        $this->postJson("/api/organizations/{$org->id}/subscriptions/assign-manual", $this->assignPayload(['reason' => 'test']))
            ->assertStatus(422);
    }

    public function test_unconfirmed_request_is_rejected(): void
    {
        $org = $this->org('Acme');
        $this->plan('professional');
        $this->actingAsRole($org, 'Super Admin');

        $this->postJson("/api/organizations/{$org->id}/subscriptions/assign-manual", $this->assignPayload(['confirmed' => false]))
            ->assertStatus(422);
    }

    public function test_end_date_before_start_date_is_rejected(): void
    {
        $org = $this->org('Acme');
        $this->plan('professional');
        $this->actingAsRole($org, 'Super Admin');

        $this->postJson("/api/organizations/{$org->id}/subscriptions/assign-manual", $this->assignPayload([
            'starts_at' => '2026-06-01T00:00:00Z',
            'ends_at' => '2026-05-01T00:00:00Z',
        ]))->assertStatus(422);
    }

    // ─── Mutation correctness & provider invariants ────────────────────

    public function test_successful_manual_assignment_creates_exactly_one_subscription_with_null_provider_identifiers(): void
    {
        $org = $this->org('Acme');
        $this->plan('professional');
        $this->actingAsRole($org, 'Super Admin');

        $this->postJson("/api/organizations/{$org->id}/subscriptions/assign-manual", $this->assignPayload())->assertCreated();

        $this->assertSame(1, Subscription::where('organization_id', $org->id)->count());
        $subscription = Subscription::where('organization_id', $org->id)->sole();
        $this->assertSame(SubscriptionSource::MANUAL, $subscription->source);
        $this->assertSame(SubscriptionStatus::ACTIVE, $subscription->status);
        $this->assertSame(BillingProviders::NONE, $subscription->provider);
        $this->assertNull($subscription->provider_subscription_id);
        $this->assertNull($subscription->provider_price_id);
        $this->assertSame('professional', $subscription->plan_code_snapshot);
        $this->assertSame('monthly', $subscription->billing_interval);
    }

    public function test_successful_complimentary_assignment(): void
    {
        $org = $this->org('Acme');
        $this->plan('professional');
        $this->actingAsRole($org, 'Super Admin');

        $response = $this->postJson("/api/organizations/{$org->id}/subscriptions/assign-complimentary", $this->assignPayload());

        $response->assertCreated();
        $subscription = Subscription::where('organization_id', $org->id)->sole();
        $this->assertSame(SubscriptionSource::COMPLIMENTARY, $subscription->source);
        $this->assertNull($subscription->provider_subscription_id);
    }

    public function test_assignment_never_creates_a_checkout_session(): void
    {
        $org = $this->org('Acme');
        $this->plan('professional');
        $this->actingAsRole($org, 'Super Admin');

        $this->postJson("/api/organizations/{$org->id}/subscriptions/assign-manual", $this->assignPayload())->assertCreated();

        $this->assertSame(0, \App\Models\BillingCheckoutSession::count());
    }

    public function test_source_is_immutable_after_assignment(): void
    {
        $org = $this->org('Acme');
        $this->plan('professional');
        $this->actingAsRole($org, 'Super Admin');
        $this->postJson("/api/organizations/{$org->id}/subscriptions/assign-manual", $this->assignPayload())->assertCreated();
        $subscription = Subscription::where('organization_id', $org->id)->sole();

        $this->expectException(\LogicException::class);
        $subscription->update(['source' => SubscriptionSource::COMPLIMENTARY]);
    }

    // ─── Access / entitlement resolution ───────────────────────────────

    public function test_assignment_produces_full_access_and_correct_feature_gate_resolution(): void
    {
        $org = $this->org('Acme');
        $plan = $this->plan('professional');
        PricingPlanEntitlement::create(['pricing_plan_id' => $plan->id, 'feature_key' => Feature::CUSTOM_BRANDING, 'is_applicable' => true, 'is_unlimited' => false, 'value' => json_encode(true)]);
        $this->actingAsRole($org, 'Super Admin');

        $response = $this->postJson("/api/organizations/{$org->id}/subscriptions/assign-manual", $this->assignPayload());
        $response->assertCreated();
        $this->assertSame('full', $response->json('data.subscription.access.mode'));

        $this->assertTrue(app(FeatureGate::class)->allows($org->fresh(), Feature::CUSTOM_BRANDING));
    }

    public function test_exactly_one_activation_snapshot_is_created(): void
    {
        $org = $this->org('Acme');
        $this->plan('professional');
        $this->actingAsRole($org, 'Super Admin');

        $this->postJson("/api/organizations/{$org->id}/subscriptions/assign-manual", $this->assignPayload())->assertCreated();

        $subscription = Subscription::where('organization_id', $org->id)->sole();
        $this->assertSame(1, SubscriptionEntitlementSnapshot::where('subscription_id', $subscription->id)->count());
        $snapshot = SubscriptionEntitlementSnapshot::where('subscription_id', $subscription->id)->sole();
        $this->assertSame('activation', $snapshot->lifecycle_reason);
        $this->assertSame($org->id, $snapshot->organization_id);
    }

    // ─── Audit ──────────────────────────────────────────────────────────

    public function test_assignment_writes_activity_log_with_expected_metadata(): void
    {
        $org = $this->org('Acme');
        $this->plan('professional');
        $actor = $this->actingAsRole($org, 'Super Admin');

        $this->postJson("/api/organizations/{$org->id}/subscriptions/assign-manual", $this->assignPayload())->assertCreated();

        $entry = ActivityLog::where('organization_id', $org->id)->where('action', 'subscription.manual_assigned')->sole();
        $this->assertSame($actor->id, $entry->user_id);
        $this->assertSame('manual', $entry->metadata['subscription_source']);
        $plan = PricingPlan::find($entry->metadata['pricing_plan_id']);
        $this->assertSame('professional', $plan->code);
        $this->assertArrayHasKey('reason', $entry->metadata);

        // Assignment surfaces in G4A's recent_activity feed.
        $payload = $this->getJson("/api/organizations/{$org->id}/subscription")->assertOk()->json('data');
        $this->assertNotEmpty(collect($payload['recent_activity'])->where('action', 'subscription.manual_assigned'));
    }

    // ─── Conflict protection ────────────────────────────────────────────

    public function test_assignment_refused_when_organisation_already_has_an_active_stripe_subscription(): void
    {
        $org = $this->org('Acme');
        $plan = $this->plan('professional');
        Subscription::create([
            'organization_id' => $org->id, 'pricing_plan_id' => $plan->id, 'provider' => 'stripe', 'livemode' => false,
            'internal_reference' => 'SUB-EXIST-1', 'status' => SubscriptionStatus::ACTIVE, 'billing_interval' => 'monthly',
            'currency' => 'GBP', 'source' => SubscriptionSource::STRIPE,
        ]);
        $this->actingAsRole($org, 'Super Admin');

        $this->postJson("/api/organizations/{$org->id}/subscriptions/assign-manual", $this->assignPayload())
            ->assertStatus(409)
            ->assertJsonPath('code', 'subscription_conflict');

        $this->assertSame(1, Subscription::where('organization_id', $org->id)->count());
    }

    public function test_assignment_refused_for_existing_active_manual_subscription(): void
    {
        $org = $this->org('Acme');
        $plan = $this->plan('professional');
        Subscription::create([
            'organization_id' => $org->id, 'pricing_plan_id' => $plan->id, 'provider' => BillingProviders::NONE, 'livemode' => false,
            'internal_reference' => 'SUB-EXIST-2', 'status' => SubscriptionStatus::ACTIVE, 'billing_interval' => 'monthly',
            'currency' => 'GBP', 'source' => SubscriptionSource::MANUAL,
        ]);
        $this->actingAsRole($org, 'Super Admin');

        $this->postJson("/api/organizations/{$org->id}/subscriptions/assign-complimentary", $this->assignPayload())
            ->assertStatus(409);
    }

    public function test_assignment_succeeds_when_only_a_fully_cancelled_historical_subscription_exists(): void
    {
        $org = $this->org('Acme');
        $plan = $this->plan('professional');
        Subscription::create([
            'organization_id' => $org->id, 'pricing_plan_id' => $plan->id, 'provider' => 'stripe', 'livemode' => false,
            'internal_reference' => 'SUB-HIST-1', 'status' => SubscriptionStatus::CANCELLED, 'billing_interval' => 'monthly',
            'currency' => 'GBP', 'source' => SubscriptionSource::STRIPE,
            'cancelled_at' => now()->subMonth(), 'ended_at' => now()->subMonth(),
        ]);
        $this->actingAsRole($org, 'Super Admin');

        $this->postJson("/api/organizations/{$org->id}/subscriptions/assign-manual", $this->assignPayload())
            ->assertCreated();
    }

    public function test_assignment_does_not_mutate_a_different_organisation(): void
    {
        $orgA = $this->org('Acme');
        $orgB = $this->org('Globex');
        $this->plan('professional');
        $this->actingAsRole($orgA, 'Super Admin');

        $this->postJson("/api/organizations/{$orgA->id}/subscriptions/assign-manual", $this->assignPayload())->assertCreated();

        $this->assertSame(0, Subscription::where('organization_id', $orgB->id)->count());
    }

    // ─── Termination ────────────────────────────────────────────────────

    public function test_super_admin_can_terminate_manual_subscription(): void
    {
        $org = $this->org('Acme');
        $plan = $this->plan('professional');
        $this->actingAsRole($org, 'Super Admin');
        $this->postJson("/api/organizations/{$org->id}/subscriptions/assign-manual", $this->assignPayload())->assertCreated();
        $subscription = Subscription::where('organization_id', $org->id)->sole();

        $response = $this->postJson("/api/organizations/{$org->id}/subscriptions/{$subscription->id}/terminate", [
            'reason' => 'Customer requested cancellation of the pilot arrangement.',
            'confirmed' => true,
        ]);

        $response->assertOk();
        $subscription->refresh();
        $this->assertSame(SubscriptionStatus::CANCELLED, $subscription->status);
        $this->assertSame(SubscriptionSource::MANUAL, $subscription->source);
        $this->assertNotNull($subscription->cancelled_at);
    }

    public function test_terminate_rejects_stripe_subscription(): void
    {
        $org = $this->org('Acme');
        $plan = $this->plan('professional');
        $subscription = Subscription::create([
            'organization_id' => $org->id, 'pricing_plan_id' => $plan->id, 'provider' => 'stripe', 'livemode' => false,
            'internal_reference' => 'SUB-STRIPE-1', 'status' => SubscriptionStatus::ACTIVE, 'billing_interval' => 'monthly',
            'currency' => 'GBP', 'source' => SubscriptionSource::STRIPE,
        ]);
        $this->actingAsRole($org, 'Super Admin');

        $this->postJson("/api/organizations/{$org->id}/subscriptions/{$subscription->id}/terminate", [
            'reason' => 'Attempting to terminate a Stripe subscription incorrectly.',
            'confirmed' => true,
        ])->assertStatus(409)->assertJsonPath('code', 'stripe_termination_not_permitted');

        $this->assertSame(SubscriptionStatus::ACTIVE, $subscription->fresh()->status);
    }

    public function test_admin_cannot_terminate_subscription(): void
    {
        $org = $this->org('Acme');
        $plan = $this->plan('professional');
        $subscription = Subscription::create([
            'organization_id' => $org->id, 'pricing_plan_id' => $plan->id, 'provider' => BillingProviders::NONE, 'livemode' => false,
            'internal_reference' => 'SUB-MAN-1', 'status' => SubscriptionStatus::ACTIVE, 'billing_interval' => 'monthly',
            'currency' => 'GBP', 'source' => SubscriptionSource::MANUAL,
        ]);
        $this->actingAsRole($org, 'Admin');

        $this->postJson("/api/organizations/{$org->id}/subscriptions/{$subscription->id}/terminate", [
            'reason' => 'Should not be permitted for Admin role.',
            'confirmed' => true,
        ])->assertForbidden();
    }

    public function test_termination_preserves_snapshot_and_activity_history(): void
    {
        $org = $this->org('Acme');
        $this->plan('professional');
        $this->actingAsRole($org, 'Super Admin');
        $this->postJson("/api/organizations/{$org->id}/subscriptions/assign-manual", $this->assignPayload())->assertCreated();
        $subscription = Subscription::where('organization_id', $org->id)->sole();
        $snapshotCountBefore = SubscriptionEntitlementSnapshot::count();
        $activityCountBefore = ActivityLog::count();

        $this->postJson("/api/organizations/{$org->id}/subscriptions/{$subscription->id}/terminate", [
            'reason' => 'Ending pilot access as agreed with the customer.',
            'confirmed' => true,
        ])->assertOk();

        $this->assertSame($snapshotCountBefore, SubscriptionEntitlementSnapshot::count());
        $this->assertGreaterThan($activityCountBefore, ActivityLog::count());
        $this->assertDatabaseHas('subscriptions', ['id' => $subscription->id]); // never deleted
    }

    // ─── Correction workflow (terminate then reassign) ─────────────────

    public function test_correction_workflow_terminate_then_reassign(): void
    {
        $org = $this->org('Acme');
        $this->plan('professional');
        $this->plan('enterprise');
        $this->actingAsRole($org, 'Super Admin');

        $this->postJson("/api/organizations/{$org->id}/subscriptions/assign-manual", $this->assignPayload(['plan_code' => 'professional']))
            ->assertCreated();
        $original = Subscription::where('organization_id', $org->id)->sole();

        $this->postJson("/api/organizations/{$org->id}/subscriptions/{$original->id}/terminate", [
            'reason' => 'Incorrect plan assigned — correcting to Enterprise.',
            'confirmed' => true,
        ])->assertOk();

        $this->postJson("/api/organizations/{$org->id}/subscriptions/assign-complimentary", $this->assignPayload(['plan_code' => 'enterprise']))
            ->assertCreated();

        $this->assertSame(2, Subscription::where('organization_id', $org->id)->count());
        $replacement = Subscription::where('organization_id', $org->id)->where('id', '!=', $original->id)->sole();
        $this->assertSame('enterprise', $replacement->plan_code_snapshot);
        $this->assertSame(SubscriptionSource::COMPLIMENTARY, $replacement->source);
        $this->assertSame(SubscriptionStatus::CANCELLED, $original->fresh()->status);
        $this->assertSame(SubscriptionStatus::ACTIVE, $replacement->status);
    }

    // ─── Stripe isolation ───────────────────────────────────────────────

    public function test_stripe_reconciliation_excludes_manual_and_complimentary_subscriptions(): void
    {
        $org = $this->org('Acme');
        $this->plan('professional');
        $this->actingAsRole($org, 'Super Admin');
        $this->postJson("/api/organizations/{$org->id}/subscriptions/assign-manual", $this->assignPayload())->assertCreated();

        $result = app(\App\Services\Billing\StripeReconciliationService::class)->reconcile();

        $this->assertSame(0, $result['counters']['scanned']);
    }
}
