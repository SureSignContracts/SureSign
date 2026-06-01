<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('adjudication_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->foreignId('project_id')->constrained()->onDelete('cascade');
            $table->foreignId('contract_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('payment_application_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('variation_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
            $table->string('case_number')->unique();
            $table->string('title');
            $table->string('dispute_type');
            $table->string('claimant_name');
            $table->string('respondent_name');
            $table->decimal('claim_amount', 15, 2)->nullable();
            $table->string('currency')->default('GBP');
            $table->text('summary')->nullable();
            $table->string('status')->default('draft');
            $table->string('current_step')->default('notice_of_dispute');
            $table->date('notice_of_dispute_date')->nullable();
            $table->date('notice_of_adjudication_date')->nullable();
            $table->date('referral_due_date')->nullable();
            $table->date('response_due_date')->nullable();
            $table->date('decision_due_date')->nullable();
            $table->date('decision_received_date')->nullable();
            $table->date('enforcement_deadline')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['project_id', 'status']);
            $table->index(['organization_id', 'project_id']);
        });

        Schema::create('adjudication_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('adjudication_case_id')->constrained()->onDelete('cascade');
            $table->string('step_key');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status')->default('pending');
            $table->date('due_date')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->onDelete('set null');
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['adjudication_case_id', 'step_key']);
        });

        Schema::create('adjudication_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->foreignId('project_id')->constrained()->onDelete('cascade');
            $table->foreignId('adjudication_case_id')->constrained()->onDelete('cascade');
            $table->foreignId('document_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->onDelete('set null');
            $table->string('title');
            $table->string('document_type');
            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->unsignedSmallInteger('version')->default(1);
            $table->string('status')->default('draft');
            $table->string('source_step')->nullable();
            $table->boolean('ai_generated')->default(false);
            $table->timestamps();

            $table->index(['adjudication_case_id', 'document_type']);
        });

        Schema::create('adjudication_deadlines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->foreignId('project_id')->constrained()->onDelete('cascade');
            $table->foreignId('adjudication_case_id')->constrained()->onDelete('cascade');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('deadline_type');
            $table->date('due_date');
            $table->string('status')->default('upcoming');
            $table->boolean('reminder_sent')->default(false);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['adjudication_case_id', 'due_date']);
            $table->index(['project_id', 'due_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('adjudication_deadlines');
        Schema::dropIfExists('adjudication_documents');
        Schema::dropIfExists('adjudication_steps');
        Schema::dropIfExists('adjudication_cases');
    }
};
