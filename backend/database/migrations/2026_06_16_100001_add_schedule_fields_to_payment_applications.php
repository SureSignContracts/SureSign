<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('payment_applications', function (Blueprint $table) {
            // Previous value auto-calculations
            $table->decimal('previous_certified_value', 15, 2)->nullable()->after('less_previous_payments');
            $table->decimal('previous_paid_value', 15, 2)->nullable()->after('previous_certified_value');
            $table->decimal('previous_retention_held', 15, 2)->nullable()->after('previous_paid_value');
            $table->decimal('previous_gross_valuation', 15, 2)->nullable()->after('previous_retention_held');
            $table->integer('previous_applications_count')->nullable()->after('previous_gross_valuation');

            // Schedule / date fields
            $table->date('valuation_period_start')->nullable()->after('application_date');
            $table->date('valuation_period_end')->nullable()->after('valuation_period_start');
            $table->date('final_date_for_payment')->nullable()->after('due_date');
            $table->date('payment_notice_deadline')->nullable()->after('final_date_for_payment');
            $table->date('pay_less_notice_deadline')->nullable()->after('payment_notice_deadline');
        });
    }

    public function down(): void
    {
        Schema::table('payment_applications', function (Blueprint $table) {
            $table->dropColumn([
                'previous_certified_value', 'previous_paid_value', 'previous_retention_held',
                'previous_gross_valuation', 'previous_applications_count',
                'valuation_period_start', 'valuation_period_end',
                'final_date_for_payment', 'payment_notice_deadline', 'pay_less_notice_deadline',
            ]);
        });
    }
};
