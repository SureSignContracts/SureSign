<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SureSign Feature Availability, Phase A — the persistence foundation for a
 * centralized, Super-Admin-controlled Active/Maintenance/Coming-Soon switch
 * per registered page/module (App\Support\FeatureAvailability\
 * FeatureAvailabilityRegistry).
 *
 * One row per feature key that has ever had a NON-default override — the
 * registry (not this table) is the catalogue of what features exist.
 * `feature_key` is validated in the application layer against
 * FeatureAvailabilityRegistry::ALL, matching this codebase's existing
 * convention for other enum-constrained string columns (e.g.
 * pricing_plan_entitlements.feature_key).
 *
 * Deliberately global — NO organization_id/project_id/tenant foreign key of
 * any kind. This is platform-wide operational configuration, not
 * tenant-scoped data; see FeatureAvailabilityService for the read path that
 * enforces "no row = Active" for every organisation identically.
 *
 * Pure additive migration: no existing table is touched, no seed rows are
 * inserted. Immediately after this runs, the table is empty and therefore
 * every registered feature resolves to Active — every existing SureSign
 * workflow behaves exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feature_availabilities', function (Blueprint $table) {
            $table->id();

            $table->string('feature_key', 60)->unique();

            // active | maintenance | coming_soon — validated against
            // App\Support\FeatureAvailability\FeatureAvailabilityStatus::ALL
            // in the application layer, not a DB-level enum (matches this
            // codebase's existing convention elsewhere).
            $table->string('status', 20)->default('active');

            $table->text('message')->nullable();

            // Informational only in V1 — no scheduler ever reads this to
            // auto-restore Active. UTC, like every other instant column in
            // this codebase.
            $table->timestamp('available_at')->nullable();

            // Who made the most recent change — nullOnDelete (not cascade)
            // so the audit/display value of this row outlives the acting
            // user's own account, matching this codebase's existing
            // convention for "who did this" columns.
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_availabilities');
    }
};
