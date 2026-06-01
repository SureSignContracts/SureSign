<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // RFIs
        Schema::create('rfis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->integer('rfi_number');
            $table->string('subject');
            $table->text('query');
            $table->text('response')->nullable();
            $table->string('status')->default('open')
                ->comment('open, pending_response, responded, closed');
            $table->string('priority')->default('normal')
                ->comment('low, normal, high, urgent');
            $table->date('date_raised');
            $table->date('response_required_by')->nullable();
            $table->date('responded_at')->nullable();
            $table->boolean('programme_impact')->default(false);
            $table->integer('programme_impact_days')->default(0);
            $table->boolean('cost_impact')->default(false);
            $table->decimal('cost_impact_amount', 15, 2)->default(0);
            $table->softDeletes();
            $table->timestamps();

            $table->index(['project_id', 'status']);
        });

        // Site Instructions
        Schema::create('site_instructions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users');
            $table->integer('instruction_number');
            $table->string('title');
            $table->text('description');
            $table->string('type')
                ->comment('variation, safety, quality, design, general, urgent');
            $table->string('issued_to')->nullable();
            $table->date('issued_date');
            $table->date('compliance_by_date')->nullable();
            $table->string('status')->default('issued')
                ->comment('issued, acknowledged, complied, disputed');
            $table->boolean('cost_impact')->default(false);
            $table->boolean('programme_impact')->default(false);
            $table->softDeletes();
            $table->timestamps();

            $table->index(['project_id', 'status']);
        });

        // Site Diaries
        Schema::create('site_diaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users');
            $table->date('diary_date');
            $table->string('weather')->nullable();
            $table->integer('workers_on_site')->default(0);
            $table->text('works_carried_out')->nullable();
            $table->text('delays_and_disruptions')->nullable();
            $table->text('visitors')->nullable();
            $table->text('health_safety_observations')->nullable();
            $table->text('materials_delivered')->nullable();
            $table->text('notes')->nullable();
            $table->string('status')->default('draft')->comment('draft, submitted, approved');
            $table->softDeletes();
            $table->timestamps();

            $table->index(['project_id', 'diary_date']);
        });

        // Meeting Minutes
        Schema::create('meeting_minutes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users');
            $table->integer('meeting_number');
            $table->string('title');
            $table->string('type')->default('progress')
                ->comment('progress, design, commercial, safety, subcontractor, other');
            $table->date('meeting_date');
            $table->string('location')->nullable();
            $table->json('attendees')->nullable();
            $table->text('agenda')->nullable();
            $table->text('minutes')->nullable();
            $table->json('action_items')->nullable();
            $table->text('ai_summary')->nullable();
            $table->string('status')->default('draft')
                ->comment('draft, issued, approved');
            $table->softDeletes();
            $table->timestamps();

            $table->index(['project_id', 'meeting_date']);
        });

        // Delay Notices / EOT Requests
        Schema::create('eot_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users');
            $table->integer('eot_number');
            $table->string('title');
            $table->text('grounds');
            $table->integer('days_claimed');
            $table->integer('days_granted')->nullable();
            $table->date('event_date');
            $table->date('notice_date');
            $table->date('assessment_date')->nullable();
            $table->string('status')->default('submitted')
                ->comment('submitted, under_review, granted, partially_granted, rejected, disputed');
            $table->boolean('loss_and_expense_claim')->default(false);
            $table->decimal('loss_and_expense_amount', 15, 2)->default(0);
            $table->text('notes')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eot_requests');
        Schema::dropIfExists('meeting_minutes');
        Schema::dropIfExists('site_diaries');
        Schema::dropIfExists('site_instructions');
        Schema::dropIfExists('rfis');
    }
};
