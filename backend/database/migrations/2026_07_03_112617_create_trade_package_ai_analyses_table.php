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
        Schema::create('trade_package_ai_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trade_package_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('file_upload_id')->nullable()->constrained('file_uploads')->nullOnDelete();
            $table->enum('status', ['pending', 'processing', 'completed', 'failed', 'confirmed', 'cancelled'])->default('pending');
            $table->string('provider', 50)->default('anthropic');
            $table->string('model', 100)->nullable();
            $table->string('document_hash', 64)->nullable();
            $table->string('summary', 1000)->nullable();
            $table->longText('raw_response_json')->nullable();
            $table->longText('raw_response_text')->nullable();
            $table->string('stop_reason', 32)->nullable();
            $table->longText('confirmed_data_json')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedBigInteger('tokens_input')->nullable();
            $table->unsignedBigInteger('tokens_output')->nullable();
            $table->decimal('estimated_cost', 10, 6)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index(['trade_package_id', 'status']);
            $table->index(['organization_id', 'created_at']);
            $table->index(['document_hash', 'model', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trade_package_ai_analyses');
    }
};
