<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stable identity for one individual appointment reminder, mirroring
     * DeadlineReminderSend's rationale: a DB-level unique constraint (not
     * application logic) is what actually prevents a retried/overlapping
     * scheduler tick from sending the same reminder twice.
     *
     * `schedule_version` (copied from appointments.schedule_version at send
     * time and part of the unique key) is what makes a reschedule
     * transparently "reset" reminders — a new schedule_version simply
     * doesn't match any existing send row, so the reminder is due again,
     * without ever deleting the old row's audit history.
     */
    public function up(): void
    {
        Schema::create('appointment_reminder_sends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('offset_minutes');
            $table->unsignedInteger('schedule_version');
            $table->timestamp('scheduled_for');
            $table->timestamp('sent_at')->nullable();
            $table->string('status')->default('pending'); // pending | sent | failed
            $table->string('failure_message')->nullable();
            $table->timestamps();

            $table->unique(
                ['appointment_id', 'offset_minutes', 'schedule_version'],
                'appointment_reminder_identity_unique'
            );
            $table->index(['scheduled_for']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_reminder_sends');
    }
};
