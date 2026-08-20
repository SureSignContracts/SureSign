<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Notification Sound System — the per-user preference for whether SureSign
// plays an audible cue when a genuinely new in-app notification arrives
// while the app is open. Deliberately its own dedicated column, mirroring
// `timezone`/`must_change_password`'s existing convention (see
// AuthController::updateTimezone()) rather than a JSON preferences blob —
// this codebase has no generic per-user settings JSON column to extend, and
// `suresign_settings.notification_settings` is a separate, platform-wide,
// admin-configured EMAIL-event allowlist (unrelated scope — never conflate
// the two). User-level only; no Organisation/platform-global behaviour.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('notification_sound_enabled')->default(true)->after('tours_reset_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('notification_sound_enabled');
        });
    }
};
