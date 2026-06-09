<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_number_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('package_id')->nullable()->constrained('trade_packages')->nullOnDelete();
            $table->string('document_type', 10); // e.g. RFI, VAR, NOT, CON
            $table->unsignedInteger('current_sequence')->default(0);
            $table->timestamps();

            $table->unique(['project_id', 'package_id', 'document_type'], 'doc_seq_unique');
            $table->index(['project_id', 'package_id']);
        });

        Schema::create('document_register', function (Blueprint $table) {
            $table->id();
            $table->string('document_number', 60)->unique();
            $table->string('title');
            $table->string('document_type', 10);
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('package_id')->nullable()->constrained('trade_packages')->nullOnDelete();
            $table->foreignId('file_upload_id')->nullable()->constrained('file_uploads')->nullOnDelete();
            $table->timestamps();

            $table->index(['project_id', 'package_id']);
            $table->index('document_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_register');
        Schema::dropIfExists('document_number_sequences');
    }
};
