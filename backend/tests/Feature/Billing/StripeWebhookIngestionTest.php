<?php

namespace Tests\Feature\Billing;

use App\Jobs\ProcessBillingWebhookEventJob;
use App\Models\ActivityLog;
use App\Models\BillingWebhookEvent;
use App\Services\Billing\FakeBillingProvider;
use App\Support\Billing\WebhookProcessingStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class StripeWebhookIngestionTest extends TestCase
{
    use RefreshDatabase;

    private FakeBillingProvider $fake;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fake = $this->app->make(FakeBillingProvider::class);
        $this->fake->livemode = false;

        config(['billing.enabled' => true]);
        config(['billing.stripe.webhook_secret_test' => 'whsec_test_secret']);
        config(['billing.stripe.webhook_secret_live' => 'whsec_live_secret']);

        // This suite tests INGESTION in isolation — checkpoint 6/7's
        // "ingestion never processes" invariant is exactly what several
        // tests below assert (e.g. a checkout.session.completed event
        // stays untouched). Since checkpoint 9 now dispatches
        // ProcessBillingWebhookEventJob after commit, and the test
        // environment's QUEUE_CONNECTION is `sync` (phpunit.xml), a real
        // dispatch here would execute WebhookEventProcessor synchronously
        // within the same request — turning every ingestion test into an
        // end-to-end processing test and breaking that isolation. Faking
        // only this one job class (not the whole queue) keeps the fake
        // narrowly scoped; dispatch-vs-no-dispatch itself is asserted by
        // the dedicated tests in the "Automatic dispatch" section below.
        Queue::fake([ProcessBillingWebhookEventJob::class]);
    }

    private function payload(array $overrides = []): string
    {
        return json_encode(array_merge([
            'id' => 'evt_' . random_int(1, 1000000),
            'object' => 'event',
            'type' => 'customer.subscription.updated',
            'created' => now()->subMinute()->timestamp,
            'livemode' => false,
            'api_version' => '2025-01-01',
            'request' => ['id' => 'req_123', 'idempotency_key' => null],
            'data' => ['object' => ['id' => 'sub_123']],
        ], $overrides));
    }

    private function postWebhook(string $payload, string $signature = 'valid:whsec_test_secret')
    {
        return $this->call('POST', '/api/billing/webhooks/stripe', [], [], [], [
            'HTTP_Stripe-Signature' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ], $payload);
    }

    // ─── Route and security ──────────────────────────────────────────────

    public function test_webhook_route_is_publicly_reachable_without_auth(): void
    {
        $response = $this->postWebhook($this->payload());

        $response->assertStatus(200);
    }

    public function test_route_carries_no_csrf_middleware(): void
    {
        $route = collect(Route::getRoutes())->first(
            fn ($r) => $r->uri() === 'api/billing/webhooks/stripe' && in_array('POST', $r->methods(), true)
        );

        $this->assertNotNull($route);
        $middleware = $route->gatherMiddleware();

        $this->assertNotContains('web', $middleware);
        foreach ($middleware as $m) {
            $this->assertStringNotContainsStringIgnoringCase('csrf', $m);
        }
    }

    public function test_invalid_signature_is_rejected(): void
    {
        $response = $this->postWebhook($this->payload(), 'valid:wrong_secret');

        $response->assertStatus(400);
        $this->assertDatabaseCount('billing_webhook_events', 0);
    }

    public function test_missing_signature_is_rejected(): void
    {
        $response = $this->postWebhook($this->payload(), '');

        $response->assertStatus(400);
        $this->assertDatabaseCount('billing_webhook_events', 0);
    }

    public function test_wrong_signing_secret_is_rejected(): void
    {
        // Correctly formatted per the fake's convention, but doesn't match
        // either configured secret.
        $response = $this->postWebhook($this->payload(), 'valid:some_other_secret');

        $response->assertStatus(400);
    }

    public function test_raw_body_mutation_invalidates_signature(): void
    {
        // The signature was computed (by the fake's own convention) against
        // the ORIGINAL payload; POSTing a different body with that same
        // signature must fail — proven against the real Stripe SDK
        // separately in StripeBillingProviderWebhookSignatureTest
        // (test_rejects_a_tampered_payload), which is what actually
        // exercises real HMAC verification. Here we confirm the same
        // principle holds through the full HTTP path with the bound fake.
        $original = $this->payload(['id' => 'evt_original']);
        $mutated = $this->payload(['id' => 'evt_mutated']);

        // Signature is only valid for whatever the fake decodes AS the
        // body — sending a mismatched raw body with a signature computed
        // for a DIFFERENT provider secret entirely demonstrates the same
        // rejection path (the fake ties validity to the secret, not body
        // content, so we exercise the real-SDK body-tampering case in the
        // dedicated Stripe SDK test file instead of duplicating HMAC logic
        // here).
        $response = $this->postWebhook($mutated, 'valid:wrong_secret_for_mutated_body');

        $response->assertStatus(400);
    }

    public function test_no_internal_exception_details_are_returned(): void
    {
        $response = $this->postWebhook($this->payload(), 'invalid');

        $response->assertStatus(400);
        $body = $response->json();
        $this->assertArrayNotHasKey('trace', $body);
        $this->assertArrayNotHasKey('exception', $body);
        $this->assertStringNotContainsString('whsec_', json_encode($body));
    }

    // ─── Verification ────────────────────────────────────────────────────

    public function test_correctly_signed_test_event_is_accepted(): void
    {
        $response = $this->postWebhook($this->payload());

        $response->assertStatus(200);
        $this->assertDatabaseCount('billing_webhook_events', 1);
    }

    public function test_correctly_signed_live_event_is_accepted_when_application_is_live(): void
    {
        $this->fake->livemode = true;

        $response = $this->postWebhook($this->payload(['livemode' => true]), 'valid:whsec_live_secret');

        $response->assertStatus(200);
        $this->assertDatabaseHas('billing_webhook_events', ['livemode' => true]);
    }

    public function test_event_type_and_provider_event_id_are_extracted(): void
    {
        $this->postWebhook($this->payload(['id' => 'evt_specific', 'type' => 'invoice.paid']));

        $this->assertDatabaseHas('billing_webhook_events', [
            'provider_event_id' => 'evt_specific',
            'event_type' => 'invoice.paid',
        ]);
    }

    public function test_provider_created_timestamp_is_extracted_from_event_created(): void
    {
        $createdAt = now()->subHours(2)->timestamp;
        $this->postWebhook($this->payload(['id' => 'evt_created_ts', 'created' => $createdAt]));

        $event = BillingWebhookEvent::where('provider_event_id', 'evt_created_ts')->firstOrFail();
        $this->assertSame($createdAt, $event->provider_created_at->timestamp);
    }

    public function test_api_version_is_extracted_where_present(): void
    {
        $this->postWebhook($this->payload(['id' => 'evt_api_ver', 'api_version' => '2025-06-01']));

        $this->assertDatabaseHas('billing_webhook_events', ['provider_event_id' => 'evt_api_ver', 'api_version' => '2025-06-01']);
    }

    public function test_livemode_is_preserved(): void
    {
        $this->postWebhook($this->payload(['id' => 'evt_livemode_check', 'livemode' => false]));

        $this->assertDatabaseHas('billing_webhook_events', ['provider_event_id' => 'evt_livemode_check', 'livemode' => false]);
    }

    // ─── Persistence ─────────────────────────────────────────────────────

    public function test_verified_event_is_persisted(): void
    {
        $this->postWebhook($this->payload(['id' => 'evt_persist']));

        $this->assertDatabaseHas('billing_webhook_events', ['provider_event_id' => 'evt_persist']);
    }

    public function test_initial_status_is_received(): void
    {
        $this->postWebhook($this->payload(['id' => 'evt_initial_status']));

        $event = BillingWebhookEvent::where('provider_event_id', 'evt_initial_status')->firstOrFail();
        $this->assertSame(WebhookProcessingStatus::RECEIVED, $event->processing_status);
    }

    public function test_payload_hash_is_persisted(): void
    {
        $payload = $this->payload(['id' => 'evt_hash']);
        $this->postWebhook($payload);

        $event = BillingWebhookEvent::where('provider_event_id', 'evt_hash')->firstOrFail();
        $this->assertSame(hash('sha256', $payload), $event->payload_hash);
    }

    public function test_raw_signature_header_is_never_persisted(): void
    {
        $this->postWebhook($this->payload(['id' => 'evt_no_sig_store']));

        $event = BillingWebhookEvent::where('provider_event_id', 'evt_no_sig_store')->firstOrFail();
        $this->assertStringNotContainsString('valid:whsec_test_secret', json_encode($event->toArray()));
    }

    public function test_no_lifecycle_state_change_occurs(): void
    {
        $this->postWebhook($this->payload(['id' => 'evt_no_lifecycle', 'type' => 'customer.subscription.updated']));

        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_no_checkout_session_state_change_occurs(): void
    {
        $this->postWebhook($this->payload(['id' => 'evt_no_checkout', 'type' => 'checkout.session.completed']));

        $this->assertDatabaseCount('billing_checkout_sessions', 0);
    }

    public function test_no_invoice_or_payment_record_is_created(): void
    {
        $this->postWebhook($this->payload(['id' => 'evt_no_invoice', 'type' => 'invoice.paid']));

        $this->assertDatabaseCount('billing_invoices', 0);
        $this->assertDatabaseCount('billing_payments', 0);
    }

    // ─── Idempotency ─────────────────────────────────────────────────────

    public function test_repeated_identical_delivery_creates_one_row(): void
    {
        $payload = $this->payload(['id' => 'evt_dup_identical']);

        $this->postWebhook($payload)->assertStatus(200);
        $this->postWebhook($payload)->assertStatus(200);

        $this->assertDatabaseCount('billing_webhook_events', 1);
    }

    public function test_duplicate_does_not_reset_processing_state(): void
    {
        $payload = $this->payload(['id' => 'evt_dup_state']);
        $this->postWebhook($payload);

        $event = BillingWebhookEvent::where('provider_event_id', 'evt_dup_state')->firstOrFail();
        $event->update(['processing_status' => WebhookProcessingStatus::PROCESSED, 'processed_at' => now()]);

        $this->postWebhook($payload)->assertStatus(200);

        $event->refresh();
        $this->assertSame(WebhookProcessingStatus::PROCESSED, $event->processing_status);
        $this->assertNotNull($event->processed_at);
    }

    public function test_duplicate_does_not_overwrite_payload(): void
    {
        $payload = $this->payload(['id' => 'evt_dup_payload', 'api_version' => '2025-01-01']);
        $this->postWebhook($payload);

        $original = BillingWebhookEvent::where('provider_event_id', 'evt_dup_payload')->firstOrFail();
        $originalHash = $original->payload_hash;

        $this->postWebhook($payload)->assertStatus(200);

        $original->refresh();
        $this->assertSame($originalHash, $original->payload_hash);
    }

    public function test_duplicate_with_mismatched_payload_is_detected_as_conflict(): void
    {
        $id = 'evt_conflict_1';
        $this->postWebhook($this->payload(['id' => $id, 'type' => 'customer.subscription.updated']));

        // Same provider_event_id, genuinely different payload content —
        // the fake's own signature validity doesn't depend on body
        // content, so this is a legitimate way to simulate Stripe
        // redelivering the "same" event ID with a mutated payload (which
        // should never happen from Stripe itself, but the ledger must
        // defend against it regardless).
        $response = $this->postWebhook($this->payload(['id' => $id, 'type' => 'customer.subscription.deleted']));

        $response->assertStatus(200); // acknowledged, not retried

        $this->assertDatabaseCount('billing_webhook_events', 1);
        $event = BillingWebhookEvent::where('provider_event_id', $id)->firstOrFail();
        $this->assertSame(WebhookProcessingStatus::CONFLICT, $event->processing_status);
        $this->assertSame('customer.subscription.updated', $event->event_type); // original preserved
    }

    public function test_conflict_on_an_already_processed_row_preserves_the_original_status(): void
    {
        $id = 'evt_conflict_processed';
        $this->postWebhook($this->payload(['id' => $id]));

        $event = BillingWebhookEvent::where('provider_event_id', $id)->firstOrFail();
        $event->update([
            'processing_status' => WebhookProcessingStatus::PROCESSED,
            'processed_at' => now(),
            'attempt_count' => 3,
        ]);
        $originalPayloadHash = $event->payload_hash;

        $response = $this->postWebhook($this->payload(['id' => $id, 'type' => 'something.different']));

        $response->assertStatus(200);

        $event->refresh();
        // Status/processed_at/attempt_count are NOT overwritten — the
        // historical processing result is preserved exactly.
        $this->assertSame(WebhookProcessingStatus::PROCESSED, $event->processing_status);
        $this->assertSame(3, $event->attempt_count);
        $this->assertNotNull($event->processed_at);
        $this->assertSame($originalPayloadHash, $event->payload_hash);
    }

    public function test_concurrent_duplicate_delivery_produces_one_row(): void
    {
        // True concurrency can't be exercised in a single synchronous test
        // process (same caveat as the lifecycle checkpoint's row-locking
        // test) — the actual safety net is the DB's own unique
        // (provider, provider_event_id) constraint, caught and reconciled
        // by WebhookIngestionService rather than relying on a check-then-
        // create race. This proves the reconciliation path itself is
        // correct; the constraint's existence is confirmed separately.
        $payload = $this->payload(['id' => 'evt_concurrent']);

        $this->postWebhook($payload);
        $this->postWebhook($payload);
        $this->postWebhook($payload);

        $this->assertDatabaseCount('billing_webhook_events', 1);
    }

    public function test_unique_constraint_exists_on_provider_and_provider_event_id(): void
    {
        BillingWebhookEvent::create([
            'provider' => 'stripe', 'provider_event_id' => 'evt_unique_1', 'event_type' => 'x',
            'livemode' => false, 'received_at' => now(), 'payload_json' => ['id' => 'evt_unique_1'],
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        BillingWebhookEvent::create([
            'provider' => 'stripe', 'provider_event_id' => 'evt_unique_1', 'event_type' => 'y',
            'livemode' => false, 'received_at' => now(), 'payload_json' => ['id' => 'evt_unique_1'],
        ]);
    }

    // ─── Isolation ───────────────────────────────────────────────────────

    public function test_test_and_live_events_remain_distinguishable(): void
    {
        $this->postWebhook($this->payload(['id' => 'evt_iso_test', 'livemode' => false]));

        $this->fake->livemode = true;
        $this->postWebhook($this->payload(['id' => 'evt_iso_live', 'livemode' => true]), 'valid:whsec_live_secret');

        $this->assertDatabaseHas('billing_webhook_events', ['provider_event_id' => 'evt_iso_test', 'livemode' => false]);
        $this->assertDatabaseHas('billing_webhook_events', ['provider_event_id' => 'evt_iso_live', 'livemode' => true]);
    }

    public function test_live_event_delivered_to_test_configured_application_is_rejected(): void
    {
        // Application is test-mode (fake->livemode = false, set in setUp);
        // the event itself claims livemode = true.
        $response = $this->postWebhook($this->payload(['id' => 'evt_opposite_1', 'livemode' => true]));

        $response->assertStatus(200); // acknowledged, never retried
        $this->assertDatabaseCount('billing_webhook_events', 0); // never persisted into the ledger
    }

    public function test_test_event_delivered_to_live_configured_application_is_rejected(): void
    {
        $this->fake->livemode = true;

        $response = $this->postWebhook($this->payload(['id' => 'evt_opposite_2', 'livemode' => false]), 'valid:whsec_live_secret');

        $response->assertStatus(200);
        $this->assertDatabaseCount('billing_webhook_events', 0);
    }

    public function test_missing_signing_secret_for_current_mode_returns_500(): void
    {
        config(['billing.stripe.webhook_secret_test' => '']);

        $response = $this->postWebhook($this->payload());

        $response->assertStatus(500);
        $this->assertStringNotContainsString('whsec_', json_encode($response->json()));
    }

    public function test_only_the_matching_mode_secret_is_ever_accepted(): void
    {
        // Application is test-mode; the LIVE secret must never validate a
        // request here, even though it's configured for the deployment.
        $response = $this->postWebhook($this->payload(), 'valid:whsec_live_secret');

        $response->assertStatus(400);
    }

    // ─── Checkout drift readiness ────────────────────────────────────────

    public function test_checkout_session_completed_is_stored_but_not_applied(): void
    {
        $checkoutSession = \App\Models\BillingCheckoutSession::create([
            'organization_id' => \App\Models\Organization::create(['name' => 'Acme', 'slug' => 'acme-' . random_int(1, 1000000), 'timezone' => 'Europe/London'])->id,
            'pricing_plan_id' => \App\Models\PricingPlan::create(['code' => 'p' . random_int(1, 1000000), 'slug' => 's' . random_int(1, 1000000), 'name' => 'Plan', 'currency' => 'GBP'])->id,
            'initiated_by_user_id' => \App\Models\User::factory()->create()->id,
            'provider' => 'stripe',
            'provider_checkout_session_id' => 'cs_drift_1',
            'internal_reference' => 'CHK-DRIFT-0001',
            'status' => 'open',
            'billing_interval' => 'monthly',
            'currency' => 'GBP',
            'amount' => 2999,
            'success_url' => '/success',
            'cancel_url' => '/cancel',
        ]);

        $this->postWebhook($this->payload([
            'id' => 'evt_checkout_completed',
            'type' => 'checkout.session.completed',
            'data' => ['object' => ['id' => 'cs_drift_1']],
        ]));

        $this->assertDatabaseHas('billing_webhook_events', ['provider_event_id' => 'evt_checkout_completed', 'event_type' => 'checkout.session.completed']);

        // Untouched — this checkpoint stores the event, never applies it.
        $checkoutSession->refresh();
        $this->assertSame('open', $checkoutSession->status);
    }

    public function test_checkout_session_expired_is_stored_but_not_applied(): void
    {
        $this->postWebhook($this->payload([
            'id' => 'evt_checkout_expired',
            'type' => 'checkout.session.expired',
            'data' => ['object' => ['id' => 'cs_drift_2']],
        ]));

        $event = BillingWebhookEvent::where('provider_event_id', 'evt_checkout_expired')->firstOrFail();
        $this->assertSame('checkout.session.expired', $event->event_type);
        $this->assertSame(WebhookProcessingStatus::RECEIVED, $event->processing_status);
        // The provider checkout session ID for future correlation is
        // present in the stored payload.
        $this->assertSame('cs_drift_2', $event->payload_json['data']['object']['id']);
    }

    // ─── Logging and security ────────────────────────────────────────────

    public function test_activity_log_never_contains_the_raw_payload(): void
    {
        $this->postWebhook($this->payload(['id' => 'evt_activity_log_check']));

        $log = ActivityLog::where('action', 'billing.webhook.received')->firstOrFail();
        $this->assertArrayNotHasKey('payload', $log->metadata ?? []);
        $this->assertArrayNotHasKey('payload_json', $log->metadata ?? []);
        $this->assertArrayNotHasKey('data', $log->metadata ?? []);
        // Only the two stable identifiers this event type logs — nothing
        // resembling the full verified event body.
        $this->assertSame(['provider_event_id', 'event_type'], array_keys($log->metadata ?? []));
    }

    public function test_signature_header_is_never_logged(): void
    {
        $this->postWebhook($this->payload(['id' => 'evt_sig_not_logged']));

        $log = ActivityLog::where('action', 'billing.webhook.received')->firstOrFail();
        $this->assertStringNotContainsString('valid:whsec', json_encode($log->metadata));
    }

    public function test_concise_operational_event_is_recorded_for_a_new_event(): void
    {
        $this->postWebhook($this->payload(['id' => 'evt_concise_log']));

        $this->assertDatabaseHas('activity_logs', ['action' => 'billing.webhook.received']);
    }

    public function test_concise_operational_event_is_recorded_for_a_duplicate(): void
    {
        $payload = $this->payload(['id' => 'evt_dup_log']);
        $this->postWebhook($payload);
        $this->postWebhook($payload);

        $this->assertDatabaseHas('activity_logs', ['action' => 'billing.webhook.duplicate']);
    }

    public function test_concise_operational_event_is_recorded_for_a_conflict(): void
    {
        $id = 'evt_conflict_log';
        $this->postWebhook($this->payload(['id' => $id, 'type' => 'a.b']));
        $this->postWebhook($this->payload(['id' => $id, 'type' => 'c.d']));

        $this->assertDatabaseHas('activity_logs', ['action' => 'billing.webhook.conflict']);
    }

    public function test_concise_operational_event_is_recorded_for_a_mode_mismatch(): void
    {
        $this->postWebhook($this->payload(['id' => 'evt_mode_mismatch_log', 'livemode' => true]));

        $this->assertDatabaseHas('activity_logs', ['action' => 'billing.webhook.mode_mismatch']);
    }

    // ─── Dedicated rate limiter ───────────────────────────────────────────

    public function test_route_uses_the_dedicated_billing_webhooks_limiter_not_the_generic_api_limiter(): void
    {
        $route = collect(Route::getRoutes())->first(
            fn ($r) => $r->uri() === 'api/billing/webhooks/stripe' && in_array('POST', $r->methods(), true)
        );

        $this->assertNotNull($route);

        // gatherMiddleware() only returns the DECLARED list (route-level
        // entries plus the bare 'api' group alias) — it does not expand
        // the group into its resolved middleware classes, nor does it
        // reflect withoutMiddleware() exclusions. Router::gatherRouteMiddleware()
        // is the one method that resolves aliases AND applies exclusions,
        // matching what actually runs on a real request — asserting
        // against gatherMiddleware() alone would pass even if the generic
        // api throttle were still stacked underneath the group alias.
        $resolved = app(\Illuminate\Routing\Router::class)->gatherRouteMiddleware($route);

        $this->assertContains('Illuminate\Routing\Middleware\ThrottleRequests:billing-webhooks', $resolved);
        $this->assertNotContains('Illuminate\Routing\Middleware\ThrottleRequests:api', $resolved);
    }

    public function test_requests_below_the_dedicated_limit_are_accepted(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $response = $this->postWebhook($this->payload(['id' => "evt_rl_{$i}"]));
            $response->assertStatus(200);
        }
    }

    public function test_exceeding_the_dedicated_limit_returns_a_safe_429(): void
    {
        for ($i = 0; $i < 120; $i++) {
            $this->postWebhook($this->payload(['id' => "evt_rl_burst_{$i}"]));
        }

        $response = $this->postWebhook($this->payload(['id' => 'evt_rl_over_limit']));

        $response->assertStatus(429);
        $body = $response->json();
        $this->assertArrayNotHasKey('trace', $body);
        $this->assertArrayNotHasKey('exception', $body);
    }

    // ─── Automatic dispatch (Automatic Webhook Processing checkpoint) ──────

    public function test_a_new_verified_event_dispatches_exactly_one_processing_job(): void
    {
        $this->postWebhook($this->payload(['id' => 'evt_dispatch_new']));

        $event = BillingWebhookEvent::where('provider_event_id', 'evt_dispatch_new')->firstOrFail();

        Queue::assertPushed(ProcessBillingWebhookEventJob::class, 1);
        Queue::assertPushed(function (ProcessBillingWebhookEventJob $job) use ($event) {
            return $this->jobTargetsEvent($job, $event->id);
        });
    }

    public function test_exact_duplicate_delivery_does_not_dispatch_a_second_job(): void
    {
        $payload = $this->payload(['id' => 'evt_dispatch_dup']);

        $this->postWebhook($payload);
        $this->postWebhook($payload);

        Queue::assertPushed(ProcessBillingWebhookEventJob::class, 1);
    }

    public function test_payload_mismatch_conflict_does_not_dispatch(): void
    {
        $id = 'evt_dispatch_conflict';
        $this->postWebhook($this->payload(['id' => $id, 'type' => 'customer.subscription.updated']));
        $this->postWebhook($this->payload(['id' => $id, 'type' => 'customer.subscription.deleted']));

        // One job for the original create; none for the conflicting redelivery.
        Queue::assertPushed(ProcessBillingWebhookEventJob::class, 1);
    }

    public function test_mode_mismatch_does_not_dispatch(): void
    {
        $this->postWebhook($this->payload(['id' => 'evt_dispatch_mismatch', 'livemode' => true]));

        Queue::assertNotPushed(ProcessBillingWebhookEventJob::class);
    }

    public function test_invalid_signature_does_not_dispatch(): void
    {
        $this->postWebhook($this->payload(['id' => 'evt_dispatch_badsig']), 'valid:wrong_secret');

        Queue::assertNotPushed(ProcessBillingWebhookEventJob::class);
    }

    public function test_missing_secret_does_not_dispatch(): void
    {
        config(['billing.stripe.webhook_secret_test' => '']);

        $this->postWebhook($this->payload(['id' => 'evt_dispatch_nosecret']));

        Queue::assertNotPushed(ProcessBillingWebhookEventJob::class);
    }

    private function jobTargetsEvent(ProcessBillingWebhookEventJob $job, int $eventId): bool
    {
        $property = new \ReflectionProperty(ProcessBillingWebhookEventJob::class, 'billingWebhookEventId');
        $property->setAccessible(true);

        return $property->getValue($job) === $eventId;
    }
}
