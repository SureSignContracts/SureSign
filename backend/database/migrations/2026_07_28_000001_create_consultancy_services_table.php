<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Consultancy Phase C1 — the commercial/presentation catalogue layer that
// wraps an existing appointment_types row. Every scheduling field (duration,
// buffers, notice/advance windows, assignment mode, default assignee) stays
// on appointment_types — this table only owns fields that don't already
// exist there. See internal-docs/commercial/suresign-consultancy-specification-v1.md.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consultancy_services', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->foreignId('appointment_type_id')->unique()->constrained('appointment_types')->cascadeOnDelete();
            $table->string('display_name');
            $table->text('description')->nullable();
            $table->text('public_description')->nullable();
            $table->boolean('enabled')->default(false);
            $table->boolean('publicly_bookable')->default(false);
            $table->boolean('available_to_existing_customers')->default(false);
            // Minor units (matches App\Support\Billing\Money's convention) —
            // display-only in Phase C1/C2, no code path treats this as an
            // amount owed until Phase C3.
            $table->unsignedInteger('price_minor_units')->nullable();
            $table->string('currency', 3)->default('GBP');
            $table->unsignedInteger('display_order')->default(0);
            $table->boolean('is_introductory')->default(false);
            // Reserved — deliberately unenforced in C1 (see spec's Quick
            // Consultation Rules section). No code reads this yet.
            $table->unsignedInteger('max_bookings_per_day')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['enabled', 'display_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consultancy_services');
    }
};
