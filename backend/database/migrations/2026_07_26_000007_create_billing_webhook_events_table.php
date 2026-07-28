<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Durable webhook idempotency ledger. The unique (provider,
     * provider_event_id) constraint is the actual duplicate-delivery
     * defence — App\Services\Billing\WebhookProcessingService inserts this
     * row before any business-logic side effect runs, so a redelivered
     * Stripe event fails the insert and is recognised as already-seen
     * rather than reprocessed. payload_json stores the verified event body
     * only — Stripe webhook payloads never contain card numbers, CVCs, or
     * secrets, but this is still never exposed directly in an API response
     * (see App\Support\Billing\BillingPresenter).
     */
    public function up(): void
    {
        Schema::create('billing_webhook_events', function (Blueprint $table) {
            $table->id();

            $table->string('provider', 30);
            $table->string('provider_event_id');
            $table->string('event_type');
            $table->string('api_version')->nullable();
            $table->boolean('livemode')->default(false);

            // received | processing | processed | ignored | failed — see
            // App\Support\Billing\WebhookProcessingStatus.
            $table->string('processing_status', 20)->default('received');
            $table->unsignedInteger('attempt_count')->default(0);

            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('failure_message')->nullable();

            $table->json('payload_json');

            $table->timestamps();

            $table->unique(['provider', 'provider_event_id'], 'billing_webhook_events_provider_event_unique');
            $table->index(['processing_status']);
            $table->index(['event_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_webhook_events');
    }
};
