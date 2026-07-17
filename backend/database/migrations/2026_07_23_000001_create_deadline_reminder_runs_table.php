<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Batch 7: per-organisation, per-local-calendar-day checkpoint for
     * `suresign:send-deadline-reminders`. Prevents the same organisation
     * being processed twice for the same local day, survives application
     * restarts/multiple replicas (unlike a cache-only marker — cache loss
     * would resend every reminder), and lets an incomplete run (crash
     * partway through) be safely resumed rather than silently marked done.
     *
     * `command_key` exists so a second reminder command, if one is ever
     * added, gets its own independent checkpoint rather than colliding
     * with this one on (organization_id, local_date) alone.
     */
    public function up(): void
    {
        Schema::create('deadline_reminder_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('command_key');
            $table->date('local_date');
            $table->string('timezone');
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('failure_message')->nullable();
            $table->unsignedInteger('reminders_evaluated')->default(0);
            $table->unsignedInteger('emails_sent')->default(0);
            $table->timestamps();

            $table->unique(['organization_id', 'command_key', 'local_date'], 'reminder_runs_org_command_date_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deadline_reminder_runs');
    }
};
