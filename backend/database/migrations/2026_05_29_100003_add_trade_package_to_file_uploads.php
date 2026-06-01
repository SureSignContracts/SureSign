<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('file_uploads', function (Blueprint $table) {
            $table->unsignedBigInteger('trade_package_id')->nullable()->after('source_id')
                ->comment('Trade package this file belongs to (subcontract workflow)');
            $table->string('trade_package_folder_key')->nullable()->after('trade_package_id')
                ->comment('Standard folder key within the trade package: tender_enquiry, drawings, etc.');

            $table->foreign('trade_package_id')->references('id')->on('trade_packages')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('file_uploads', function (Blueprint $table) {
            $table->dropForeign(['trade_package_id']);
            $table->dropColumn(['trade_package_id', 'trade_package_folder_key']);
        });
    }
};
