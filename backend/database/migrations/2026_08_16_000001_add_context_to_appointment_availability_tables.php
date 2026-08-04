<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Consultancy Live Booking Upgrade, Stage 1 — introduces the availability
// "context" dimension so Consultancy and ordinary Appointments (including
// Book a Demo) can each have their own weekly schedule and date overrides
// for the same consultant, without a second scheduling engine. See
// internal-docs/commercial/consultancy-live-booking-phase-0-architecture-review.md
// §9. `appointment_blocked_periods` deliberately does NOT gain this column —
// a blocked period represents real consultant unavailability and must
// continue to apply to every context for that consultant (approved Stage 1
// decision).
//
// Safe-migration sequence (mirrors the consultation_enquiries engagement
// fields migration's own precedent): add nullable -> backfill every existing
// row to the canonical 'appointments' context (App\Support\Appointments\AvailabilityContext::APPOINTMENTS)
// -> only then make the column NOT NULL with that same default. No existing
// row is ever deleted or reinterpreted as Consultancy.
return new class extends Migration
{
    /**
     * Every step below is guarded to be safely re-runnable. MySQL DDL
     * (ADD/DROP COLUMN, ADD/DROP INDEX) auto-commits and is never rolled
     * back by Laravel's migration wrapper — a container restart or crash
     * partway through this migration (observed in a local dev environment:
     * the column had been added and backfilled, but the index swap below
     * had not happened yet, while the migrations table still recorded the
     * whole migration as not-yet-run) otherwise leaves it permanently
     * unable to complete, since the first statement it retries would
     * always fail with "column/index already exists". Guarding each step
     * individually lets the migration resume from wherever it actually
     * left off, with no data ever touched or reinterpreted.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('appointment_availabilities', 'context')) {
            Schema::table('appointment_availabilities', function (Blueprint $table) {
                $table->string('context', 20)->nullable()->after('user_id');
            });
        }
        if (!Schema::hasColumn('appointment_availability_overrides', 'context')) {
            Schema::table('appointment_availability_overrides', function (Blueprint $table) {
                $table->string('context', 20)->nullable()->after('user_id');
            });
        }

        DB::table('appointment_availabilities')->whereNull('context')->update(['context' => 'appointments']);
        DB::table('appointment_availability_overrides')->whereNull('context')->update(['context' => 'appointments']);

        Schema::table('appointment_availabilities', function (Blueprint $table) {
            $table->string('context', 20)->nullable(false)->default('appointments')->change();
        });
        Schema::table('appointment_availability_overrides', function (Blueprint $table) {
            $table->string('context', 20)->nullable(false)->default('appointments')->change();
        });

        // The new index must be created BEFORE the old one is dropped:
        // `user_id` is a foreign key, and MySQL requires SOME index
        // covering it at all times — dropping the only covering index
        // first fails with "needed in a foreign key constraint" (found
        // running this migration locally).
        if (!Schema::hasIndex('appointment_availabilities', ['user_id', 'context', 'weekday'])) {
            Schema::table('appointment_availabilities', function (Blueprint $table) {
                $table->index(['user_id', 'context', 'weekday']);
            });
        }
        if (Schema::hasIndex('appointment_availabilities', ['user_id', 'weekday'])) {
            Schema::table('appointment_availabilities', function (Blueprint $table) {
                $table->dropIndex(['user_id', 'weekday']);
            });
        }

        if (!Schema::hasIndex('appointment_availability_overrides', ['user_id', 'context', 'local_date'])) {
            Schema::table('appointment_availability_overrides', function (Blueprint $table) {
                // Explicit short name — Laravel's auto-generated name
                // (`appointment_availability_overrides_user_id_context_local_date_index`,
                // 69 characters) exceeds MySQL's 64-character identifier
                // limit (found running this migration locally — the same
                // class of bug documented elsewhere in this codebase for
                // billing_entitlement_snapshots' own creation migration).
                $table->index(['user_id', 'context', 'local_date'], 'avail_overrides_user_context_date_idx');
            });
        }
        if (Schema::hasIndex('appointment_availability_overrides', ['user_id', 'local_date'])) {
            Schema::table('appointment_availability_overrides', function (Blueprint $table) {
                $table->dropIndex(['user_id', 'local_date']);
            });
        }
    }

    /**
     * Rollback drops the context column entirely, collapsing every row back
     * to a single undifferentiated schedule per user — this is lossy for any
     * Consultancy-context row created after this migration ran (it becomes
     * indistinguishable from an Appointments-context row once the column is
     * gone), though no row itself is deleted. Documented here rather than
     * silently reversed: do not roll back this migration once Consultancy
     * availability rows exist in a populated environment without first
     * exporting them.
     */
    public function down(): void
    {
        Schema::table('appointment_availabilities', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'context', 'weekday']);
            $table->dropColumn('context');
            $table->index(['user_id', 'weekday']);
        });
        Schema::table('appointment_availability_overrides', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'context', 'local_date']);
            $table->dropColumn('context');
            $table->index(['user_id', 'local_date']);
        });
    }
};
