<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->unsignedSmallInteger('due_date_offset_days')->nullable()->after('payment_terms_days');
            $table->unsignedSmallInteger('final_date_offset_days')->nullable()->after('due_date_offset_days');
            $table->unsignedSmallInteger('payment_notice_offset_days')->nullable()->after('final_date_offset_days');
            $table->unsignedSmallInteger('pay_less_notice_offset_days')->nullable()->after('payment_notice_offset_days');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn([
                'due_date_offset_days',
                'final_date_offset_days',
                'payment_notice_offset_days',
                'pay_less_notice_offset_days',
            ]);
        });
    }
};
