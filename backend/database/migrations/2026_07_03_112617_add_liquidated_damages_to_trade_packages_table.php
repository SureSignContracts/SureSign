<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('trade_packages', function (Blueprint $table) {
            // Free-text, mirroring Contract.liquidated_damages — LD clauses are usually
            // worded ("£500 per day or part thereof"), not a clean numeric rate.
            $table->string('liquidated_damages')->nullable()->after('retention_percentage');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trade_packages', function (Blueprint $table) {
            $table->dropColumn('liquidated_damages');
        });
    }
};
