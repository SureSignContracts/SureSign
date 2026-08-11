<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dashboard Command Center — Project Location & Map Foundation.
 *
 * Adds the project's own geographic site coordinates only — deliberately
 * NOT drawing/floor-plan coordinates, grid references, or a
 * building/block/level location. Those remain a separate, unbuilt future
 * concept (see project-context.md's Drawing/Site Location architecture
 * note). Nullable, no default, no backfill — an existing project with no
 * coordinates stays exactly as valid as it was before this migration, and
 * is simply absent from the Dashboard Project Map until an organisation
 * user enters coordinates manually. No geocoding populates these columns
 * anywhere in the codebase.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            // decimal(10,7) covers -90..90 / -180..180 with ~1cm precision at
            // the equator — enough for construction-site-level positioning,
            // no more than that.
            $table->decimal('latitude', 10, 7)->nullable()->after('country');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude']);
        });
    }
};
