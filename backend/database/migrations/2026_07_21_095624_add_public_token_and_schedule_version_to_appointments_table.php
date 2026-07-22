<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            // Opaque identifier for public signed cancel/reschedule links —
            // deliberately never the numeric id or the sequential `reference`
            // (APT-000007 is guessable/enumerable), so a signed link leaks no
            // information about how many appointments exist. Nullable only
            // because existing rows predate this column; every new row gets
            // one via Appointment::booted() (see the model).
            $table->string('public_token', 64)->nullable()->unique()->after('reference');

            // Bumped every time the appointment is rescheduled. Serves two
            // purposes at once: (1) folded into appointment_reminder_sends'
            // unique key so a rescheduled appointment's reminders recompute
            // against the new time without deleting the old send history,
            // and (2) reused directly as the ICS SEQUENCE number so calendar
            // clients recognise a re-sent invite as an update, not a
            // duplicate.
            $table->unsignedInteger('schedule_version')->default(0)->after('public_token');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn(['public_token', 'schedule_version']);
        });
    }
};
