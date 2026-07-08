<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_notices', function (Blueprint $table) {
            $table->boolean('is_late')->default(false)->after('notice_date')
                ->comment('notice_date issued after payment_notice_deadline on the parent PaymentApplication');
        });

        Schema::table('pay_less_notices', function (Blueprint $table) {
            $table->boolean('is_late')->default(false)->after('notice_date')
                ->comment('notice_date issued after pay_less_notice_deadline on the parent PaymentApplication');
        });
    }

    public function down(): void
    {
        Schema::table('payment_notices', function (Blueprint $table) {
            $table->dropColumn('is_late');
        });

        Schema::table('pay_less_notices', function (Blueprint $table) {
            $table->dropColumn('is_late');
        });
    }
};
