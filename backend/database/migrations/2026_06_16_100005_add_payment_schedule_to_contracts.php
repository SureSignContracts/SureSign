<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->string('payment_frequency')->nullable()->after('payment_terms_days');
            $table->integer('application_due_day')->nullable()->after('payment_frequency');
            $table->string('valuation_period_rule')->nullable()->after('application_due_day');
            $table->string('payment_due_date_rule')->nullable()->after('valuation_period_rule');
            $table->string('final_date_for_payment_rule')->nullable()->after('payment_due_date_rule');
            $table->string('pay_less_notice_deadline_rule')->nullable()->after('final_date_for_payment_rule');
            $table->string('payment_notice_deadline_rule')->nullable()->after('pay_less_notice_deadline_rule');
            $table->boolean('manual_date_override_allowed')->default(true)->after('payment_notice_deadline_rule');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn([
                'payment_frequency', 'application_due_day', 'valuation_period_rule',
                'payment_due_date_rule', 'final_date_for_payment_rule',
                'pay_less_notice_deadline_rule', 'payment_notice_deadline_rule',
                'manual_date_override_allowed',
            ]);
        });
    }
};
