<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suresign_settings', function (Blueprint $table) {
            $table->string('admin_email')->nullable()->after('email_reply_to');
        });
    }

    public function down(): void
    {
        Schema::table('suresign_settings', function (Blueprint $table) {
            $table->dropColumn('admin_email');
        });
    }
};
