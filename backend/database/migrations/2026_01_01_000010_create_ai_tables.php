<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // AI Conversation sessions
        Schema::create('ai_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->nullableMorphs('contextable');
            $table->string('title')->nullable();
            $table->string('type')->default('general')
                ->comment('general, document_draft, meeting_summary, variation_analysis, report, rfi');
            $table->string('status')->default('active')->comment('active, archived');
            $table->integer('token_count')->default(0);
            $table->timestamps();

            $table->index(['user_id', 'project_id']);
        });

        // AI Messages within conversations
        Schema::create('ai_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_conversation_id')->constrained()->cascadeOnDelete();
            $table->string('role')->comment('user, assistant, system');
            $table->longText('content');
            $table->integer('token_count')->default(0);
            $table->json('metadata')->nullable()->comment('model, temperature, etc.');
            $table->timestamps();
        });

        // AI generated outputs (drafts/summaries saved for review)
        Schema::create('ai_outputs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type')
                ->comment('document_draft, meeting_summary, variation_summary, report, extraction');
            $table->string('title');
            $table->longText('content');
            $table->string('status')->default('pending_review')
                ->comment('pending_review, approved, rejected, used');
            $table->string('model_used')->nullable();
            $table->json('source_context')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_outputs');
        Schema::dropIfExists('ai_messages');
        Schema::dropIfExists('ai_conversations');
    }
};
