<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Stage 4B.2 (Google Meet Conference Generation) — extends the existing
// appointment_external_syncs row rather than introducing a second sync
// table. Meet is requested as part of the SAME Calendar event creation
// (Google Meet rides on conferenceData, not a separate resource), so it
// belongs on the same row that already tracks that event's lifecycle —
// see internal-docs/super-admin/google-integration.md's Stage 4B.2
// section. No new Calendar event ID/correlation key/sync state/Google
// connection ID/Appointment ID column is introduced — all five already
// exist on this table from Stage 4B.1 and are reused as-is.
//
// Guarded per this repository's known interrupted-multi-statement/
// 64-character-identifier-limit migration handling (see
// 2026_08_16_000001_add_context_to_appointment_availability_tables.php and
// 2026_08_20_000001_create_appointment_external_syncs_table.php for the
// two prior occurrences of this exact class of issue).
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('appointment_external_syncs', 'meeting_state')) {
            Schema::table('appointment_external_syncs', function (Blueprint $table) {
                // Independent from `state` (Calendar truth) — see
                // App\Support\Google\MeetConferenceState. Deliberately a
                // separate column, never overloaded onto CalendarSyncState.
                $table->string('meeting_state', 20)->default('not_requested')->after('state');
            });
        }

        if (!Schema::hasColumn('appointment_external_syncs', 'provider_conference_id')) {
            Schema::table('appointment_external_syncs', function (Blueprint $table) {
                $table->string('provider_conference_id')->nullable()->after('provider_event_id');
            });
        }

        if (!Schema::hasColumn('appointment_external_syncs', 'provider_conference_type')) {
            Schema::table('appointment_external_syncs', function (Blueprint $table) {
                // e.g. 'hangoutsMeet' — Google's own ConferenceSolutionKey.type value.
                $table->string('provider_conference_type', 40)->nullable()->after('provider_conference_id');
            });
        }

        if (!Schema::hasColumn('appointment_external_syncs', 'meeting_join_url')) {
            Schema::table('appointment_external_syncs', function (Blueprint $table) {
                // Only ever a provider-normalised, scheme/host-validated
                // Google Meet URL (see GoogleCalendarProvider's
                // normalisation) — never raw/unvalidated provider text.
                $table->string('meeting_join_url')->nullable()->after('provider_conference_type');
            });
        }

        if (!Schema::hasColumn('appointment_external_syncs', 'meeting_created_at')) {
            Schema::table('appointment_external_syncs', function (Blueprint $table) {
                $table->timestamp('meeting_created_at')->nullable()->after('last_success_at');
            });
        }

        if (!Schema::hasColumn('appointment_external_syncs', 'meeting_failure_category')) {
            Schema::table('appointment_external_syncs', function (Blueprint $table) {
                // Normalised category only (App\Support\Google\CalendarSyncFailureCategory)
                // — never a raw exception message. Separate from the
                // existing Calendar `failure_category`, since a Calendar
                // event can succeed while Meet fails independently.
                $table->string('meeting_failure_category', 40)->nullable()->after('failure_message');
            });
        }

        if (!Schema::hasIndex('appointment_external_syncs', ['meeting_state'])) {
            Schema::table('appointment_external_syncs', function (Blueprint $table) {
                $table->index('meeting_state');
            });
        }
    }

    public function down(): void
    {
        Schema::table('appointment_external_syncs', function (Blueprint $table) {
            if (Schema::hasIndex('appointment_external_syncs', ['meeting_state'])) {
                $table->dropIndex(['meeting_state']);
            }
            $columns = [
                'meeting_state', 'provider_conference_id', 'provider_conference_type',
                'meeting_join_url', 'meeting_created_at', 'meeting_failure_category',
            ];
            foreach ($columns as $column) {
                if (Schema::hasColumn('appointment_external_syncs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
