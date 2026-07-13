<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * workers_on_site was NOT NULL with a default of 0 — but that default
     * only applies when the column is omitted from an INSERT, not when an
     * explicit NULL is passed. The controller's validation already treats
     * this field as optional ('nullable|integer'), and Laravel's
     * ConvertEmptyStringsToNull middleware turns an empty form field into
     * an explicit null, which the DB then rejected outright. Making it
     * genuinely nullable also lets "not recorded" be distinct from a
     * confirmed "0 workers on site".
     */
    public function up(): void
    {
        // Schema builder change() (not a raw MySQL-only MODIFY statement) so
        // this runs on both MySQL and the SQLite test database.
        Schema::table('site_diaries', function (Blueprint $table) {
            $table->integer('workers_on_site')->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        Schema::table('site_diaries', function (Blueprint $table) {
            $table->integer('workers_on_site')->nullable(false)->default(0)->change();
        });
    }
};
