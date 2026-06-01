<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Document Templates
        Schema::create('document_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('category')
                ->comment('contract, payment, variation, rfi, meeting, report, eot, site, other');
            $table->string('type')->default('pdf')->comment('pdf, docx, html');
            $table->text('description')->nullable();
            $table->longText('content')->nullable()->comment('HTML/template content');
            $table->json('variables')->nullable()->comment('Available placeholder variables');
            $table->boolean('is_global')->default(false)->comment('Available to all organizations');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Documents
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('template_id')->nullable()->constrained('document_templates')->nullOnDelete();
            $table->nullableMorphs('documentable');
            $table->string('title');
            $table->string('type')
                ->comment('contract, payment_app, variation, rfi, site_instruction, meeting_minutes, report, eot, other');
            $table->string('category')->nullable();
            $table->string('reference_number')->nullable();
            $table->string('status')->default('draft')
                ->comment('draft, pending_approval, approved, issued, superseded, archived');
            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->integer('version')->default(1);
            $table->boolean('ai_generated')->default(false);
            $table->json('template_data')->nullable()->comment('Data used to generate document');
            $table->softDeletes();
            $table->timestamps();

            $table->index(['project_id', 'type', 'status']);
        });

        // Document Versions
        Schema::create('document_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users');
            $table->integer('version');
            $table->string('file_path');
            $table->string('file_name');
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('mime_type')->nullable();
            $table->text('change_notes')->nullable();
            $table->timestamps();
        });

        // Document Approvals
        Schema::create('document_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('approver_id')->constrained('users');
            $table->string('status')->default('pending')
                ->comment('pending, approved, rejected');
            $table->text('comments')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });

        // File uploads (project file library)
        Schema::create('file_uploads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->constrained('users');
            $table->nullableMorphs('attachable');
            $table->string('original_name');
            $table->string('stored_name');
            $table->string('file_path');
            $table->string('mime_type');
            $table->unsignedBigInteger('file_size');
            $table->string('folder_path')->nullable()->comment('Virtual folder structure');
            $table->string('disk')->default('local');
            $table->timestamps();

            $table->index(['project_id', 'folder_path']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('file_uploads');
        Schema::dropIfExists('document_approvals');
        Schema::dropIfExists('document_versions');
        Schema::dropIfExists('documents');
        Schema::dropIfExists('document_templates');
    }
};
