<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointment_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('public_title')->nullable();
            $table->text('public_description')->nullable();
            $table->text('internal_notes')->nullable();
            $table->unsignedInteger('duration_minutes')->default(30);
            $table->unsignedInteger('buffer_before_minutes')->default(0);
            $table->unsignedInteger('buffer_after_minutes')->default(0);
            $table->unsignedInteger('min_notice_hours')->default(0);
            $table->unsignedInteger('max_advance_days')->default(60);
            $table->boolean('is_public')->default(false);
            $table->boolean('is_active')->default(true);
            $table->string('color', 20)->nullable();
            $table->foreignId('default_assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            // Phase 1 supports only fixed/manual assignment — 'auto' and
            // 'round_robin' are deliberately not in this enum yet (see
            // architecture gate report); adding them later is an additive
            // migration, not a breaking one.
            $table->enum('assignment_mode', ['fixed', 'manual'])->default('manual');
            $table->boolean('requires_confirmation')->default(false);
            $table->enum('meeting_method', ['google_meet', 'teams', 'zoom', 'phone', 'in_person', 'custom', 'tbc'])->default('tbc');
            $table->string('default_location')->nullable();
            $table->unsignedInteger('cancellation_notice_hours')->default(0);
            $table->unsignedInteger('reschedule_notice_hours')->default(0);
            $table->unsignedInteger('display_order')->default(0);
            $table->softDeletes();
            $table->timestamps();

            $table->index(['is_active', 'display_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_types');
    }
};
