<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Additive gap fix identified while building the event-processing
     * checkpoint: `billing_webhook_events` had no durable way to record
     * whether a `failed` row is safe to retry — inspection of the existing
     * schema (checkpoint 6) confirmed no such field, and encoding it inside
     * `failure_message` as a string convention would mean structured state
     * living in a free-text column, which this codebase avoids elsewhere
     * (see e.g. `processing_status` itself being a dedicated column rather
     * than folded into a note). Nullable — only ever meaningful once
     * `processing_status = failed`; `null` for every other status.
     * App\Services\Billing\WebhookEventProcessor is the only writer.
     */
    public function up(): void
    {
        Schema::table('billing_webhook_events', function (Blueprint $table) {
            $table->boolean('retryable')->nullable()->after('failure_message');
        });
    }

    public function down(): void
    {
        Schema::table('billing_webhook_events', function (Blueprint $table) {
            $table->dropColumn('retryable');
        });
    }
};
