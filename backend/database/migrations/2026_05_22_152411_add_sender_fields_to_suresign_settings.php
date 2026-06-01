<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('suresign_settings', function (Blueprint $table) {
            $table->string('email_sender_email')->nullable()->after('email_reply_to');
            $table->string('email_sender_name')->nullable()->default('SureSign')->after('email_sender_email');
        });
    }

    public function down(): void
    {
        Schema::table('suresign_settings', function (Blueprint $table) {
            $table->dropColumn(['email_sender_email', 'email_sender_name']);
        });
    }
};
