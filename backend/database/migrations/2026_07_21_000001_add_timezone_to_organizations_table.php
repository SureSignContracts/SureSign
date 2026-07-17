<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Organisation timezone is required (top of the user → organisation →
     * platform → UTC hierarchy that isn't the platform default itself).
     * Adding a NOT NULL column with a DEFAULT backfills every existing row
     * with 'Europe/London' at the database level — no separate backfill
     * step needed, and no existing row is left null.
     */
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('timezone')->default('Europe/London')->after('country');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('timezone');
        });
    }
};
