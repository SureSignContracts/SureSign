<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('delay_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            // A delay event belongs to a contract OR a trade package (subcontract),
            // never neither — validated in the controller, not enforced by the DB.
            $table->foreignId('contract_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('trade_package_id')->nullable()->constrained('trade_packages')->nullOnDelete();

            // Optional links — a delay event may explain a variation and/or
            // affect a specific programme milestone/activity.
            $table->foreignId('variation_id')->nullable()->constrained('variations')->nullOnDelete();
            $table->foreignId('affected_milestone_id')->nullable()->constrained('contract_programme_milestones')->nullOnDelete();

            $table->foreignId('created_by')->constrained('users');

            $table->integer('event_number');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('cause_category')->default('other')
                ->comment('weather, employer_instruction, utility, access, design, third_party, other');

            $table->date('date_occurred');

            // Basic notice marker for 5B — a full Delay Notice record (with
            // document generation / notice history) can be added later if
            // required; for now this is enough to know a notice was given.
            $table->date('date_notified')->nullable();
            $table->string('notified_by')->nullable();

            $table->integer('estimated_delay_days')->nullable();
            $table->string('status')->default('open')
                ->comment('open, under_assessment, closed, rejected');
            $table->text('notes')->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->index(['project_id', 'status']);
            $table->index('contract_id');
            $table->index('trade_package_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('delay_events');
    }
};
