<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_applications', function (Blueprint $table) {
            $table->unsignedInteger('withdrawal_count')->default(0)->after('submitted_by');
            $table->timestamp('withdrawn_at')->nullable()->after('withdrawal_count');
            $table->unsignedBigInteger('withdrawn_by')->nullable()->after('withdrawn_at');
            $table->string('withdrawal_reason', 500)->nullable()->after('withdrawn_by');
        });
    }

    public function down(): void
    {
        Schema::table('payment_applications', function (Blueprint $table) {
            $table->dropColumn(['withdrawal_count', 'withdrawn_at', 'withdrawn_by', 'withdrawal_reason']);
        });
    }
};
