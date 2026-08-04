<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Consultancy Phase C1 — the structured enquiry fields captured alongside a
// consultation booking (authenticated or public). One row per appointment.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consultation_enquiries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id')->unique()->constrained('appointments')->cascadeOnDelete();
            $table->foreignId('consultancy_service_id')->constrained('consultancy_services')->cascadeOnDelete();
            $table->string('title');
            $table->text('description');
            // Free text, not a hardcoded enum — project stage / contract form
            // vocabularies are expected to evolve without a migration.
            $table->string('project_stage')->nullable();
            $table->string('contract_form')->nullable();
            $table->text('preferred_outcome')->nullable();
            $table->enum('submitted_by', ['public', 'authenticated']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consultation_enquiries');
    }
};
