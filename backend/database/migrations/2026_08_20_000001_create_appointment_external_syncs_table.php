<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Stage 4B.1 (Google Calendar Event Synchronisation) — the provider-neutral
// record of an Appointment's external-representation lifecycle. Owns only
// the synchronisation state; Appointment itself remains the sole source of
// truth for booking state (see App\Models\Appointment::isEligibleForExternalSync()).
// See internal-docs/super-admin/google-integration.md's Stage 4B.1 section
// for the full architecture rationale.
return new class extends Migration
{
    /**
     * Every step is guarded to be safely re-runnable. MySQL DDL auto-
     * commits per statement — a create() blueprint with several unique/
     * index commands compiles to a SEQUENCE of separate ALTER statements
     * after the initial CREATE TABLE, not one atomic statement. An
     * interrupted run (observed locally: the table and its two foreign-
     * key-backed indexes existed, but the explicit unique/index commands
     * below had not yet run, while the migrations table still recorded
     * this migration as not-yet-run) otherwise leaves it permanently
     * unable to complete — see
     * 2026_08_16_000001_add_context_to_appointment_availability_tables.php's
     * own docblock for the first occurrence of this exact class of issue
     * in this codebase.
     */
    public function up(): void
    {
        if (!Schema::hasTable('appointment_external_syncs')) {
            Schema::create('appointment_external_syncs', function (Blueprint $table) {
                $table->id();

                $table->foreignId('appointment_id')->constrained('appointments')->restrictOnDelete();
                // Which connection was actually used for the last attempt —
                // diagnostic/history only. Nullable: a row may exist (pending)
                // before any connection was ever consulted. GoogleConnection
                // rows are never hard-deleted (only marked disconnected/revoked
                // — see App\Models\GoogleConnection), so nullOnDelete() here is
                // a defensive default, not an expected occurrence.
                $table->foreignId('google_connection_id')->nullable()->constrained('google_connections')->nullOnDelete();

                $table->string('provider', 30)->default('google');
                // Future-proofs a distinct external resource kind (e.g. a
                // future Meet-specific resource) without a redesign — Stage
                // 4B.1 only ever writes 'calendar_event'.
                $table->string('external_resource_type', 30)->default('calendar_event');

                $table->string('state', 20)->default('pending');

                $table->string('provider_event_id')->nullable();
                // Stable, random, independent of any business identifier — see
                // App\Services\Calendar\ConsultancyAppointmentCalendarEventPayloadFactory
                // and App\Services\Calendar\AppointmentCalendarSyncService.
                $table->string('correlation_key', 40);

                $table->string('payload_version', 10)->default('v1');
                $table->string('payload_hash')->nullable();

                // Sync-row-level attempts — genuine provider operations, never
                // queue delivery counts. See AppointmentCalendarSyncService's
                // own docblock for exactly when this increments.
                $table->unsignedInteger('attempt_count')->default(0);

                $table->timestamp('processing_started_at')->nullable();
                $table->timestamp('last_attempted_at')->nullable();
                $table->timestamp('last_success_at')->nullable();
                $table->timestamp('next_retry_at')->nullable();

                // Normalised category (App\Support\Google\CalendarSyncFailureCategory)
                // — never a raw exception message.
                $table->string('failure_category', 40)->nullable();
                $table->string('failure_message')->nullable();

                // The external-uncertainty marker — true only while a network
                // call's outcome is genuinely unknown (no HTTP response at
                // all). See AppointmentCalendarSyncService's reconciliation
                // algorithm.
                $table->boolean('outcome_uncertain')->default(false);

                $table->timestamps();
            });
        }

        if (!Schema::hasIndex('appointment_external_syncs', ['appointment_id', 'provider', 'external_resource_type'])) {
            Schema::table('appointment_external_syncs', function (Blueprint $table) {
                // Explicit short name — Laravel's auto-generated name (82
                // characters) exceeds MySQL's 64-character identifier
                // limit (the same recurring class of bug documented
                // elsewhere in this codebase for
                // billing_entitlement_snapshots' own creation migration).
                $table->unique(['appointment_id', 'provider', 'external_resource_type'], 'appt_ext_syncs_appt_provider_resource_unique');
            });
        }
        if (!Schema::hasIndex('appointment_external_syncs', ['correlation_key'])) {
            Schema::table('appointment_external_syncs', function (Blueprint $table) {
                $table->unique('correlation_key');
            });
        }
        if (!Schema::hasIndex('appointment_external_syncs', ['provider', 'provider_event_id'])) {
            Schema::table('appointment_external_syncs', function (Blueprint $table) {
                // Multiple NULLs are permitted by MySQL's unique index
                // semantics — this only enforces uniqueness once a real
                // event ID is known.
                $table->unique(['provider', 'provider_event_id']);
            });
        }
        if (!Schema::hasIndex('appointment_external_syncs', ['state'])) {
            Schema::table('appointment_external_syncs', function (Blueprint $table) {
                $table->index('state');
            });
        }
        if (!Schema::hasIndex('appointment_external_syncs', ['next_retry_at'])) {
            Schema::table('appointment_external_syncs', function (Blueprint $table) {
                $table->index('next_retry_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_external_syncs');
    }
};
