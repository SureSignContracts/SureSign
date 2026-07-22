<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Weekly recurring availability — local wall-clock time in the
        // staff member's CURRENT effective timezone (re-resolved on every
        // read, not stored). Multiple rows per user/weekday support more
        // than one window (e.g. 09:00-12:00 and 13:00-17:00).
        Schema::create('appointment_availabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday'); // Carbon::SUNDAY (0) .. SATURDAY (6)
            $table->time('start_time');
            $table->time('end_time');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['user_id', 'weekday']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_availabilities');
    }
};
