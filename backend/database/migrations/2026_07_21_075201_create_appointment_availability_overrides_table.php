<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Date-specific overrides — take full precedence over the weekly
        // schedule for that local date. A date is represented either by a
        // single is_unavailable=true row (whole day off) or by one-or-more
        // window rows (start_time/end_time) — never both at once for the
        // same user/date (enforced in AppointmentAvailabilityService, not
        // the database). Same local-wall-clock-in-current-effective-
        // timezone rule as appointment_availabilities — no timezone column.
        Schema::create('appointment_availability_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('local_date');
            $table->boolean('is_unavailable')->default(false);
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'local_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_availability_overrides');
    }
};
