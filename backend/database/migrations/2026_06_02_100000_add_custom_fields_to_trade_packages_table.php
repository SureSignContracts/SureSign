<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trade_packages', function (Blueprint $table) {
            $table->boolean('is_custom')->default(false)->after('status');
            $table->boolean('created_by_user')->default(false)->after('is_custom');
            $table->string('original_name')->nullable()->after('created_by_user');
            $table->string('source_type')->nullable()->after('original_name'); // 'standard' | 'custom'
        });
    }

    public function down(): void
    {
        Schema::table('trade_packages', function (Blueprint $table) {
            $table->dropColumn(['is_custom', 'created_by_user', 'original_name', 'source_type']);
        });
    }
};
