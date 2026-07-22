<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Unlike weekly availability / date overrides, blocked periods are
        // fixed UTC instants (leave, internal commitments, etc.) — the
        // `timezone` column is the IANA zone active when the block was
        // created, kept only so it can be redisplayed correctly later even
        // if the staff member's effective timezone setting changes
        // afterwards (same rationale as MeetingMinutes.scheduled_timezone).
        Schema::create('appointment_blocked_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('timezone');
            $table->string('reason')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_blocked_periods');
    }
};
