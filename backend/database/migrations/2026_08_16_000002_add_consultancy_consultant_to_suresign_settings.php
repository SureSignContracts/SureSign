<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Consultancy Live Booking Upgrade, Stage 1 — the single authoritative
// Consultancy consultant setting (App\Services\Consultancy\ConsultancyConsultantResolver
// is the only place it is ever read from). Deliberately NOT stored on
// consultancy_services/appointment_types — see the Phase 0 report's
// "Consultant configuration" section for why a per-service copy would
// create two competing sources of truth.
return new class extends Migration
{
    public function up(): void
    {
        // Guarded: found running this migration in a local dev environment
        // where the column (and its foreign key) had already been added by
        // an earlier, interrupted run of this same migration, while the
        // migrations table still recorded it as not-yet-run — see
        // 2026_08_16_000001_add_context_to_appointment_availability_tables.php's
        // own docblock for the full explanation of why this class of issue
        // occurs and why every step here is guarded rather than assumed.
        if (!Schema::hasColumn('suresign_settings', 'consultancy_consultant_user_id')) {
            Schema::table('suresign_settings', function (Blueprint $table) {
                $table->foreignId('consultancy_consultant_user_id')->nullable()->after('id')->constrained('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::table('suresign_settings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('consultancy_consultant_user_id');
        });
    }
};
