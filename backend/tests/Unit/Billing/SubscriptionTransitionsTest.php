<?php

namespace Tests\Unit\Billing;

use App\Support\Billing\SubscriptionStatus;
use App\Support\Billing\SubscriptionTransitions;
use Tests\TestCase;

class SubscriptionTransitionsTest extends TestCase
{
    public function test_valid_transitions_are_allowed(): void
    {
        $this->assertTrue(SubscriptionTransitions::canTransition(SubscriptionStatus::DRAFT, SubscriptionStatus::PENDING_PAYMENT));
        $this->assertTrue(SubscriptionTransitions::canTransition(SubscriptionStatus::PENDING_PAYMENT, SubscriptionStatus::ACTIVE));
        $this->assertTrue(SubscriptionTransitions::canTransition(SubscriptionStatus::PENDING_PAYMENT, SubscriptionStatus::INCOMPLETE));
        $this->assertTrue(SubscriptionTransitions::canTransition(SubscriptionStatus::INCOMPLETE, SubscriptionStatus::ACTIVE));
        // Added in the Subscription Event Hardening checkpoint — see
        // SubscriptionTransitions' docblock for why incomplete_expired
        // maps to EXPIRED, not CANCELLED.
        $this->assertTrue(SubscriptionTransitions::canTransition(SubscriptionStatus::INCOMPLETE, SubscriptionStatus::EXPIRED));
        $this->assertTrue(SubscriptionTransitions::canTransition(SubscriptionStatus::INCOMPLETE, SubscriptionStatus::CANCELLED));
        $this->assertTrue(SubscriptionTransitions::canTransition(SubscriptionStatus::TRIALING, SubscriptionStatus::ACTIVE));
        $this->assertTrue(SubscriptionTransitions::canTransition(SubscriptionStatus::TRIALING, SubscriptionStatus::EXPIRED));
        $this->assertTrue(SubscriptionTransitions::canTransition(SubscriptionStatus::ACTIVE, SubscriptionStatus::PAST_DUE));
        $this->assertTrue(SubscriptionTransitions::canTransition(SubscriptionStatus::PAST_DUE, SubscriptionStatus::ACTIVE));
        $this->assertTrue(SubscriptionTransitions::canTransition(SubscriptionStatus::PAST_DUE, SubscriptionStatus::UNPAID));
        $this->assertTrue(SubscriptionTransitions::canTransition(SubscriptionStatus::PAST_DUE, SubscriptionStatus::SUSPENDED));
        $this->assertTrue(SubscriptionTransitions::canTransition(SubscriptionStatus::UNPAID, SubscriptionStatus::SUSPENDED));
        $this->assertTrue(SubscriptionTransitions::canTransition(SubscriptionStatus::ACTIVE, SubscriptionStatus::SUSPENDED));
        $this->assertTrue(SubscriptionTransitions::canTransition(SubscriptionStatus::SUSPENDED, SubscriptionStatus::ACTIVE));
        $this->assertTrue(SubscriptionTransitions::canTransition(SubscriptionStatus::ACTIVE, SubscriptionStatus::CANCELLED));
        $this->assertTrue(SubscriptionTransitions::canTransition(SubscriptionStatus::CANCELLED, SubscriptionStatus::EXPIRED));
        $this->assertTrue(SubscriptionTransitions::canTransition(SubscriptionStatus::PAUSED, SubscriptionStatus::ACTIVE));

        // Additions from the SubscriptionLifecycleService checkpoint.
        $this->assertTrue(SubscriptionTransitions::canTransition(SubscriptionStatus::DRAFT, SubscriptionStatus::TRIALING));
        $this->assertTrue(SubscriptionTransitions::canTransition(SubscriptionStatus::DRAFT, SubscriptionStatus::CANCELLED));
        $this->assertTrue(SubscriptionTransitions::canTransition(SubscriptionStatus::TRIALING, SubscriptionStatus::PENDING_PAYMENT));
        $this->assertTrue(SubscriptionTransitions::canTransition(SubscriptionStatus::TRIALING, SubscriptionStatus::CANCELLED));
        $this->assertTrue(SubscriptionTransitions::canTransition(SubscriptionStatus::PENDING_PAYMENT, SubscriptionStatus::EXPIRED));
        $this->assertTrue(SubscriptionTransitions::canTransition(SubscriptionStatus::SUSPENDED, SubscriptionStatus::CANCELLED));
    }

    public function test_invalid_transitions_are_rejected(): void
    {
        $this->assertFalse(SubscriptionTransitions::canTransition(SubscriptionStatus::DRAFT, SubscriptionStatus::ACTIVE));
        $this->assertFalse(SubscriptionTransitions::canTransition(SubscriptionStatus::EXPIRED, SubscriptionStatus::ACTIVE));
        $this->assertFalse(SubscriptionTransitions::canTransition(SubscriptionStatus::CANCELLED, SubscriptionStatus::ACTIVE));
        $this->assertFalse(SubscriptionTransitions::canTransition(SubscriptionStatus::SUSPENDED, SubscriptionStatus::PAST_DUE));

        // Deliberately not modeled — see SubscriptionTransitions' class
        // docblock: expiry only ever follows cancellation, a lapsed trial,
        // or an abandoned pending-payment window, never directly off an
        // active subscription (no "fixed term" concept exists to justify it).
        $this->assertFalse(SubscriptionTransitions::canTransition(SubscriptionStatus::ACTIVE, SubscriptionStatus::EXPIRED));
        $this->assertFalse(SubscriptionTransitions::canTransition(SubscriptionStatus::DRAFT, SubscriptionStatus::SUSPENDED));
    }

    public function test_a_status_never_transitions_to_itself(): void
    {
        foreach (SubscriptionStatus::ALL as $status) {
            $this->assertFalse(SubscriptionTransitions::canTransition($status, $status));
        }
    }

    public function test_expired_is_a_terminal_state(): void
    {
        $this->assertSame([], SubscriptionTransitions::allowedFrom(SubscriptionStatus::EXPIRED));
    }

    public function test_every_status_appears_in_the_transition_map(): void
    {
        foreach (SubscriptionStatus::ALL as $status) {
            $this->assertArrayHasKey($status, SubscriptionTransitions::MAP);
        }
    }
}
