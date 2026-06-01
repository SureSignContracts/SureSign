<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('file_uploads', function (Blueprint $table) {
            $table->string('title')->nullable()->after('original_name');
            $table->string('document_type')->nullable()->after('folder_key')
                ->comment('Generic document type label');
            $table->string('contract_document_type')->nullable()->after('document_type')
                ->comment('main_contract | subcontract | consultant_agreement | supplier_agreement | other');
            $table->string('source_type')->nullable()->after('contract_document_type')
                ->comment('Morphable source model class');
            $table->unsignedBigInteger('source_id')->nullable()->after('source_type')
                ->comment('Morphable source model id');
            $table->string('status')->nullable()->default('active')->after('source_id')
                ->comment('active | archived');
        });
    }

    public function down(): void
    {
        Schema::table('file_uploads', function (Blueprint $table) {
            $table->dropColumn([
                'title',
                'document_type',
                'contract_document_type',
                'source_type',
                'source_id',
                'status',
            ]);
        });
    }
};
