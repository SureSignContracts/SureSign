<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            // Belongs to a Contract OR a Trade Package, never neither —
            // validated in the controller, not enforced by the DB. Mirrors
            // delay_events / contract_risks.
            $table->foreignId('contract_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('trade_package_id')->nullable()->constrained('trade_packages')->nullOnDelete();

            // Points at an existing uploaded/generated Document once one is
            // linked — this table never stores files itself.
            $table->foreignId('document_id')->nullable()->constrained('documents')->nullOnDelete();

            $table->string('category')->default('other')
                ->comment('method_statement, rams, itp, lift_plan, temporary_works, coshh, permit, installation_procedure, manufacturer_instruction, task_briefing, other');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status')->default('required')
                ->comment('required, pending, submitted, under_review, approved, rejected, expired, superseded');
            $table->string('revision')->nullable();

            $table->string('submitted_by')->nullable();
            $table->string('reviewed_by')->nullable();
            $table->string('approved_by')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('approved_at')->nullable();

            $table->date('due_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->text('notes')->nullable();

            // AI extraction — no destination existed for these fields before
            // this table (Sprint 6B's SubcontractAnalysisPrompt deliberately
            // excluded them for exactly that reason). Nullable/unused until
            // the AI extraction phase is built.
            $table->boolean('is_ai_extracted')->default(false);
            $table->unsignedBigInteger('source_ai_analysis_id')->nullable();
            $table->unsignedBigInteger('source_document_id')->nullable();
            $table->json('extracted_data_json')->nullable();

            $table->foreignId('created_by')->constrained('users');

            $table->softDeletes();
            $table->timestamps();

            $table->index(['project_id', 'status']);
            $table->index(['project_id', 'category']);
            $table->index('trade_package_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_documents');
    }
};
