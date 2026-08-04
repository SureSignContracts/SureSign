<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Consultancy Live Booking Upgrade, Stage 3 — the immutable commercial and
// payment lifecycle record. Owns the commercial snapshot (frozen once
// Stripe Checkout is created); ConsultancySlotReservation continues to own
// only the temporary scheduling hold. See
// internal-docs/super-admin/consultancy.md's Stage 3 section for the full
// architecture rationale.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consultancy_payments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('reservation_id')->constrained('consultancy_slot_reservations')->restrictOnDelete();
            // Traceability only — every commercial value actually used for
            // Checkout/reconciliation/conversion is snapshotted below, never
            // re-read from this relation after Checkout creation.
            $table->foreignId('consultancy_service_id')->constrained('consultancy_services')->restrictOnDelete();

            // Commercial snapshot — frozen at Checkout-creation time.
            $table->string('service_code_snapshot');
            $table->string('service_name_snapshot');
            $table->text('description_snapshot')->nullable();
            $table->foreignId('consultant_user_id_snapshot')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('duration_minutes_snapshot');
            $table->timestamp('starts_at_snapshot');
            $table->timestamp('ends_at_snapshot');
            $table->string('booking_timezone_snapshot');
            $table->unsignedInteger('amount_minor_units');
            $table->string('currency', 3);
            // 'not_separately_calculated' for Stage 3's launch policy — a
            // real enum value recording what policy applied to THIS
            // transaction, never null/ambiguous. See
            // App\Support\Consultancy\ConsultancyTaxTreatment.
            $table->string('tax_treatment', 40);
            $table->unsignedInteger('subtotal_minor_units');
            $table->unsignedInteger('tax_minor_units')->default(0);
            $table->unsignedInteger('total_minor_units');
            $table->string('attendee_name_snapshot');
            $table->string('attendee_email_snapshot');

            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->foreignId('linked_user_id')->nullable()->constrained('users')->nullOnDelete();

            // The booking-attempt token this payment was created for —
            // retained for diagnostics/correlation, never logged raw (see
            // ActivityLog entries, which store a one-way hash instead).
            $table->string('booking_attempt_token', 64);

            $table->enum('status', [
                'creating', 'checkout_open', 'paid', 'conversion_pending',
                'converted', 'expired', 'cancelled', 'failed', 'manual_review',
            ])->default('creating');

            $table->string('provider', 30)->default('stripe');
            $table->boolean('livemode')->default(false);
            $table->string('stripe_checkout_session_id')->nullable()->unique();
            // The Stripe-hosted Checkout page URL itself (mirrors
            // billing_checkout_sessions.checkout_url's identical need) —
            // the browser must be redirected somewhere; re-deriving it from
            // Stripe on every read would be an unnecessary provider call.
            $table->text('checkout_url')->nullable();
            $table->string('stripe_payment_intent_id')->nullable()->unique();
            $table->timestamp('checkout_expires_at')->nullable();

            // Which verified webhook event most recently drove a status
            // change — the idempotency boundary for "has this exact event
            // already been applied" at the conversion step.
            $table->string('confirming_stripe_event_id')->nullable();

            $table->foreignId('appointment_id')->nullable()->constrained('appointments')->nullOnDelete();

            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('converted_at')->nullable();

            $table->timestamps();

            $table->index(['reservation_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consultancy_payments');
    }
};
