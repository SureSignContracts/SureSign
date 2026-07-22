<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->unique();
            $table->foreignId('appointment_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('linked_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('company_name')->nullable();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();

            $table->string('attendee_name');
            $table->string('attendee_email');
            $table->string('attendee_phone')->nullable();
            $table->string('attendee_job_title')->nullable();
            $table->string('attendee_company')->nullable();
            $table->string('attendee_timezone');

            // UTC instant + the IANA zone it was booked in — same trio
            // pattern as meeting_minutes.starts_at/ends_at/scheduled_timezone.
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('booking_timezone');

            $table->enum('status', [
                'requested', 'pending_confirmation', 'confirmed',
                'declined', 'cancelled', 'completed', 'no_show',
            ])->default('requested');

            $table->string('booking_source')->default('admin_created');
            $table->enum('meeting_method', ['google_meet', 'teams', 'zoom', 'phone', 'in_person', 'custom', 'tbc'])->default('tbc');
            $table->string('meeting_url')->nullable();
            $table->string('location')->nullable();

            $table->text('attendee_message')->nullable();
            $table->text('internal_notes')->nullable();
            $table->string('cancellation_reason')->nullable();
            $table->string('reschedule_reason')->nullable();
            $table->text('completion_notes')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['assigned_user_id', 'starts_at']);
            $table->index(['organization_id', 'status']);
            $table->index(['status', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
