<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Billing\Exceptions\InvalidWebhookSignatureException;
use App\Services\Billing\Exceptions\MalformedWebhookEventException;
use App\Services\Billing\Exceptions\WebhookModeMismatchException;
use App\Services\Billing\Exceptions\WebhookSecretNotConfiguredException;
use App\Services\Billing\WebhookIngestionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Public, unauthenticated Stripe webhook endpoint — trust comes entirely
 * from signature verification (WebhookIngestionService), never from
 * Sanctum/session auth, which Stripe's servers cannot participate in.
 *
 * Deliberately thin: reads the raw body and signature header, delegates
 * everything else to WebhookIngestionService, and translates its outcome/
 * exceptions into an HTTP response. No signature logic, persistence rule,
 * or deduplication decision lives here.
 */
class StripeWebhookController extends Controller
{
    public function __construct(private readonly WebhookIngestionService $ingestionService)
    {
    }

    public function handle(Request $request)
    {
        // config('billing.enabled') gates whether billing is reachable "at
        // all" (see config/billing.php's own docblock) — while dormant, a
        // webhook delivery is acknowledged (not retried; nothing about this
        // is Stripe-side misconfiguration) but never ingested/processed. See
        // App\Http\Middleware\EnsureBillingIsEnabled for the equivalent gate
        // on every mutating organisation-facing Billing endpoint.
        if (! config('billing.enabled')) {
            Log::info('Ignored an incoming Stripe webhook — billing is currently disabled (BILLING_ENABLED=false).');

            return response()->json(['status' => 'ignored'], 200);
        }

        // The EXACT raw body as received — never $request->all() or a
        // re-encoded copy, which would invalidate Stripe's signature.
        $rawBody = $request->getContent();
        $signature = $request->header('Stripe-Signature', '');

        try {
            $result = $this->ingestionService->ingest($rawBody, $signature);

            return response()->json(['status' => $result->outcome], $result->httpStatus);
        } catch (InvalidWebhookSignatureException) {
            // Never echo back why — an attacker probing this endpoint
            // learns nothing more than "rejected."
            return response()->json(['message' => 'Invalid signature.'], 400);
        } catch (MalformedWebhookEventException) {
            return response()->json(['message' => 'Malformed event.'], 400);
        } catch (WebhookModeMismatchException) {
            // Acknowledged, not retried — a mode mismatch is a Stripe
            // Dashboard/endpoint configuration issue a retry can never
            // resolve; the mismatch is already durably logged by the
            // ingestion service itself. See internal-docs/super-admin/
            // subscription-billing.md for the full rationale.
            return response()->json(['status' => 'ignored'], 200);
        } catch (WebhookSecretNotConfiguredException) {
            // A genuine deployment misconfiguration — 5xx so Stripe
            // retries once it's fixed; never expose which secret or any
            // config detail in the response body.
            Log::error('Stripe webhook secret not configured for the application\'s current mode.');

            return response()->json(['message' => 'Webhook is not configured.'], 500);
        }
    }
}
