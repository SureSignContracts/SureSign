<?php

namespace Tests\Feature\Entitlements;

use App\Models\Organization;
use App\Models\Subscription;
use App\Services\Entitlements\EntitlementOverrideRepository;
use App\Services\Entitlements\FeatureGate;
use App\Services\Entitlements\FeatureNotEntitledException;
use App\Support\Billing\SubscriptionStatus;
use App\Support\Entitlements\EntitlementSource;
use App\Support\Entitlements\EntitlementValue;
use App\Support\Entitlements\Feature;
use App\Support\Entitlements\PlanEntitlements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\TestCase;

class FeatureGateTest extends TestCase
{
    use RefreshDatabase;

    private FeatureGate $gate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gate = $this->app->make(FeatureGate::class);
    }

    private function org(): Organization
    {
        return Organization::create(['name' => 'Acme', 'slug' => 'acme-' . random_int(1, 10000000), 'timezone' => 'Europe/London']);
    }

    private function subscription(Organization $organization, array $overrides = []): Subscription
    {
        return Subscription::create(array_merge([
            'organization_id' => $organization->id,
            'provider' => 'stripe',
            'livemode' => false,
            'internal_reference' => 'SUB-TEST-' . random_int(1, 10000000),
            'status' => SubscriptionStatus::ACTIVE,
            'billing_interval' => 'monthly',
            'currency' => 'GBP',
            'unit_amount' => 2999,
            'plan_code_snapshot' => PlanEntitlements::PROFESSIONAL,
            // Predates the entitlement-snapshot support boundary
            // (config('billing.entitlement_snapshot_introduced_at')) —
            // these fixtures deliberately represent the legacy
            // compatibility-fallback case (a subscription created directly,
            // never through activate()/startTrial(), so it never received
            // a snapshot) rather than a modern subscription missing one.
            'starts_at' => '2026-01-01 00:00:00',
        ], $overrides));
    }

    // ─── No subscription ────────────────────────────────────────────────

    public function test_organisation_with_no_subscription_is_not_entitled(): void
    {
        $organization = $this->org();

        $this->assertFalse($this->gate->allows($organization, Feature::CUSTOM_BRANDING));
        $this->assertFalse($this->gate->allows($organization, Feature::ADVANCED_REPORTING));
    }

    // ─── Active subscription resolves the plan's defaults ──────────────

    public function test_active_subscription_resolves_its_plans_defaults(): void
    {
        $organization = $this->org();
        $this->subscription($organization, ['plan_code_snapshot' => PlanEntitlements::ESSENTIAL]);

        $this->assertTrue($this->gate->allows($organization, Feature::CUSTOM_BRANDING));
        $this->assertFalse($this->gate->allows($organization, Feature::ADVANCED_REPORTING));
    }

    public function test_professional_subscription_includes_advanced_reporting(): void
    {
        $organization = $this->org();
        $this->subscription($organization, ['plan_code_snapshot' => PlanEntitlements::PROFESSIONAL]);

        $this->assertTrue($this->gate->allows($organization, Feature::ADVANCED_REPORTING));
    }

    public function test_limit_returns_the_full_resolved_value_for_a_usage_entitlement(): void
    {
        $organization = $this->org();
        $this->subscription($organization, ['plan_code_snapshot' => PlanEntitlements::PROFESSIONAL]);

        $limit = $this->gate->limit($organization, Feature::MAX_ACTIVE_PROJECTS);

        $this->assertSame(25, $limit->value);
        $this->assertFalse($limit->isUnlimited);
    }

    // ─── Grace period (past_due) ────────────────────────────────────────

    public function test_past_due_subscription_still_resolves_full_entitlements(): void
    {
        $organization = $this->org();
        $this->subscription($organization, ['status' => SubscriptionStatus::PAST_DUE, 'plan_code_snapshot' => PlanEntitlements::PROFESSIONAL]);

        $this->assertTrue($this->gate->allows($organization, Feature::ADVANCED_REPORTING));
    }

    // ─── Inactive / pre-activation subscriptions ────────────────────────

    public function test_draft_subscription_is_not_entitled(): void
    {
        $organization = $this->org();
        $this->subscription($organization, ['status' => SubscriptionStatus::DRAFT]);

        $this->assertFalse($this->gate->allows($organization, Feature::CUSTOM_BRANDING));
    }

    public function test_pending_payment_subscription_is_not_entitled(): void
    {
        $organization = $this->org();
        $this->subscription($organization, ['status' => SubscriptionStatus::PENDING_PAYMENT]);

        $this->assertFalse($this->gate->allows($organization, Feature::CUSTOM_BRANDING));
    }

    public function test_incomplete_subscription_is_not_entitled(): void
    {
        $organization = $this->org();
        $this->subscription($organization, ['status' => SubscriptionStatus::INCOMPLETE]);

        $this->assertFalse($this->gate->allows($organization, Feature::CUSTOM_BRANDING));
    }

    // ─── Expired / terminal subscriptions ───────────────────────────────

    public function test_expired_subscription_is_not_entitled(): void
    {
        $organization = $this->org();
        $this->subscription($organization, ['status' => SubscriptionStatus::EXPIRED, 'plan_code_snapshot' => PlanEntitlements::PROFESSIONAL]);

        $this->assertFalse($this->gate->allows($organization, Feature::ADVANCED_REPORTING));
    }

    public function test_cancelled_subscription_is_not_entitled(): void
    {
        $organization = $this->org();
        $this->subscription($organization, ['status' => SubscriptionStatus::CANCELLED, 'plan_code_snapshot' => PlanEntitlements::PROFESSIONAL]);

        $this->assertFalse($this->gate->allows($organization, Feature::ADVANCED_REPORTING));
    }

    public function test_suspended_subscription_is_not_entitled(): void
    {
        $organization = $this->org();
        $this->subscription($organization, ['status' => SubscriptionStatus::SUSPENDED, 'plan_code_snapshot' => PlanEntitlements::PROFESSIONAL]);

        $this->assertFalse($this->gate->allows($organization, Feature::ADVANCED_REPORTING));
    }

    // ─── Trial subscriptions ────────────────────────────────────────────

    public function test_trialing_subscription_resolves_the_dedicated_trial_profile_not_a_plan_default(): void
    {
        $organization = $this->org();
        // plan_code_snapshot intentionally left null — a trial has not
        // necessarily been assigned a standard plan snapshot at all.
        $this->subscription($organization, ['status' => SubscriptionStatus::TRIALING, 'plan_code_snapshot' => null]);

        $limit = $this->gate->limit($organization, Feature::AI_ANALYSES_PER_MONTH);

        $this->assertSame(PlanEntitlements::trialProfile()[Feature::AI_ANALYSES_PER_MONTH]->value, $limit->value);
        $this->assertTrue($this->gate->allows($organization, Feature::ADVANCED_REPORTING));
    }

    // ─── Dormant / unknown ───────────────────────────────────────────────

    public function test_dormant_keys_are_never_entitled_regardless_of_plan(): void
    {
        $organization = $this->org();
        $this->subscription($organization, ['plan_code_snapshot' => PlanEntitlements::ENTERPRISE]);

        $value = $this->gate->limit($organization, Feature::MAX_USERS);

        $this->assertNull($value->value);
        $this->assertSame(EntitlementSource::NONE, $value->source);
    }

    public function test_unknown_feature_key_throws(): void
    {
        $organization = $this->org();

        $this->expectException(\InvalidArgumentException::class);
        $this->gate->allows($organization, 'not_a_real_key');
    }

    public function test_unrecognised_plan_code_snapshot_is_not_entitled(): void
    {
        $organization = $this->org();
        $this->subscription($organization, ['plan_code_snapshot' => 'some_legacy_plan_code']);

        $this->assertFalse($this->gate->allows($organization, Feature::CUSTOM_BRANDING));
    }

    // ─── requireFeature() / explain() ───────────────────────────────────

    public function test_require_feature_throws_when_not_entitled(): void
    {
        $organization = $this->org();

        $this->expectException(FeatureNotEntitledException::class);
        $this->gate->requireFeature($organization, Feature::ADVANCED_REPORTING);
    }

    // Error Messaging & Recovery UX, Batch 1 (P0 fix) — the exception's own
    // getMessage() must never leak the raw organisation ID or the raw
    // internal Feature key, since a future caller may forward it verbatim
    // to a customer response (the existing pattern several controllers
    // already use for other typed exceptions). The organisation/feature key
    // must still be available via typed properties for server-side logging.
    public function test_feature_not_entitled_exception_message_does_not_leak_organisation_or_feature_key(): void
    {
        $organization = $this->org();

        try {
            $this->gate->requireFeature($organization, Feature::ADVANCED_REPORTING);
            $this->fail('Expected FeatureNotEntitledException was not thrown.');
        } catch (FeatureNotEntitledException $e) {
            $this->assertStringNotContainsString((string) $organization->id, $e->getMessage());
            $this->assertStringNotContainsString(Feature::ADVANCED_REPORTING, $e->getMessage());
            $this->assertStringNotContainsString('Organisation', $e->getMessage());

            // Internal context must still be fully available — just never
            // through getMessage().
            $this->assertSame($organization->id, $e->organization->id);
            $this->assertSame(Feature::ADVANCED_REPORTING, $e->featureKey);
            $this->assertSame('feature_not_entitled', $e->errorCode);
            $this->assertSame($organization->id, $e->logContext()['organization_id']);
            $this->assertSame(Feature::ADVANCED_REPORTING, $e->logContext()['feature_key']);
        }
    }

    public function test_require_feature_is_silent_when_entitled(): void
    {
        $organization = $this->org();
        $this->subscription($organization, ['plan_code_snapshot' => PlanEntitlements::PROFESSIONAL]);

        $this->gate->requireFeature($organization, Feature::ADVANCED_REPORTING);
        $this->addToAssertionCount(1);
    }

    public function test_explain_reports_the_subscription_status_and_a_human_reason(): void
    {
        $organization = $this->org();
        $this->subscription($organization, ['status' => SubscriptionStatus::SUSPENDED, 'plan_code_snapshot' => PlanEntitlements::PROFESSIONAL]);

        $decision = $this->gate->explain($organization, Feature::ADVANCED_REPORTING);

        $this->assertSame(SubscriptionStatus::SUSPENDED, $decision->subscriptionStatus);
        $this->assertStringContainsString('suspended', $decision->reason);
        $this->assertIsArray($decision->toArray());
    }

    // ─── Future override resolution (Part 8/9's extension seam) ─────────

    public function test_an_active_override_takes_precedence_over_the_plan_default(): void
    {
        $organization = $this->org();
        $this->subscription($organization, ['plan_code_snapshot' => PlanEntitlements::ESSENTIAL]);

        $stubOverrides = new class implements EntitlementOverrideRepository {
            public function findActiveOverride(Subscription $subscription, string $featureKey): ?EntitlementValue
            {
                if ($featureKey === Feature::ADVANCED_REPORTING) {
                    return EntitlementValue::make(
                        Feature::ADVANCED_REPORTING,
                        \App\Support\Entitlements\EntitlementValueType::BOOLEAN,
                        true,
                        false,
                        EntitlementSource::NEGOTIATED_OVERRIDE,
                        null,
                        true,
                    );
                }

                return null;
            }
        };

        $gateWithOverride = new FeatureGate($stubOverrides, new \App\Services\Entitlements\SubscriptionAccessPolicy(), new \App\Services\Entitlements\SnapshotIntegrityClassifier(app(\App\Services\Entitlements\PlanEntitlementRepository::class)), app(\App\Services\Entitlements\PlanEntitlementRepository::class));

        // Essential does not normally include advanced_reporting...
        $this->assertFalse($this->gate->allows($organization, Feature::ADVANCED_REPORTING));
        // ...but a negotiated override grants it, without any module code
        // changing at all — only the injected collaborator differs.
        $this->assertTrue($gateWithOverride->allows($organization, Feature::ADVANCED_REPORTING));
    }

    public function test_dormant_keys_ignore_overrides_entirely(): void
    {
        $organization = $this->org();
        $this->subscription($organization);

        $stubOverrides = new class implements EntitlementOverrideRepository {
            public function findActiveOverride(Subscription $subscription, string $featureKey): ?EntitlementValue
            {
                // Even a (deliberately invalid) attempt to override a
                // dormant key must never surface — FeatureGate checks
                // Feature::isDormant() before ever consulting overrides.
                return EntitlementValue::make(Feature::MAX_USERS, \App\Support\Entitlements\EntitlementValueType::INTEGER, 999, false, EntitlementSource::NEGOTIATED_OVERRIDE);
            }
        };

        $gateWithOverride = new FeatureGate($stubOverrides, new \App\Services\Entitlements\SubscriptionAccessPolicy(), new \App\Services\Entitlements\SnapshotIntegrityClassifier(app(\App\Services\Entitlements\PlanEntitlementRepository::class)), app(\App\Services\Entitlements\PlanEntitlementRepository::class));
        $value = $gateWithOverride->limit($organization, Feature::MAX_USERS);

        $this->assertNull($value->value);
    }

    /**
     * The safety fix this checkpoint makes: a manual/negotiated override
     * must NEVER silently resurrect access for an organisation whose
     * access mode is RESTRICTED (or NONE) — Part 10's explicit
     * requirement. Confirmed against `suspended` specifically, since
     * that's the scenario the checkpoint brief names directly.
     */
    public function test_override_cannot_bypass_a_suspended_subscription(): void
    {
        $organization = $this->org();
        $this->subscription($organization, ['status' => SubscriptionStatus::SUSPENDED, 'plan_code_snapshot' => PlanEntitlements::PROFESSIONAL]);

        $stubOverrides = new class implements EntitlementOverrideRepository {
            public function findActiveOverride(Subscription $subscription, string $featureKey): ?EntitlementValue
            {
                return EntitlementValue::make(
                    Feature::ADVANCED_REPORTING,
                    \App\Support\Entitlements\EntitlementValueType::BOOLEAN,
                    true,
                    false,
                    EntitlementSource::NEGOTIATED_OVERRIDE,
                    null,
                    true,
                );
            }
        };

        $gateWithOverride = new FeatureGate($stubOverrides, new \App\Services\Entitlements\SubscriptionAccessPolicy(), new \App\Services\Entitlements\SnapshotIntegrityClassifier(app(\App\Services\Entitlements\PlanEntitlementRepository::class)), app(\App\Services\Entitlements\PlanEntitlementRepository::class));

        $this->assertFalse($gateWithOverride->allows($organization, Feature::ADVANCED_REPORTING));
    }

    public function test_override_cannot_bypass_a_draft_subscription(): void
    {
        $organization = $this->org();
        $this->subscription($organization, ['status' => SubscriptionStatus::DRAFT]);

        $stubOverrides = new class implements EntitlementOverrideRepository {
            public function findActiveOverride(Subscription $subscription, string $featureKey): ?EntitlementValue
            {
                return EntitlementValue::make(Feature::CUSTOM_BRANDING, \App\Support\Entitlements\EntitlementValueType::BOOLEAN, true, false, EntitlementSource::NEGOTIATED_OVERRIDE);
            }
        };

        $gateWithOverride = new FeatureGate($stubOverrides, new \App\Services\Entitlements\SubscriptionAccessPolicy(), new \App\Services\Entitlements\SnapshotIntegrityClassifier(app(\App\Services\Entitlements\PlanEntitlementRepository::class)), app(\App\Services\Entitlements\PlanEntitlementRepository::class));

        $this->assertFalse($gateWithOverride->allows($organization, Feature::CUSTOM_BRANDING));
    }

    // ─── Provider independence ───────────────────────────────────────────

    public function test_feature_gate_has_no_stripe_or_billing_provider_dependency(): void
    {
        $constructor = (new ReflectionClass(FeatureGate::class))->getConstructor();

        $this->assertNotNull($constructor);

        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            $typeName = $type instanceof \ReflectionNamedType ? $type->getName() : (string) $type;

            $this->assertStringNotContainsStringIgnoringCase('stripe', $typeName);
            $this->assertStringNotContainsStringIgnoringCase('billingprovider', $typeName);
        }
    }

    public function test_entitlement_classes_never_import_a_stripe_or_billing_provider_class(): void
    {
        // Checks actual `use`-imports of Stripe/provider classes — not
        // the literal word "Stripe," which legitimately appears in this
        // subsystem's own explanatory docblocks (e.g. FeatureGate's class
        // docblock explains exactly why it has no such dependency).
        $files = array_merge(
            glob(app_path('Support/Entitlements/*.php')),
            glob(app_path('Services/Entitlements/*.php')),
        );

        foreach ($files as $file) {
            $contents = file_get_contents($file);
            $imports = [];
            preg_match_all('/^use\s+([^;]+);/m', $contents, $matches);
            $imports = $matches[1] ?? [];

            foreach ($imports as $import) {
                $this->assertStringNotContainsStringIgnoringCase('stripe', $import, "{$file} must never import a Stripe class ({$import}).");
                $this->assertStringNotContainsStringIgnoringCase('BillingProviderInterface', $import, "{$file} must never depend on the billing provider abstraction ({$import}).");
            }
        }
    }
}
