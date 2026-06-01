<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('file_uploads', function (Blueprint $table) {
            $table->string('module_key')->nullable()->after('folder_path')
                ->comment('Module: contracts, commercial, payment_applications, variations, notices, adjudication, rfis, meetings, qa_reports, snagging, closeout, site_reports, general');
            $table->string('folder_key')->nullable()->after('module_key')
                ->comment('Mirror of module_key used for display grouping');
        });
    }

    public function down(): void
    {
        Schema::table('file_uploads', function (Blueprint $table) {
            $table->dropColumn(['module_key', 'folder_key']);
        });
    }
};
