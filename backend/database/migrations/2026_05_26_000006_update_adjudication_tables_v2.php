<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add archive fields to adjudication_cases
        Schema::table('adjudication_cases', function (Blueprint $table) {
            $table->foreignId('archived_by')->nullable()->after('created_by')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('archived_at')->nullable()->after('metadata');
        });

        // Add category + tags to adjudication_documents
        Schema::table('adjudication_documents', function (Blueprint $table) {
            $table->string('category', 100)->nullable()->after('document_type');
            $table->json('tags')->nullable()->after('category');
        });
    }

    public function down(): void
    {
        Schema::table('adjudication_cases', function (Blueprint $table) {
            $table->dropForeign(['archived_by']);
            $table->dropColumn(['archived_by', 'archived_at']);
        });
        Schema::table('adjudication_documents', function (Blueprint $table) {
            $table->dropColumn(['category', 'tags']);
        });
    }
};
