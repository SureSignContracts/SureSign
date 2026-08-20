<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Notification Sound System — the configurable PLATFORM-WIDE notification
// sound asset, uploaded by Super Admin/Admin via the existing SureSign
// Branding settings hub (mirrors logo_path/favicon_path exactly — same
// disk, same upload/remove endpoint shape, same accessor pattern on
// SuresignSetting). This is deliberately separate from
// `users.notification_sound_enabled` (added in 2026_08_20_000003) — that
// column is the per-user ON/OFF preference; this column is the one shared
// audio asset every user's preference plays when enabled. Nullable — no
// default asset ships with the platform; the feature simply has nothing to
// play until an operator uploads one.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suresign_settings', function (Blueprint $table) {
            $table->string('notification_sound_path')->nullable()->after('favicon_path');
        });
    }

    public function down(): void
    {
        Schema::table('suresign_settings', function (Blueprint $table) {
            $table->dropColumn('notification_sound_path');
        });
    }
};
