<?php

namespace Tests\Unit\Entitlements;

use App\Models\Subscription;
use App\Services\Entitlements\SubscriptionAccessPolicy;
use App\Support\Billing\SubscriptionStatus;
use App\Support\Entitlements\SubscriptionAccessMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Tests\TestCase;

/**
 * Pure unit tests — `Subscription` is instantiated in memory (never
 * saved), so no database is touched at all, matching the checkpoint's
 * "no database dependency unless justified" instruction. Date-sensitive
 * cases (grace-period boundary) freeze time via `Date::setTestNow()`
 * rather than depending on the real current date.
 */
class SubscriptionAccessPolicyTest extends TestCase
{
    private SubscriptionAccessPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->policy = new SubscriptionAccessPolicy();
    }

    protected function tearDown(): void
    {
        Date::setTestNow();

        parent::tearDown();
    }

    private function subscription(array $attributes = []): Subscription
    {
        $subscription = new Subscription();
        $subscription->forceFill(array_merge([
            'status' => SubscriptionStatus::ACTIVE,
        ], $attributes));

        return $subscription;
    }

    public function test_no_subscription_resolves_none(): void
    {
        $decision = $this->policy->resolve(null);

        $this->assertSame(SubscriptionAccessMode::NONE, $decision->mode);
        $this->assertNull($decision->subscriptionStatus);
    }

    public function test_draft_resolves_none(): void
    {
        $decision = $this->policy->resolve($this->subscription(['status' => SubscriptionStatus::DRAFT]));

        $this->assertSame(SubscriptionAccessMode::NONE, $decision->mode);
    }

    public function test_pending_payment_resolves_none(): void
    {
        $decision = $this->policy->resolve($this->subscription(['status' => SubscriptionStatus::PENDING_PAYMENT]));

        $this->assertSame(SubscriptionAccessMode::NONE, $decision->mode);
    }

    public function test_incomplete_resolves_none(): void
    {
        $decision = $this->policy->resolve($this->subscription(['status' => SubscriptionStatus::INCOMPLETE]));

        $this->assertSame(SubscriptionAccessMode::NONE, $decision->mode);
    }

    public function test_trialing_resolves_trial(): void
    {
        $decision = $this->policy->resolve($this->subscription(['status' => SubscriptionStatus::TRIALING]));

        $this->assertSame(SubscriptionAccessMode::TRIAL, $decision->mode);
    }

    public function test_active_resolves_full(): void
    {
        $decision = $this->policy->resolve($this->subscription(['status' => SubscriptionStatus::ACTIVE]));

        $this->assertSame(SubscriptionAccessMode::FULL, $decision->mode);
    }

    public function test_active_with_scheduled_cancellation_still_resolves_full(): void
    {
        // Part 6/7: the mere presence of a scheduled action must not
        // remove access early — status stays ACTIVE (and thus FULL)
        // until SubscriptionLifecycleService::confirmCancellation()
        // actually flips it, which by construction only happens at/after
        // the effective date.
        $decision = $this->policy->resolve($this->subscription([
            'status' => SubscriptionStatus::ACTIVE,
            'cancel_at_period_end' => true,
            'current_period_ends_at' => CarbonImmutable::now()->addDay(),
        ]));

        $this->assertSame(SubscriptionAccessMode::FULL, $decision->mode);
        $this->assertStringContainsString('scheduled', $decision->reason);
    }

    public function test_past_due_within_grace_resolves_grace(): void
    {
        Date::setTestNow(CarbonImmutable::parse('2026-08-01 00:00:00'));

        $decision = $this->policy->resolve($this->subscription([
            'status' => SubscriptionStatus::PAST_DUE,
            'grace_period_ends_at' => CarbonImmutable::parse('2026-08-12 00:00:00'),
        ]));

        $this->assertSame(SubscriptionAccessMode::GRACE, $decision->mode);
    }

    public function test_past_due_with_no_grace_deadline_recorded_resolves_grace(): void
    {
        // No caller currently sets grace_period_ends_at automatically
        // (SubscriptionLifecycleService::startGracePeriod() exists but is
        // unused by any caller today — see this checkpoint's report) — a
        // past_due subscription with no recorded deadline must still
        // resolve GRACE, not fail closed.
        $decision = $this->policy->resolve($this->subscription([
            'status' => SubscriptionStatus::PAST_DUE,
            'grace_period_ends_at' => null,
        ]));

        $this->assertSame(SubscriptionAccessMode::GRACE, $decision->mode);
    }

    public function test_past_due_after_grace_expiry_resolves_restricted(): void
    {
        Date::setTestNow(CarbonImmutable::parse('2026-08-15 00:00:00'));

        $decision = $this->policy->resolve($this->subscription([
            'status' => SubscriptionStatus::PAST_DUE,
            'grace_period_ends_at' => CarbonImmutable::parse('2026-08-12 00:00:00'),
        ]));

        $this->assertSame(SubscriptionAccessMode::RESTRICTED, $decision->mode);
        $this->assertStringContainsString('2026-08-12', $decision->reason);
    }

    public function test_past_due_exactly_at_grace_boundary_still_resolves_grace(): void
    {
        // Boundary check: "expired" means strictly AFTER the deadline,
        // not at the exact instant — avoids an off-by-one cutting access
        // one instant early.
        Date::setTestNow(CarbonImmutable::parse('2026-08-12 00:00:00'));

        $decision = $this->policy->resolve($this->subscription([
            'status' => SubscriptionStatus::PAST_DUE,
            'grace_period_ends_at' => CarbonImmutable::parse('2026-08-12 00:00:00'),
        ]));

        $this->assertSame(SubscriptionAccessMode::GRACE, $decision->mode);
    }

    public function test_unpaid_resolves_restricted(): void
    {
        $decision = $this->policy->resolve($this->subscription(['status' => SubscriptionStatus::UNPAID]));

        $this->assertSame(SubscriptionAccessMode::RESTRICTED, $decision->mode);
    }

    public function test_paused_fails_safe_to_none(): void
    {
        $decision = $this->policy->resolve($this->subscription(['status' => SubscriptionStatus::PAUSED]));

        $this->assertSame(SubscriptionAccessMode::NONE, $decision->mode);
    }

    public function test_suspended_resolves_restricted(): void
    {
        $decision = $this->policy->resolve($this->subscription(['status' => SubscriptionStatus::SUSPENDED]));

        $this->assertSame(SubscriptionAccessMode::RESTRICTED, $decision->mode);
    }

    public function test_cancelled_resolves_restricted(): void
    {
        $decision = $this->policy->resolve($this->subscription(['status' => SubscriptionStatus::CANCELLED]));

        $this->assertSame(SubscriptionAccessMode::RESTRICTED, $decision->mode);
    }

    /**
     * Phase E6 — this reason string is rendered verbatim to the customer
     * by AccessStatusBanner; it must never name an implementing class or
     * method (previously did, the literal internal message this phase's
     * audit was triggered by).
     */
    public function test_cancelled_reason_never_names_an_implementation_class_or_method(): void
    {
        $decision = $this->policy->resolve($this->subscription(['status' => SubscriptionStatus::CANCELLED]));

        $this->assertStringNotContainsString('SubscriptionLifecycleService', $decision->reason);
        $this->assertStringNotContainsString('confirmCancellation', $decision->reason);
        $this->assertStringNotContainsString('cancelImmediately', $decision->reason);
    }

    public function test_expired_resolves_restricted(): void
    {
        $decision = $this->policy->resolve($this->subscription(['status' => SubscriptionStatus::EXPIRED]));

        $this->assertSame(SubscriptionAccessMode::RESTRICTED, $decision->mode);
    }

    public function test_unknown_status_fails_safe_to_none(): void
    {
        $decision = $this->policy->resolve($this->subscription(['status' => 'some_future_status']));

        $this->assertSame(SubscriptionAccessMode::NONE, $decision->mode);
        $this->assertSame('unrecognised_status', $decision->reasonCode);
    }

    // ─── Provider independence ───────────────────────────────────────────

    public function test_policy_never_imports_a_stripe_or_billing_provider_class(): void
    {
        $contents = file_get_contents(app_path('Services/Entitlements/SubscriptionAccessPolicy.php'));
        preg_match_all('/^use\s+([^;]+);/m', $contents, $matches);

        foreach ($matches[1] as $import) {
            $this->assertStringNotContainsStringIgnoringCase('stripe', $import);
            $this->assertStringNotContainsStringIgnoringCase('billingprovider', $import);
        }
    }
}
