<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Additive gap fix identified while building verified webhook
     * ingestion: `billing_webhook_events` had no durable record of (a) the
     * exact raw payload's integrity (`payload_hash`) or (b) Stripe's own
     * `event.created` timestamp, distinct from `received_at` (when SureSign
     * happened to receive it). Both are required for this checkpoint's
     * duplicate/conflict detection and for the future processing
     * checkpoint's event-ordering rule (stale-event rejection must compare
     * against the PROVIDER's own timestamp, not local receipt time).
     *
     * Both nullable — existing rows (none exist yet in any real deployment,
     * since webhook ingestion has never been built before this checkpoint,
     * but nullable regardless for safety) are left exactly as they are, no
     * backfill: a provider timestamp cannot be reconstructed after the
     * fact without re-verifying against Stripe, and a payload hash must
     * never be computed from a re-serialized/decoded copy of `payload_json`
     * (that would hash something OTHER than the originally verified raw
     * body, defeating the point of the hash entirely — see
     * App\Services\Billing\WebhookIngestionService). Every NEWLY ingested
     * event from this checkpoint onward always populates both.
     *
     * `processing_status` remains the existing plain `string(20)` column —
     * adding the new `conflict` value (see
     * App\Support\Billing\WebhookProcessingStatus) is a PHP-level allow-list
     * change only, not a schema change, since the column was never a
     * database-level constrained enum.
     */
    public function up(): void
    {
        Schema::table('billing_webhook_events', function (Blueprint $table) {
            $table->string('payload_hash', 64)->nullable()->after('payload_json');
            $table->timestamp('provider_created_at')->nullable()->after('livemode');
        });

        // Supports future ordered processing/investigation queries
        // ("show events in the order Stripe generated them," not the order
        // they happened to arrive) — the one query pattern Part 15
        // explicitly anticipates. No index added for payload_hash: it is
        // looked up only via the existing (provider, provider_event_id)
        // unique constraint's row, never queried standalone.
        Schema::table('billing_webhook_events', function (Blueprint $table) {
            $table->index('provider_created_at');
        });
    }

    public function down(): void
    {
        Schema::table('billing_webhook_events', function (Blueprint $table) {
            $table->dropIndex(['provider_created_at']);
            $table->dropColumn(['payload_hash', 'provider_created_at']);
        });
    }
};
