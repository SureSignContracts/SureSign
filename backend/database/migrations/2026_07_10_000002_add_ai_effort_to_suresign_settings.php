<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suresign_settings', function (Blueprint $table) {
            $table->string('ai_effort')->nullable()->after('ai_model');
        });
    }

    public function down(): void
    {
        Schema::table('suresign_settings', function (Blueprint $table) {
            $table->dropColumn('ai_effort');
        });
    }
};
