<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Notifications
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        // Audit logs
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event')
                ->comment('created, updated, deleted, login, logout, exported, generated, approved');
            $table->string('auditable_type')->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->string('url')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'event']);
            $table->index(['auditable_type', 'auditable_id']);
            $table->index(['organization_id', 'created_at']);
        });

        // Project folder structure definitions
        Schema::create('project_folders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('path');
            $table->string('folder_number')->nullable();
            $table->integer('order')->default(0);
            $table->boolean('is_auto_created')->default(false);
            $table->timestamps();

            $table->unique(['project_id', 'path']);
        });

        // Reports
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users');
            $table->string('title');
            $table->string('type')
                ->comment('progress, commercial, monthly, weekly, final, custom');
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->longText('content')->nullable();
            $table->string('status')->default('draft')->comment('draft, issued, archived');
            $table->boolean('ai_assisted')->default(false);
            $table->string('file_path')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['project_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
        Schema::dropIfExists('project_folders');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('notifications');
    }
};
