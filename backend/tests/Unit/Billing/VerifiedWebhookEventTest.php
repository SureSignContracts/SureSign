<?php

namespace Tests\Unit\Billing;

use App\Services\Billing\Exceptions\MalformedWebhookEventException;
use App\Services\Billing\VerifiedWebhookEvent;
use Tests\TestCase;

class VerifiedWebhookEventTest extends TestCase
{
    private function verifiedArray(array $overrides = []): array
    {
        return array_merge([
            'id' => 'evt_1',
            'type' => 'customer.subscription.updated',
            'created' => 1735689600,
            'livemode' => false,
            'api_version' => '2025-01-01',
            'request' => ['id' => 'req_1'],
            'account' => null,
        ], $overrides);
    }

    public function test_builds_a_normalized_envelope_from_a_verified_array(): void
    {
        $rawBody = json_encode($this->verifiedArray());
        $event = VerifiedWebhookEvent::fromVerified('stripe', $rawBody, $this->verifiedArray());

        $this->assertSame('stripe', $event->provider);
        $this->assertSame('evt_1', $event->providerEventId);
        $this->assertSame('customer.subscription.updated', $event->eventType);
        $this->assertFalse($event->livemode);
        $this->assertSame(1735689600, $event->providerCreatedAt->timestamp);
        $this->assertSame('2025-01-01', $event->apiVersion);
        $this->assertSame('req_1', $event->requestId);
        $this->assertNull($event->accountId);
    }

    public function test_payload_hash_is_sha256_of_the_exact_raw_body(): void
    {
        $rawBody = json_encode($this->verifiedArray());
        $event = VerifiedWebhookEvent::fromVerified('stripe', $rawBody, $this->verifiedArray());

        $this->assertSame(hash('sha256', $rawBody), $event->payloadHash);
    }

    public function test_payload_hash_differs_if_raw_body_differs_from_the_verified_array(): void
    {
        // Deliberately hash a DIFFERENT raw body than the one the array was
        // derived from — proves the hash is of the raw body, not a
        // re-serialization of the array.
        $event = VerifiedWebhookEvent::fromVerified('stripe', 'not the same bytes', $this->verifiedArray());

        $this->assertSame(hash('sha256', 'not the same bytes'), $event->payloadHash);
        $this->assertNotSame(hash('sha256', json_encode($this->verifiedArray())), $event->payloadHash);
    }

    public function test_rejects_a_missing_id(): void
    {
        $this->expectException(MalformedWebhookEventException::class);
        VerifiedWebhookEvent::fromVerified('stripe', '{}', $this->verifiedArray(['id' => null]));
    }

    public function test_rejects_a_missing_type(): void
    {
        $this->expectException(MalformedWebhookEventException::class);
        VerifiedWebhookEvent::fromVerified('stripe', '{}', $this->verifiedArray(['type' => null]));
    }

    public function test_rejects_a_missing_created_timestamp(): void
    {
        $this->expectException(MalformedWebhookEventException::class);
        VerifiedWebhookEvent::fromVerified('stripe', '{}', $this->verifiedArray(['created' => null]));
    }

    public function test_defaults_livemode_false_when_absent(): void
    {
        $array = $this->verifiedArray();
        unset($array['livemode']);

        $event = VerifiedWebhookEvent::fromVerified('stripe', '{}', $array);

        $this->assertFalse($event->livemode);
    }

    public function test_api_version_and_request_id_are_nullable(): void
    {
        $event = VerifiedWebhookEvent::fromVerified('stripe', '{}', $this->verifiedArray(['api_version' => null, 'request' => null]));

        $this->assertNull($event->apiVersion);
        $this->assertNull($event->requestId);
    }

    public function test_contains_no_stripe_sdk_object(): void
    {
        $event = VerifiedWebhookEvent::fromVerified('stripe', '{}', $this->verifiedArray());

        foreach (get_object_vars($event) as $value) {
            $this->assertFalse(is_object($value) && str_starts_with(get_class($value), 'Stripe\\'));
        }
    }
}
