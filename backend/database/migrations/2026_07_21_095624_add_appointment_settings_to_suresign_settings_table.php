<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Appointments Phase 4 — platform-wide settings. Deliberately minimal:
     * sender identity, support contact, and branding are already covered by
     * existing suresign_settings columns and are reused as-is, not
     * duplicated here.
     */
    public function up(): void
    {
        Schema::table('suresign_settings', function (Blueprint $table) {
            $table->boolean('appointment_reminders_enabled')->default(true);
            // Minutes before the appointment, e.g. [1440, 60] for 24h + 1h.
            $table->json('appointment_reminder_offsets_minutes')->nullable();
            $table->unsignedInteger('appointment_cancel_link_ttl_hours')->default(720);
            $table->unsignedInteger('appointment_reschedule_link_ttl_hours')->default(720);
            $table->unsignedInteger('appointment_cancellation_cutoff_hours')->default(2);
            $table->unsignedInteger('appointment_reschedule_cutoff_hours')->default(2);
            $table->boolean('appointment_ics_enabled')->default(true);
            $table->text('appointment_default_meeting_instructions')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('suresign_settings', function (Blueprint $table) {
            $table->dropColumn([
                'appointment_reminders_enabled',
                'appointment_reminder_offsets_minutes',
                'appointment_cancel_link_ttl_hours',
                'appointment_reschedule_link_ttl_hours',
                'appointment_cancellation_cutoff_hours',
                'appointment_reschedule_cutoff_hours',
                'appointment_ics_enabled',
                'appointment_default_meeting_instructions',
            ]);
        });
    }
};
