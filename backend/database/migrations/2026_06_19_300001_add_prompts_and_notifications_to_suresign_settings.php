<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suresign_settings', function (Blueprint $table) {
            $table->boolean('prompts_enabled')->default(true)->after('ai_enabled');
            $table->json('notification_settings')->nullable()->after('prompts_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('suresign_settings', function (Blueprint $table) {
            $table->dropColumn(['prompts_enabled', 'notification_settings']);
        });
    }
};
