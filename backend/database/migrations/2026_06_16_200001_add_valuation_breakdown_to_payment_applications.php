<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_applications', function (Blueprint $table) {
            $table->boolean('use_breakdown')->default(false)->after('breakdown');
            $table->decimal('vat_rate', 5, 2)->nullable()->default(20)->after('use_breakdown');
            $table->decimal('vat_amount', 15, 2)->nullable()->after('vat_rate');
            $table->decimal('total_due_including_vat', 15, 2)->nullable()->after('vat_amount');
            $table->decimal('measured_works_total', 15, 2)->nullable()->after('total_due_including_vat');
            $table->decimal('variations_total', 15, 2)->nullable()->after('measured_works_total');
            $table->decimal('materials_on_site_total', 15, 2)->nullable()->after('variations_total');
        });
    }

    public function down(): void
    {
        Schema::table('payment_applications', function (Blueprint $table) {
            $table->dropColumn([
                'use_breakdown',
                'vat_rate',
                'vat_amount',
                'total_due_including_vat',
                'measured_works_total',
                'variations_total',
                'materials_on_site_total',
            ]);
        });
    }
};
