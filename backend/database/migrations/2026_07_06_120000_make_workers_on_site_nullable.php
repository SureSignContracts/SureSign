<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

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
        DB::statement('ALTER TABLE site_diaries MODIFY workers_on_site INT NULL DEFAULT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE site_diaries MODIFY workers_on_site INT NOT NULL DEFAULT 0');
    }
};
