<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prompt_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('icon')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('prompt_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prompt_category_id')->nullable()->constrained()->onDelete('set null');
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->longText('prompt_text');
            $table->string('module')->nullable();
            $table->string('use_case')->nullable();
            $table->json('variables')->nullable();
            $table->boolean('is_global')->default(true);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_featured')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
            $table->unsignedBigInteger('copied_count')->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['prompt_category_id', 'is_active']);
            $table->index(['is_featured', 'is_active']);
        });

        Schema::create('prompt_favorites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('prompt_template_id')->constrained()->onDelete('cascade');
            $table->timestamps();

            $table->unique(['user_id', 'prompt_template_id']);
        });

        Schema::create('prompt_copy_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('prompt_template_id')->constrained()->onDelete('cascade');
            $table->foreignId('project_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('organization_id')->nullable()->constrained()->onDelete('set null');
            $table->longText('copied_prompt_snapshot')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prompt_copy_logs');
        Schema::dropIfExists('prompt_favorites');
        Schema::dropIfExists('prompt_templates');
        Schema::dropIfExists('prompt_categories');
    }
};
