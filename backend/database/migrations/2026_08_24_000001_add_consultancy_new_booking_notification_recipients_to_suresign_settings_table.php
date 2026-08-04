<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Consultancy — operator-facing "new booking" in-app notification
 * (previously didn't exist at all; an operator only ever learned about a
 * new booking by manually checking the Consultancy Queue). Configurable
 * rather than hardcoded per explicit instruction: 'all_admins' (every
 * Super Admin/Admin, matching Support's own notifySupportOperators()
 * convention) or 'assigned_consultant' (only the specific consultant the
 * booking was assigned to). Default 'all_admins'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suresign_settings', function (Blueprint $table) {
            $table->string('consultancy_new_booking_notification_recipients', 30)->default('all_admins');
        });
    }

    public function down(): void
    {
        Schema::table('suresign_settings', function (Blueprint $table) {
            $table->dropColumn('consultancy_new_booking_notification_recipients');
        });
    }
};
