<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Consultancy Communications & Global Email Experience Upgrade, Batch 3 —
 * a dedicated TTL for the new public consultation "view" and "published
 * summary" signed links (AppointmentPublicLinkService::consultationViewApiUrl()/
 * consultationSummaryApiUrl()). Deliberately NOT reusing
 * appointment_cancel_link_ttl_hours/appointment_reschedule_link_ttl_hours —
 * those are computed relative to the appointment's own starts_at/cutoff,
 * which is meaningless for a link whose entire purpose is to remain valid
 * AFTER the appointment has already happened. Default 4320 hours (180
 * days) — long enough for a customer to revisit their booking or a
 * published summary at their own pace, while still being a real, finite
 * expiry (every signed link in this codebase has one; this is not the
 * first exception).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suresign_settings', function (Blueprint $table) {
            $table->unsignedInteger('consultation_public_link_ttl_hours')->default(4320);
        });
    }

    public function down(): void
    {
        Schema::table('suresign_settings', function (Blueprint $table) {
            $table->dropColumn('consultation_public_link_ttl_hours');
        });
    }
};
