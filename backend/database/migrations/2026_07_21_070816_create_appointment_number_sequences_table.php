<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Single-row global counter (no project scoping — appointments
        // aren't necessarily project-scoped), locked for update the same
        // way DocumentNumberSequence is, so AppointmentReferenceService can
        // generate APT-000001-style references atomically.
        Schema::create('appointment_number_sequences', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('current_sequence')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_number_sequences');
    }
};
