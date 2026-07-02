<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suresign_settings', function (Blueprint $table) {
            $table->boolean('ai_enabled')->default(false)->after('local_export_enabled');
            $table->string('ai_provider', 50)->default('anthropic')->after('ai_enabled');
            $table->string('ai_model', 100)->default('claude-3-5-sonnet-latest')->after('ai_provider');
            $table->text('anthropic_api_key')->nullable()->after('ai_model');
        });
    }

    public function down(): void
    {
        Schema::table('suresign_settings', function (Blueprint $table) {
            $table->dropColumn(['ai_enabled', 'ai_provider', 'ai_model', 'anthropic_api_key']);
        });
    }
};
