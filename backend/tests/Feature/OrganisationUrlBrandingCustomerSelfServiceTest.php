<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Appointment;
use App\Models\AppointmentType;
use App\Models\Organization;
use App\Models\OrganizationUrlSlugHistory;
use App\Models\PricingPlan;
use App\Models\PricingPlanEntitlement;
use App\Models\Subscription;
use App\Models\SubscriptionEntitlementSnapshot;
use App\Models\User;
use App\Services\AppointmentPublicLinkService;
use App\Support\Billing\SubscriptionStatus;
use App\Support\Entitlements\Feature;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Organisation URL Branding — customer self-service (Company Branding →
 * Custom URL). See internal-docs/super-admin/organisation-url-branding.md's
 * customer self-service section.
 */
class OrganisationUrlBrandingCustomerSelfServiceTest extends TestCase
{
    use RefreshDatabase;

    private static int $orgCounter = 200;
    private static int $planCounter = 0;

    private function makeOrg(array $overrides = []): Organization
    {
        $n = ++self::$orgCounter;
        return Organization::create(array_merge([
            'name' => "Org {$n}", 'slug' => "org-{$n}", 'timezone' => 'Europe/London', 'is_active' => true,
        ], $overrides));
    }

    private function makeUser(string $role, ?Organization $org = null): User
    {
        $org ??= $this->makeOrg();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole(Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']));
        return $user;
    }

    private function makePlan(string $code): PricingPlan
    {
        $n = ++self::$planCounter;
        return PricingPlan::create([
            'code' => $code, 'slug' => $code . '-' . $n, 'name' => ucfirst($code), 'order' => $n,
            'currency' => 'GBP', 'status' => 'active',
        ]);
    }

    private function setPlanEntitlement(PricingPlan $plan, string $featureKey, bool $value): void
    {
        PricingPlanEntitlement::create([
            'pricing_plan_id' => $plan->id,
            'feature_key' => $featureKey,
            'is_applicable' => true,
            'is_unlimited' => false,
            'value' => $value,
        ]);
    }

    /**
     * Creates an ACTIVE subscription for $organization on $planCode, with a
     * real activation snapshot whose entitlements_json includes whatever
     * `pricing_plan_entitlements` rows exist for that plan at this moment —
     * exactly mirroring what SubscriptionLifecycleService::activate()
     * really produces (never hand-crafted around the real resolution
     * path), so these tests exercise FeatureGate's real snapshot-first
     * behaviour.
     */
    private function makeActiveSubscriptionWithSnapshot(Organization $organization, string $planCode, array $statusOverrides = []): Subscription
    {
        $subscription = Subscription::create(array_merge([
            'organization_id' => $organization->id,
            'provider' => 'stripe',
            'livemode' => false,
            'internal_reference' => 'SUB-TEST-' . random_int(1, 99999999),
            'status' => SubscriptionStatus::ACTIVE,
            'billing_interval' => 'monthly',
            'currency' => 'GBP',
            'unit_amount' => 2999,
            'plan_code_snapshot' => $planCode,
            'starts_at' => now()->subDay(),
        ], $statusOverrides));

        $entitlementsJson = [];
        foreach (PricingPlanEntitlement::whereHas('pricingPlan', fn ($q) => $q->where('code', $planCode))->get() as $row) {
            $entitlementsJson[$row->feature_key] = [
                'value_type' => 'boolean', 'value' => (bool) $row->value, 'is_unlimited' => false, 'unit' => null, 'source' => 'plan_default',
            ];
        }

        SubscriptionEntitlementSnapshot::create([
            'subscription_id' => $subscription->id,
            'organization_id' => $organization->id,
            'pricing_plan_id' => null,
            'plan_code_snapshot' => $planCode,
            'entitlements_json' => $entitlementsJson,
            'effective_from' => CarbonImmutable::now()->subHour(),
            'lifecycle_reason' => 'activation',
            'source_transition' => 'subscription.activated',
        ]);

        return $subscription;
    }

    private function makeAppointment(?Organization $org): Appointment
    {
        $type = AppointmentType::create([
            'name' => 'Type', 'slug' => 'type-' . uniqid(),
            'duration_minutes' => 30, 'is_active' => true, 'is_public' => true, 'assignment_mode' => 'manual',
            'meeting_method' => 'tbc', 'requires_confirmation' => false,
            'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0,
            'min_notice_hours' => 0, 'max_advance_days' => 60,
        ]);

        return Appointment::create([
            'reference' => 'APT-' . str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
            'organization_id' => $org?->id,
            'appointment_type_id' => $type->id,
            'attendee_name' => 'Jane Doe', 'attendee_email' => 'jane@example.com', 'attendee_timezone' => 'Europe/London',
            'starts_at' => now()->addDays(3)->setTime(10, 0), 'ends_at' => now()->addDays(3)->setTime(10, 30),
            'booking_timezone' => 'Europe/London', 'status' => 'confirmed',
            'booking_source' => 'public_booking_page', 'meeting_method' => 'tbc',
        ]);
    }

    // ── Entitlement ──────────────────────────────────────────────────────────

    public function test_entitled_organization_can_set_its_own_url_slug(): void
    {
        $org = $this->makeOrg();
        $plan = $this->makePlan('professional');
        $this->setPlanEntitlement($plan, Feature::CUSTOM_BRANDED_SUBDOMAIN, true);
        $this->makeActiveSubscriptionWithSnapshot($org, 'professional');
        $client = $this->makeUser('Client', $org);
        Sanctum::actingAs($client);

        $response = $this->putJson('/api/organization/url-slug', ['url_slug' => 'star-affinity']);

        $response->assertStatus(200);
        $this->assertSame('star-affinity', $org->fresh()->url_slug);
        $this->assertDatabaseHas('activity_logs', ['action' => 'organization.url_branding_created']);
    }

    public function test_non_entitled_organization_is_denied_by_the_backend(): void
    {
        $org = $this->makeOrg();
        $plan = $this->makePlan('essential');
        $this->setPlanEntitlement($plan, Feature::CUSTOM_BRANDED_SUBDOMAIN, false);
        $this->makeActiveSubscriptionWithSnapshot($org, 'essential');
        $client = $this->makeUser('Client', $org);
        Sanctum::actingAs($client);

        $response = $this->putJson('/api/organization/url-slug', ['url_slug' => 'star-affinity']);

        $response->assertStatus(403);
        $this->assertNull($org->fresh()->url_slug);
    }

    public function test_get_reports_entitlement_flag_accurately(): void
    {
        $org = $this->makeOrg();
        $plan = $this->makePlan('professional');
        $this->setPlanEntitlement($plan, Feature::CUSTOM_BRANDED_SUBDOMAIN, true);
        $this->makeActiveSubscriptionWithSnapshot($org, 'professional');
        $client = $this->makeUser('Client', $org);
        Sanctum::actingAs($client);

        $response = $this->getJson('/api/organization/url-slug');

        $response->assertStatus(200)->assertJson(['data' => ['url_slug' => null, 'entitled' => true, 'preview_url' => null]]);
    }

    public function test_organization_with_no_subscription_is_not_entitled(): void
    {
        $org = $this->makeOrg();
        $client = $this->makeUser('Client', $org);
        Sanctum::actingAs($client);

        $this->putJson('/api/organization/url-slug', ['url_slug' => 'star-affinity'])->assertStatus(403);
    }

    // ── Authorisation ────────────────────────────────────────────────────────

    public function test_super_admin_has_no_own_organization_to_manage(): void
    {
        $superAdmin = $this->makeUser('Super Admin');
        Sanctum::actingAs($superAdmin);

        $this->getJson('/api/organization/url-slug')->assertStatus(422);
        $this->putJson('/api/organization/url-slug', ['url_slug' => 'x'])->assertStatus(422);
    }

    public function test_admin_has_no_own_organization_to_manage(): void
    {
        $admin = $this->makeUser('Admin');
        Sanctum::actingAs($admin);

        $this->getJson('/api/organization/url-slug')->assertStatus(422);
    }

    public function test_client_only_ever_affects_their_own_organization(): void
    {
        $orgA = $this->makeOrg();
        $planA = $this->makePlan('professional');
        $this->setPlanEntitlement($planA, Feature::CUSTOM_BRANDED_SUBDOMAIN, true);
        $this->makeActiveSubscriptionWithSnapshot($orgA, 'professional');

        $orgB = $this->makeOrg();
        $clientA = $this->makeUser('Client', $orgA);
        Sanctum::actingAs($clientA);

        $this->putJson('/api/organization/url-slug', ['url_slug' => 'org-a-slug'])->assertStatus(200);

        $this->assertSame('org-a-slug', $orgA->fresh()->url_slug);
        $this->assertNull($orgB->fresh()->url_slug);
    }

    public function test_customer_cannot_reach_super_admin_domain_endpoints(): void
    {
        $org = $this->makeOrg();
        $client = $this->makeUser('Client', $org);
        Sanctum::actingAs($client);

        $this->postJson("/api/organizations/{$org->id}/domains", [
            'hostname' => 'contracts.customer.com', 'reason' => 'Trying as a Client.', 'confirmed' => true,
        ])->assertStatus(403);
    }

    // ── Slug lifecycle (customer path) ──────────────────────────────────────

    public function test_customer_can_change_and_remove_their_own_slug_with_history_recorded(): void
    {
        $org = $this->makeOrg();
        $plan = $this->makePlan('professional');
        $this->setPlanEntitlement($plan, Feature::CUSTOM_BRANDED_SUBDOMAIN, true);
        $this->makeActiveSubscriptionWithSnapshot($org, 'professional');
        $client = $this->makeUser('Client', $org);
        Sanctum::actingAs($client);

        $this->putJson('/api/organization/url-slug', ['url_slug' => 'first-name'])->assertStatus(200);
        $this->putJson('/api/organization/url-slug', ['url_slug' => 'second-name'])->assertStatus(200);

        $this->assertDatabaseHas('organization_url_slug_history', ['organization_id' => $org->id, 'url_slug' => 'first-name']);
        $this->assertSame('second-name', $org->fresh()->url_slug);

        $this->deleteJson('/api/organization/url-slug')->assertStatus(200);
        $this->assertNull($org->fresh()->url_slug);
        $this->assertDatabaseHas('activity_logs', ['action' => 'organization.url_branding_removed']);
    }

    public function test_customer_removal_is_allowed_even_without_entitlement(): void
    {
        $org = $this->makeOrg(['url_slug' => 'already-set']);
        $plan = $this->makePlan('essential');
        $this->setPlanEntitlement($plan, Feature::CUSTOM_BRANDED_SUBDOMAIN, false);
        $this->makeActiveSubscriptionWithSnapshot($org, 'essential');
        $client = $this->makeUser('Client', $org);
        Sanctum::actingAs($client);

        // Cannot CHANGE without entitlement...
        $this->putJson('/api/organization/url-slug', ['url_slug' => 'new-name'])->assertStatus(403);
        $this->assertSame('already-set', $org->fresh()->url_slug);

        // ...but CAN remove it.
        $this->deleteJson('/api/organization/url-slug')->assertStatus(200);
        $this->assertNull($org->fresh()->url_slug);
    }

    public function test_customer_cannot_reclaim_a_slug_released_by_a_different_organization(): void
    {
        $orgA = $this->makeOrg();
        $orgA->urlSlugHistory()->create(['url_slug' => 'star-affinity', 'released_at' => now()]);

        $orgB = $this->makeOrg();
        $planB = $this->makePlan('professional');
        $this->setPlanEntitlement($planB, Feature::CUSTOM_BRANDED_SUBDOMAIN, true);
        $this->makeActiveSubscriptionWithSnapshot($orgB, 'professional');
        $clientB = $this->makeUser('Client', $orgB);
        Sanctum::actingAs($clientB);

        $this->putJson('/api/organization/url-slug', ['url_slug' => 'star-affinity'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('url_slug');
    }

    public function test_reserved_slug_is_rejected_for_customer_path_too(): void
    {
        $org = $this->makeOrg();
        $plan = $this->makePlan('professional');
        $this->setPlanEntitlement($plan, Feature::CUSTOM_BRANDED_SUBDOMAIN, true);
        $this->makeActiveSubscriptionWithSnapshot($org, 'professional');
        $client = $this->makeUser('Client', $org);
        Sanctum::actingAs($client);

        $this->putJson('/api/organization/url-slug', ['url_slug' => 'admin'])
            ->assertStatus(422)->assertJsonValidationErrors('url_slug');
    }

    public function test_organization_name_change_does_not_affect_customer_managed_slug(): void
    {
        $org = $this->makeOrg(['url_slug' => 'stays-the-same']);
        $org->update(['name' => 'A Totally New Name']);

        $this->assertSame('stays-the-same', $org->fresh()->url_slug);
    }

    // ── Subscription access states ──────────────────────────────────────────

    public function test_restricted_subscription_blocks_setting_but_existing_url_still_resolves(): void
    {
        $org = $this->makeOrg(['url_slug' => 'still-works']);
        $plan = $this->makePlan('professional');
        $this->setPlanEntitlement($plan, Feature::CUSTOM_BRANDED_SUBDOMAIN, true);
        $this->makeActiveSubscriptionWithSnapshot($org, 'professional', ['status' => SubscriptionStatus::CANCELLED]);
        $client = $this->makeUser('Client', $org);
        Sanctum::actingAs($client);

        // Mutation blocked...
        $this->putJson('/api/organization/url-slug', ['url_slug' => 'new-one'])->assertStatus(403);

        // ...but the URL generator (never entitlement-aware) still resolves
        // the existing slug — existing emailed links keep working.
        Config::set('organisation_branding.root_domain', 'suresigncontracts.app');
        Config::set('suresign.marketing_url', 'https://marketing.example.test');
        $appointment = $this->makeAppointment($org);
        $url = app(AppointmentPublicLinkService::class)->cancelMarketingUrl($appointment);
        $this->assertStringStartsWith('https://still-works.suresigncontracts.app/appointments/', $url);
    }

    // ── Link generation ──────────────────────────────────────────────────────

    public function test_customer_set_slug_is_used_in_new_public_links(): void
    {
        Config::set('organisation_branding.root_domain', 'suresigncontracts.app');
        Config::set('suresign.marketing_url', 'https://marketing.example.test');
        $org = $this->makeOrg();
        $plan = $this->makePlan('professional');
        $this->setPlanEntitlement($plan, Feature::CUSTOM_BRANDED_SUBDOMAIN, true);
        $this->makeActiveSubscriptionWithSnapshot($org, 'professional');
        $client = $this->makeUser('Client', $org);
        Sanctum::actingAs($client);

        $this->putJson('/api/organization/url-slug', ['url_slug' => 'customer-set'])->assertStatus(200);

        $appointment = $this->makeAppointment($org->fresh());
        $url = app(AppointmentPublicLinkService::class)->cancelMarketingUrl($appointment);

        $this->assertStringStartsWith('https://customer-set.suresigncontracts.app/appointments/', $url);
    }

    // ── Entitlement rollout command ──────────────────────────────────────────

    public function test_rollout_command_dry_run_makes_no_changes(): void
    {
        $org = $this->makeOrg();
        $plan = $this->makePlan('professional');
        $this->setPlanEntitlement($plan, Feature::CUSTOM_BRANDED_SUBDOMAIN, true);
        $subscription = $this->makeActiveSubscriptionWithSnapshot($org, 'professional', []);
        // Simulate a PRE-EXISTING snapshot that predates the new key (no
        // custom_branded_subdomain entry at all).
        SubscriptionEntitlementSnapshot::where('subscription_id', $subscription->id)->update(['entitlements_json' => []]);

        $this->artisan('entitlements:refresh-capability-rollout --dry-run')->assertExitCode(0);

        $this->assertDatabaseMissing('billing_entitlement_snapshots', ['subscription_id' => $subscription->id, 'source_transition' => 'subscription.entitlement_rollout']);
    }

    public function test_rollout_command_creates_new_snapshot_for_missing_key(): void
    {
        $org = $this->makeOrg();
        $plan = $this->makePlan('professional');
        $this->setPlanEntitlement($plan, Feature::CUSTOM_BRANDED_SUBDOMAIN, true);
        $subscription = $this->makeActiveSubscriptionWithSnapshot($org, 'professional');
        SubscriptionEntitlementSnapshot::where('subscription_id', $subscription->id)->update(['entitlements_json' => []]);

        $this->artisan('entitlements:refresh-capability-rollout --confirm')->assertExitCode(0);

        $this->assertDatabaseHas('billing_entitlement_snapshots', [
            'subscription_id' => $subscription->id, 'source_transition' => 'subscription.entitlement_rollout',
        ]);
        $fresh = $subscription->fresh()->currentEntitlementSnapshot;
        $this->assertTrue($fresh->entitlements_json[Feature::CUSTOM_BRANDED_SUBDOMAIN]['value']);
    }

    public function test_rollout_command_is_idempotent_and_reports_unchanged_on_second_run(): void
    {
        $org = $this->makeOrg();
        $plan = $this->makePlan('professional');
        $this->setPlanEntitlement($plan, Feature::CUSTOM_BRANDED_SUBDOMAIN, true);
        $subscription = $this->makeActiveSubscriptionWithSnapshot($org, 'professional');
        SubscriptionEntitlementSnapshot::where('subscription_id', $subscription->id)->update(['entitlements_json' => []]);

        $this->artisan('entitlements:refresh-capability-rollout --confirm')->assertExitCode(0);
        $countAfterFirst = SubscriptionEntitlementSnapshot::where('subscription_id', $subscription->id)->count();

        $this->artisan('entitlements:refresh-capability-rollout --confirm')->assertExitCode(0);
        $countAfterSecond = SubscriptionEntitlementSnapshot::where('subscription_id', $subscription->id)->count();

        $this->assertSame($countAfterFirst, $countAfterSecond);
    }

    public function test_rollout_command_never_touches_essential_plan_subscriptions(): void
    {
        $org = $this->makeOrg();
        $plan = $this->makePlan('essential');
        $this->setPlanEntitlement($plan, Feature::CUSTOM_BRANDED_SUBDOMAIN, false);
        $subscription = $this->makeActiveSubscriptionWithSnapshot($org, 'essential');

        $this->artisan('entitlements:refresh-capability-rollout --confirm')->assertExitCode(0);

        $this->assertDatabaseMissing('billing_entitlement_snapshots', [
            'subscription_id' => $subscription->id, 'source_transition' => 'subscription.entitlement_rollout',
        ]);
    }

    public function test_rollout_command_never_touches_custom_domain_key(): void
    {
        $org = $this->makeOrg();
        $plan = $this->makePlan('professional');
        $this->setPlanEntitlement($plan, Feature::CUSTOM_BRANDED_SUBDOMAIN, true);
        $this->setPlanEntitlement($plan, Feature::CUSTOM_DOMAIN, false);
        $subscription = $this->makeActiveSubscriptionWithSnapshot($org, 'professional');
        SubscriptionEntitlementSnapshot::where('subscription_id', $subscription->id)->update(['entitlements_json' => []]);

        $this->artisan('entitlements:refresh-capability-rollout --confirm')->assertExitCode(0);

        $fresh = $subscription->fresh()->currentEntitlementSnapshot;
        $this->assertFalse($fresh->entitlements_json[Feature::CUSTOM_DOMAIN]['value']);
    }

    public function test_rollout_command_skips_cancelled_subscriptions(): void
    {
        $org = $this->makeOrg();
        $plan = $this->makePlan('professional');
        $this->setPlanEntitlement($plan, Feature::CUSTOM_BRANDED_SUBDOMAIN, true);
        $subscription = $this->makeActiveSubscriptionWithSnapshot($org, 'professional', ['status' => SubscriptionStatus::CANCELLED]);

        $this->artisan('entitlements:refresh-capability-rollout --confirm')->assertExitCode(0);

        $this->assertDatabaseMissing('billing_entitlement_snapshots', [
            'subscription_id' => $subscription->id, 'source_transition' => 'subscription.entitlement_rollout',
        ]);
    }
}
