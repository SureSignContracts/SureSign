<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Consultancy Communications & Global Email Experience Upgrade, Batch 1 —
 * the delivery/idempotency record for Consultancy customer communications.
 * Modelled directly on `App\Models\AppointmentReminderSend`'s proven
 * shape (a real DB unique constraint, not application-level "probably
 * once" logic) — NOT a single boolean per email type, per the approved
 * architecture.
 *
 * `idempotency_key` is the actual uniqueness guarantee: deterministic per
 * (appointment, communication_type, schedule_version) so a duplicate
 * dispatch (retried job, reconciliation re-observing the same available
 * state, a race between two queue workers) always collides on INSERT
 * rather than sending twice — the same "attempt the insert, only send if
 * it succeeds" pattern this codebase already uses in
 * `SendAppointmentReminders::claimReminderSend()` and
 * `App\Services\Billing\WebhookEventProcessor`.
 *
 * Batch 1 only ever writes `communication_type` values `booking_confirmed`/
 * `meeting_link_ready` — the column itself is a plain string (not an enum)
 * so Batch 2/3 can add `reminder_24h`/`booking_rescheduled`/etc. later with
 * no schema change.
 *
 * `recipient` deliberately stores the actual email address (needed for
 * support/ops investigation of a specific delivery, exactly like
 * `billing_invoices`/`AppointmentReminderSend` store real identifying
 * data) — the "do not log the raw address unnecessarily" security
 * requirement governs LOG lines, not this table's own column, which is a
 * legitimate operational record with normal DB access controls, not a log
 * file with broader retention/access.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Written defensively per the lesson already documented on
        // appointment_external_syncs' own creation migration (Stage 4B.1):
        // a Schema::create() blueprint combining several unique/index
        // definitions compiles to a CREATE TABLE followed by SEPARATE ALTER
        // TABLE statements on MySQL — if one of those trailing ALTERs fails,
        // the CREATE TABLE has already committed (MySQL DDL auto-commits per
        // statement), so a naive `Schema::hasTable()` guard on a re-run would
        // silently skip re-adding the missing index forever. Column creation
        // and index creation are therefore two separate, independently
        // guarded steps below.
        if (!Schema::hasTable('consultation_communication_deliveries')) {
            Schema::create('consultation_communication_deliveries', function (Blueprint $table) {
                $table->id();
                $table->foreignId('appointment_id')->constrained()->cascadeOnDelete();
                $table->string('communication_type', 60);
                $table->string('recipient');
                $table->unsignedInteger('schedule_version')->default(0);
                $table->string('status', 20)->default('queued'); // queued | sent | failed
                $table->timestamp('queued_at')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamp('failed_at')->nullable();
                $table->unsignedTinyInteger('attempt_count')->default(0);
                $table->string('provider_message_id')->nullable();
                $table->string('failure_category', 60)->nullable();
                $table->string('idempotency_key', 191);
                $table->timestamps();
            });
        }

        if (!Schema::hasIndex('consultation_communication_deliveries', 'ccd_idempotency_key_unique')) {
            Schema::table('consultation_communication_deliveries', function (Blueprint $table) {
                $table->unique('idempotency_key', 'ccd_idempotency_key_unique');
            });
        }

        if (!Schema::hasIndex('consultation_communication_deliveries', 'ccd_appointment_type_index')) {
            Schema::table('consultation_communication_deliveries', function (Blueprint $table) {
                $table->index(['appointment_id', 'communication_type'], 'ccd_appointment_type_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('consultation_communication_deliveries');
    }
};
