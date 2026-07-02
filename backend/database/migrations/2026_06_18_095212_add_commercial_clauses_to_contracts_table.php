<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            // Commercial clauses extracted by AI analysis — no home before this migration
            $table->string('defects_liability_period')->nullable()->after('completion_date');
            $table->string('liquidated_damages')->nullable()->after('defects_liability_period');
            $table->text('notice_requirements')->nullable()->after('liquidated_damages');
            $table->text('variation_procedure')->nullable()->after('notice_requirements');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn([
                'defects_liability_period',
                'liquidated_damages',
                'notice_requirements',
                'variation_procedure',
            ]);
        });
    }
};
