<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Automated Friday Pack, V1F — delivery recipient configuration.
 * Additive only, on top of the already-run V1B/V1E friday_pack_settings
 * migrations.
 *
 * Recipients are OPERATIONAL delivery settings, not report content —
 * deliberately never copied into a FridayPack's own snapshot_json. At
 * Send initiation, the CURRENT value of this column is copied into
 * durable FridayPackDelivery rows (see that table) — this column itself
 * is never read again for an already-initiated delivery, so a later
 * change here can never alter an in-progress or completed send.
 *
 * Nullable, no DB-level default (matches SQLite/MySQL portably — a JSON
 * literal default requires MySQL 8.0.13+ expression syntax, which would
 * not behave identically on the SQLite test database). `null` is treated
 * as "no recipients configured" (an empty list) at the model/controller
 * layer, exactly like FridayPackSettingsController already does for a
 * project with no settings row at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('friday_pack_settings', function (Blueprint $table) {
            $table->json('recipients')->nullable()->after('generation_hour_local');
        });
    }

    public function down(): void
    {
        Schema::table('friday_pack_settings', function (Blueprint $table) {
            $table->dropColumn('recipients');
        });
    }
};
