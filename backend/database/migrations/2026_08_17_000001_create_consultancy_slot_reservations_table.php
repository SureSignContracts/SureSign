<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Consultancy Live Booking Upgrade, Stage 2 — a temporary, server-
// authoritative hold on one Consultancy slot for one customer booking
// attempt. NOT an Appointment, NOT a payment, NOT a Consultation
// Engagement, NOT a Google Calendar event. See
// internal-docs/super-admin/consultancy.md's Stage 2 section.
return new class extends Migration
{
    public function up(): void
    {
        // Guarded: found running this migration in a local dev environment
        // where the table had already been created by an earlier,
        // interrupted migration run, while the migrations table still
        // recorded it as not-yet-run — see
        // 2026_08_16_000001_add_context_to_appointment_availability_tables.php's
        // own docblock for the full explanation.
        if (Schema::hasTable('consultancy_slot_reservations')) {
            return;
        }

        Schema::create('consultancy_slot_reservations', function (Blueprint $table) {
            $table->id();

            // Opaque, cryptographically random — never a bare sequential ID
            // exposed to any client.
            $table->string('public_token', 64)->unique();

            // The client-held booking-attempt idempotency boundary (never
            // consultant+service+start-time alone — two different
            // customers may legitimately compete for the same slot).
            // Retained on every row (including cancelled ones) for audit;
            // `active_attempt_token` is the actual uniqueness enforcement
            // point — set equal to this value only while status='active',
            // null otherwise, so a cancelled/expired/consumed row never
            // blocks a later reservation attempt reusing the same token.
            $table->string('booking_attempt_token', 64);
            $table->string('active_attempt_token', 64)->nullable()->unique();

            $table->foreignId('consultancy_service_id')->constrained('consultancy_services')->restrictOnDelete();
            $table->foreignId('consultant_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->foreignId('linked_user_id')->nullable()->constrained('users')->nullOnDelete();

            // Minimal pre-payment personal data, per the approved data-
            // minimisation decision — no phone/company/job-title/message
            // fields until the later booking/conversion stage genuinely
            // needs them.
            $table->string('attendee_name');
            $table->string('attendee_email');

            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('booking_timezone');

            $table->enum('status', ['active', 'consumed', 'expired', 'cancelled'])->default('active');
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->timestamps();

            // Supports the conflict re-check query
            // (consultant + active + unexpired + overlapping range).
            $table->index(['consultant_user_id', 'status', 'expires_at']);
            $table->index(['starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consultancy_slot_reservations');
    }
};
