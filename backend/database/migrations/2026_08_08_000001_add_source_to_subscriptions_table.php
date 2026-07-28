<?php

use App\Support\Billing\SubscriptionSource;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase G4B.1 — Subscription Source Foundation. Adds the commercial-origin
 * classification (`App\Support\Billing\SubscriptionSource` — 'stripe' /
 * 'manual' / 'complimentary') to `subscriptions`.
 *
 * Deployment-safe three-step sequence, not a bare NOT NULL default:
 *   1. add the column nullable, so this migration never fails against
 *      existing rows;
 *   2. explicitly backfill every existing row to 'stripe' — the only
 *      production Subscription-creation path
 *      (`SubscriptionLifecycleService::createDraftSubscription()`) has
 *      ever run against a real Stripe provider price mapping, so this is
 *      not a guess;
 *   3. only then tighten the column to NOT NULL with a 'stripe' default,
 *      once every existing row is known to have a real value.
 *
 * `source` is never mutated after creation — see `Subscription`'s own
 * `booted()` guard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('source', 20)->nullable()->after('provider');
        });

        DB::table('subscriptions')->whereNull('source')->update(['source' => SubscriptionSource::STRIPE]);

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('source', 20)->default(SubscriptionSource::STRIPE)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
