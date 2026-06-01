<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suresign_settings', function (Blueprint $table) {
            $table->string('local_export_path')->nullable()->after('timezone')
                ->comment('Optional local mirror path e.g. C:/Users/Admin/Documents/SureSign or ~/Documents/SureSign');
            $table->boolean('local_export_enabled')->default(false)->after('local_export_path');
        });
    }

    public function down(): void
    {
        Schema::table('suresign_settings', function (Blueprint $table) {
            $table->dropColumn(['local_export_path', 'local_export_enabled']);
        });
    }
};
