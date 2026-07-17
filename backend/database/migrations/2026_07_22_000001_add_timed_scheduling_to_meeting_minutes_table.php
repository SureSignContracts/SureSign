<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Additive only — `meeting_date` (DATE) remains the authoritative field
     * for every existing (and every future date-only) meeting. Nothing here
     * touches its type, its data, or any existing row.
     *
     * `starts_at`/`ends_at` (nullable UTC DATETIME) let a meeting optionally
     * carry a real scheduled time. `scheduled_timezone` (nullable IANA
     * string) records which timezone was actually used to build that
     * UTC instant — needed so re-opening a timed meeting to edit it shows
     * back the organiser's original local wall-clock time, even if the
     * organisation's own timezone setting is changed later (see Batch 6
     * report for the full reasoning).
     *
     * No backfill: every existing row gets starts_at/ends_at/
     * scheduled_timezone = null, which is exactly correct — a legacy
     * meeting never had a real scheduled time, so inventing midnight (or
     * any other instant) for it would be a fabrication, not a migration.
     */
    public function up(): void
    {
        Schema::table('meeting_minutes', function (Blueprint $table) {
            $table->timestamp('starts_at')->nullable()->after('meeting_date');
            $table->timestamp('ends_at')->nullable()->after('starts_at');
            $table->string('scheduled_timezone')->nullable()->after('ends_at');
        });
    }

    public function down(): void
    {
        Schema::table('meeting_minutes', function (Blueprint $table) {
            $table->dropColumn(['starts_at', 'ends_at', 'scheduled_timezone']);
        });
    }
};
