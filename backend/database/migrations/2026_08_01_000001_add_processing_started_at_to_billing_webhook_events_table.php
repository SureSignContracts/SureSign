<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Additive gap fix identified while designing claim recovery for the
     * Subscription Event Hardening checkpoint: `billing_webhook_events`
     * had no durable record of WHEN a row was last promoted to
     * `processing`, so an abandoned claim (a crashed/killed worker that
     * never reached `finalize()`) had no well-defined recovery rule.
     * Nullable — only meaningful while `processing_status = processing`;
     * cleared back to null on every finalize() outcome. See
     * App\Services\Billing\WebhookEventProcessor's claim-lease docblock
     * for the exact recovery policy this column backs.
     */
    public function up(): void
    {
        Schema::table('billing_webhook_events', function (Blueprint $table) {
            $table->timestamp('processing_started_at')->nullable()->after('attempt_count');
        });
    }

    public function down(): void
    {
        Schema::table('billing_webhook_events', function (Blueprint $table) {
            $table->dropColumn('processing_started_at');
        });
    }
};
