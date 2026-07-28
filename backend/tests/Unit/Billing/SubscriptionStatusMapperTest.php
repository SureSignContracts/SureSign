<?php

namespace Tests\Unit\Billing;

use App\Support\Billing\SubscriptionStatus;
use App\Support\Billing\SubscriptionStatusMapper;
use Tests\TestCase;

class SubscriptionStatusMapperTest extends TestCase
{
    public function test_maps_every_known_stripe_status(): void
    {
        $this->assertSame(SubscriptionStatus::INCOMPLETE, SubscriptionStatusMapper::fromStripeStatus('incomplete'));
        // Changed in the Subscription Event Hardening checkpoint — see this
        // class's docblock for why incomplete_expired maps to EXPIRED, not
        // CANCELLED.
        $this->assertSame(SubscriptionStatus::EXPIRED, SubscriptionStatusMapper::fromStripeStatus('incomplete_expired'));
        $this->assertSame(SubscriptionStatus::TRIALING, SubscriptionStatusMapper::fromStripeStatus('trialing'));
        $this->assertSame(SubscriptionStatus::ACTIVE, SubscriptionStatusMapper::fromStripeStatus('active'));
        $this->assertSame(SubscriptionStatus::PAST_DUE, SubscriptionStatusMapper::fromStripeStatus('past_due'));
        $this->assertSame(SubscriptionStatus::CANCELLED, SubscriptionStatusMapper::fromStripeStatus('canceled'));
        $this->assertSame(SubscriptionStatus::UNPAID, SubscriptionStatusMapper::fromStripeStatus('unpaid'));
        $this->assertSame(SubscriptionStatus::PAUSED, SubscriptionStatusMapper::fromStripeStatus('paused'));
    }

    public function test_throws_on_unrecognised_stripe_status(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SubscriptionStatusMapper::fromStripeStatus('some_future_stripe_status');
    }

    public function test_is_known_stripe_status(): void
    {
        $this->assertTrue(SubscriptionStatusMapper::isKnownStripeStatus('active'));
        $this->assertFalse(SubscriptionStatusMapper::isKnownStripeStatus('draft'));
    }

    public function test_internal_only_statuses_are_never_produced_by_the_mapper(): void
    {
        // draft/pending_payment/suspended have no Stripe equivalent and must
        // never be reachable via fromStripeStatus().
        foreach (['draft', 'pending_payment', 'suspended'] as $internalOnlyStatus) {
            $this->assertFalse(SubscriptionStatusMapper::isKnownStripeStatus($internalOnlyStatus));
        }
    }
}
