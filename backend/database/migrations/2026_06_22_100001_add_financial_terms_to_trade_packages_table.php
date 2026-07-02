<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trade_packages', function (Blueprint $table) {
            $table->decimal('contract_value', 15, 2)->nullable()->after('contractor_name')
                ->comment('Subcontract / trade package contract sum');
            $table->decimal('retention_percentage', 5, 2)->nullable()->after('contract_value')
                ->comment('Retention % applied to payment applications for this package');
            $table->unsignedSmallInteger('payment_terms_days')->nullable()->after('retention_percentage')
                ->comment('Payment terms in days (mirrors contract payment_terms_days)');
            $table->string('payment_frequency', 20)->nullable()->after('payment_terms_days')
                ->comment('weekly | fortnightly | monthly | manual');
        });
    }

    public function down(): void
    {
        Schema::table('trade_packages', function (Blueprint $table) {
            $table->dropColumn(['contract_value', 'retention_percentage', 'payment_terms_days', 'payment_frequency']);
        });
    }
};
