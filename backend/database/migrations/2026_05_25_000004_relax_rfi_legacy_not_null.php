<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Relax remaining NOT NULL constraints that block module inserts.
 *
 * rfis: query and date_raised are legacy NOT NULL columns;
 *       models/controllers now use description and raised_date instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        // rfis — legacy columns that are no longer populated by the controller
        Schema::table('rfis', function (Blueprint $table) {
            $table->text('query')->nullable()->change();
            $table->date('date_raised')->nullable()->change();
        });
    }

    public function down(): void
    {
        // intentionally left blank
    }
};
