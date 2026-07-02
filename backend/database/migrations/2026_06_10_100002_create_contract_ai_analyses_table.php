<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_ai_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('file_upload_id')->nullable()->constrained('file_uploads')->nullOnDelete();
            $table->enum('status', ['pending', 'processing', 'completed', 'failed', 'confirmed', 'cancelled'])->default('pending');
            $table->string('provider', 50)->default('anthropic');
            $table->string('model', 100)->nullable();
            $table->longText('raw_response_json')->nullable();
            $table->longText('confirmed_data_json')->nullable();
            $table->text('error_message')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index(['contract_id', 'status']);
            $table->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_ai_analyses');
    }
};
