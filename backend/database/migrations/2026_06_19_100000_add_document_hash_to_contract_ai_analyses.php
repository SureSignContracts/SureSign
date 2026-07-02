<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contract_ai_analyses', function (Blueprint $table) {
            // SHA-256 of the extracted contract text. Lets us reuse a prior completed
            // analysis for identical document content instead of re-charging Claude.
            $table->string('document_hash', 64)->nullable()->after('model');
            $table->index(['document_hash', 'model', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('contract_ai_analyses', function (Blueprint $table) {
            $table->dropIndex(['document_hash', 'model', 'status']);
            $table->dropColumn('document_hash');
        });
    }
};
