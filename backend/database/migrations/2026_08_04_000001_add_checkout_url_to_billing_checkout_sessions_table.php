<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gap found during Slice C2 (First Subscription Checkout): the table
 * stored our OWN success/cancel redirect targets but never the Stripe-
 * hosted Checkout page URL itself (`providerSession['url']` from
 * BillingProviderInterface::createCheckoutSession()) — meaning nothing
 * could give a browser a redirect target on the reuse path (an existing
 * OPEN session is reused without any provider call at all, so there was
 * never anywhere the URL could be re-derived from without an extra live
 * API round-trip on every request). Nullable and additive only.
 *
 * `text`, not `string` (VARCHAR 255) — a real Stripe Checkout URL includes
 * a long opaque fragment after `#` that can exceed 255 characters (caught
 * by a genuine `Data too long for column` error against real Stripe
 * Sandbox during this checkpoint's own live validation, before this
 * migration had ever been relied on elsewhere).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_checkout_sessions', function (Blueprint $table) {
            $table->text('checkout_url')->nullable()->after('provider_checkout_session_id');
        });
    }

    public function down(): void
    {
        Schema::table('billing_checkout_sessions', function (Blueprint $table) {
            $table->dropColumn('checkout_url');
        });
    }
};
