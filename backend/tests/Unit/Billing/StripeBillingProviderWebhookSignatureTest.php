<?php

namespace Tests\Unit\Billing;

use App\Services\Billing\Exceptions\InvalidWebhookSignatureException;
use App\Services\Billing\StripeBillingProvider;
use Tests\TestCase;

/**
 * Exercises the REAL Stripe SDK signature-verification path
 * (\Stripe\Webhook::constructEvent, via StripeBillingProvider) against
 * locally-signed fixture payloads — no network call is made, no API key is
 * used, verification is a pure HMAC computation. This is deliberately kept
 * separate from FakeBillingProviderTest's signature test, which only checks
 * FakeBillingProvider's own simplified stand-in — this test is what
 * actually proves the real verification code path is correct.
 */
class StripeBillingProviderWebhookSignatureTest extends TestCase
{
    private const SECRET = 'whsec_test_secret';

    public function test_accepts_a_correctly_signed_payload(): void
    {
        $payload = json_encode(['id' => 'evt_test_1', 'type' => 'checkout.session.completed']);
        $header = $this->signedHeader($payload, self::SECRET);

        $event = (new StripeBillingProvider())->verifyWebhookSignature($payload, $header, self::SECRET);

        $this->assertSame('evt_test_1', $event['id']);
        $this->assertSame('checkout.session.completed', $event['type']);
    }

    public function test_rejects_a_payload_signed_with_the_wrong_secret(): void
    {
        $payload = json_encode(['id' => 'evt_test_1']);
        $header = $this->signedHeader($payload, 'whsec_wrong_secret');

        $this->expectException(InvalidWebhookSignatureException::class);
        (new StripeBillingProvider())->verifyWebhookSignature($payload, $header, self::SECRET);
    }

    public function test_rejects_a_tampered_payload(): void
    {
        $originalPayload = json_encode(['id' => 'evt_test_1', 'amount' => 100]);
        $header = $this->signedHeader($originalPayload, self::SECRET);

        $tamperedPayload = json_encode(['id' => 'evt_test_1', 'amount' => 999999]);

        $this->expectException(InvalidWebhookSignatureException::class);
        (new StripeBillingProvider())->verifyWebhookSignature($tamperedPayload, $header, self::SECRET);
    }

    public function test_rejects_a_missing_signature_header(): void
    {
        $payload = json_encode(['id' => 'evt_test_1']);

        $this->expectException(InvalidWebhookSignatureException::class);
        (new StripeBillingProvider())->verifyWebhookSignature($payload, '', self::SECRET);
    }

    /**
     * Builds a real Stripe-format `Stripe-Signature` header value using the
     * documented v1 scheme (HMAC-SHA256 over "{timestamp}.{payload}") —
     * the same algorithm \Stripe\WebhookSignature uses internally, applied
     * here only to construct a valid fixture, never to bypass verification.
     */
    private function signedHeader(string $payload, string $secret): string
    {
        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);

        return "t={$timestamp},v1={$signature}";
    }
}
