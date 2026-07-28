<?php

namespace Tests\Feature\Billing;

use App\Models\BillingCustomer;
use App\Models\Organization;
use App\Models\PricingPlan;
use App\Models\PricingPlanProviderPrice;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Admin\OrganizationSubscriptionAdminService;
use App\Services\Billing\FakeBillingProvider;
use App\Services\Billing\StripeReconciliationService;
use App\Services\Billing\SubscriptionLifecycleService;
use App\Services\Billing\TransitionContext;
use App\Support\Billing\ReconciliationFinding;
use App\Support\Billing\SubscriptionSource;
use App\Support\Billing\SubscriptionStatus;
use App\Support\Billing\TransitionSource;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\TestCase;

/**
 * Phase G4B.1 — Subscription Source Foundation. Covers the migration's
 * add/backfill/enforce sequence, the sole production creation path setting
 * source explicitly, immutability, and the StripeReconciliationService
 * guard added this phase.
 */
class SubscriptionSourceTest extends TestCase
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

    private function subscriptionRow(int $organizationId, array $overrides = []): array
    {
        return array_merge([
            'organization_id' => $organizationId,
            'provider' => 'stripe',
            'livemode' => false,
            'internal_reference' => 'SUB-SRC-' . random_int(1, 99999999),
            'status' => SubscriptionStatus::ACTIVE,
            'billing_interval' => 'monthly',
            'currency' => 'GBP',
            'unit_amount' => 79900,
            'quantity' => 1,
            'subtotal_amount' => 79900,
            'tax_amount' => 0,
            'total_amount' => 79900,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides);
    }

    // ─── Migration: add / backfill / enforce ──────────────────────────────

    public function test_migration_backfills_existing_null_source_rows_to_stripe(): void
    {
        $org = $this->org('Acme');

        // Simulate the pre-migration state: drop the column RefreshDatabase's
        // full migration run already added, insert a "legacy" row with no
        // source at all (possible again now the column is gone), then
        // re-run this migration's own up() logic exactly as it exists on
        // disk — the real add/backfill/enforce sequence, not a re-derived
        // approximation of it.
        Schema::table('subscriptions', function ($table) {
            $table->dropColumn('source');
        });

        DB::table('subscriptions')->insert($this->subscriptionRow($org->id));
        $this->assertNull(DB::table('subscriptions')->first()->source ?? null);

        $migration = require database_path('migrations/2026_08_08_000001_add_source_to_subscriptions_table.php');
        $migration->up();

        $row = DB::table('subscriptions')->first();
        $this->assertSame(SubscriptionSource::STRIPE, $row->source);
    }

    public function test_migration_enforces_not_null_after_backfill(): void
    {
        // The full migration stack has already run (RefreshDatabase) —
        // source is NOT NULL with a 'stripe' default at this point. A raw
        // insert explicitly passing null must fail.
        $org = $this->org('Acme');

        $this->expectException(QueryException::class);

        DB::table('subscriptions')->insert($this->subscriptionRow($org->id, ['source' => null]));
    }

    public function test_new_row_omitting_source_defaults_to_stripe_via_db_default(): void
    {
        $org = $this->org('Acme');

        // A raw insert (bypassing Eloquent entirely, as an old/unaware
        // fixture might) that never mentions "source" at all — proves the
        // DB-level default is the real backward-compatibility net, not
        // something only Eloquent's model layer provides.
        DB::table('subscriptions')->insert($this->subscriptionRow($org->id));

        $row = DB::table('subscriptions')->first();
        $this->assertSame(SubscriptionSource::STRIPE, $row->source);
    }

    // ─── Production creation path ─────────────────────────────────────────

    public function test_checkout_created_subscription_receives_stripe_source_explicitly(): void
    {
        $lifecycle = $this->app->make(SubscriptionLifecycleService::class);
        $org = $this->org('Acme');
        $actor = User::factory()->create(['organization_id' => $org->id]);
        $plan = PricingPlan::create(['code' => 'pro-' . random_int(1, 999999), 'slug' => 'pro-' . random_int(1, 999999), 'name' => 'Professional', 'monthly_price' => 79.99, 'currency' => 'GBP']);
        $mapping = PricingPlanProviderPrice::create([
            'pricing_plan_id' => $plan->id, 'provider' => 'stripe', 'billing_interval' => 'monthly',
            'currency' => 'GBP', 'provider_price_id' => 'price_fake_' . random_int(1, 999999),
            'unit_amount' => 7999, 'is_active' => true, 'livemode' => false,
        ]);
        $context = TransitionContext::make(['source' => TransitionSource::SUPER_ADMIN, 'actor_user_id' => $actor->id]);

        $subscription = $lifecycle->createDraftSubscription($org, $plan, $mapping, 'monthly', $context);

        $this->assertSame(SubscriptionSource::STRIPE, $subscription->fresh()->source);
    }

    // ─── Source value validation ───────────────────────────────────────────

    public function test_only_approved_source_values_are_valid(): void
    {
        $this->assertTrue(SubscriptionSource::isValid(SubscriptionSource::STRIPE));
        $this->assertTrue(SubscriptionSource::isValid(SubscriptionSource::MANUAL));
        $this->assertTrue(SubscriptionSource::isValid(SubscriptionSource::COMPLIMENTARY));
        $this->assertFalse(SubscriptionSource::isValid('testing'));
        $this->assertFalse(SubscriptionSource::isValid('trial'));
        $this->assertFalse(SubscriptionSource::isValid('legacy'));
    }

    // ─── Immutability ──────────────────────────────────────────────────────

    public function test_source_can_be_set_freely_before_first_save(): void
    {
        $org = $this->org('Acme');

        $subscription = new Subscription($this->subscriptionRow($org->id, ['source' => SubscriptionSource::MANUAL]));
        $subscription->save();

        $this->assertSame(SubscriptionSource::MANUAL, $subscription->fresh()->source);
    }

    public function test_source_cannot_be_changed_after_persistence(): void
    {
        $org = $this->org('Acme');
        $subscription = Subscription::create($this->subscriptionRow($org->id, ['source' => SubscriptionSource::STRIPE]));

        $this->expectException(LogicException::class);

        $subscription->update(['source' => SubscriptionSource::MANUAL]);
    }

    public function test_unrelated_subscription_updates_remain_unaffected_by_the_immutability_guard(): void
    {
        $org = $this->org('Acme');
        $subscription = Subscription::create($this->subscriptionRow($org->id, ['source' => SubscriptionSource::STRIPE]));

        // Ordinary lifecycle-style update that never touches source.
        $subscription->update(['status' => SubscriptionStatus::PAST_DUE, 'grace_period_ends_at' => now()->addDays(7)]);

        $this->assertSame(SubscriptionStatus::PAST_DUE, $subscription->fresh()->status);
        $this->assertSame(SubscriptionSource::STRIPE, $subscription->fresh()->source);
    }

    // ─── Stripe reconciliation guard ───────────────────────────────────────

    public function test_stripe_reconciliation_ignores_non_stripe_source_rows(): void
    {
        $reconciliation = $this->app->make(StripeReconciliationService::class);
        $org = $this->org('Acme');

        // A manual-source row that would otherwise look "unhealthy" to
        // reconciliation (an active status with no real provider
        // subscription id at all) — must never be scanned.
        Subscription::create($this->subscriptionRow($org->id, [
            'source' => SubscriptionSource::MANUAL,
            'provider_subscription_id' => null,
        ]));

        $result = $reconciliation->reconcile();

        $this->assertSame(0, $result['counters']['scanned']);
    }

    public function test_stripe_reconciliation_still_scans_stripe_source_rows(): void
    {
        $reconciliation = $this->app->make(StripeReconciliationService::class);
        $lifecycle = $this->app->make(SubscriptionLifecycleService::class);
        $fake = $this->app->make(FakeBillingProvider::class);

        $org = $this->org('Acme');
        $actor = User::factory()->create(['organization_id' => $org->id]);
        $plan = PricingPlan::create(['code' => 'pro-' . random_int(1, 999999), 'slug' => 'pro-' . random_int(1, 999999), 'name' => 'Professional', 'monthly_price' => 79.99, 'currency' => 'GBP']);
        $mapping = PricingPlanProviderPrice::create([
            'pricing_plan_id' => $plan->id, 'provider' => 'stripe', 'billing_interval' => 'monthly',
            'currency' => 'GBP', 'provider_price_id' => 'price_fake_' . random_int(1, 999999),
            'unit_amount' => 7999, 'is_active' => true, 'livemode' => false,
        ]);
        $providerCustomerId = 'cus_fake_' . random_int(1, 9999999);
        $customer = BillingCustomer::create(['organization_id' => $org->id, 'provider' => 'stripe', 'provider_customer_id' => $providerCustomerId, 'livemode' => false]);
        $context = TransitionContext::make(['source' => TransitionSource::SUPER_ADMIN, 'actor_user_id' => $actor->id]);

        $subscription = $lifecycle->createDraftSubscription($org, $plan, $mapping, 'monthly', $context, null, $customer->id);
        $lifecycle->markPendingPayment($subscription, $context);
        $providerId = 'sub_fake_' . random_int(1, 999999);
        $lifecycle->activate($subscription, [
            'id' => $providerId, 'status' => 'active', 'customer_id' => $providerCustomerId,
            'cancel_at_period_end' => false, 'current_period_start' => now()->subDay()->timestamp,
            'current_period_end' => now()->addMonth()->timestamp, 'trial_end' => null, 'livemode' => false,
        ], $context);
        $fake->seedSubscription($providerId, [
            'status' => 'active', 'customer_id' => $providerCustomerId, 'cancel_at_period_end' => false,
            'price_id' => $mapping->provider_price_id, 'livemode' => false,
            'current_period_start' => now()->subDay()->timestamp, 'current_period_end' => now()->addMonth()->timestamp,
        ]);

        $result = $reconciliation->reconcile();

        $this->assertSame(1, $result['counters']['scanned']);
        $this->assertSame(1, $result['counters'][ReconciliationFinding::HEALTHY]);
    }

    // ─── G4A admin UI display ──────────────────────────────────────────────

    public function test_organisation_subscription_admin_payload_exposes_source(): void
    {
        $org = $this->org('Acme');
        Subscription::create($this->subscriptionRow($org->id, ['source' => SubscriptionSource::MANUAL]));

        $payload = $this->app->make(OrganizationSubscriptionAdminService::class)->forOrganization($org->fresh());

        $this->assertSame(SubscriptionSource::MANUAL, $payload['subscription_source']);
    }

    public function test_organisation_subscription_admin_payload_reports_null_source_for_no_subscription(): void
    {
        $org = $this->org('Acme');

        $payload = $this->app->make(OrganizationSubscriptionAdminService::class)->forOrganization($org);

        $this->assertNull($payload['subscription_source']);
    }

    // ─── No side effects on unrelated architecture ─────────────────────────

    public function test_no_entitlement_snapshot_is_created_by_introducing_the_source_column(): void
    {
        $org = $this->org('Acme');
        Subscription::create($this->subscriptionRow($org->id, ['source' => SubscriptionSource::STRIPE]));

        $this->assertSame(0, \App\Models\SubscriptionEntitlementSnapshot::query()->count());
    }

    public function test_no_organisation_access_change_from_introducing_the_source_column(): void
    {
        $org = $this->org('Acme');
        $subscription = Subscription::create($this->subscriptionRow($org->id, ['source' => SubscriptionSource::STRIPE]));

        $decision = $this->app->make(\App\Services\Entitlements\SubscriptionAccessPolicy::class)->resolve($subscription);

        // Unchanged — resolves purely from status, exactly as before this
        // phase; source plays no part in access-mode resolution.
        $this->assertSame('full', $decision->mode);
    }
}
