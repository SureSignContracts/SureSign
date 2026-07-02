<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('pay_less_notices', function (Blueprint $table) {
            $table->decimal('original_amount_due', 15, 2)->nullable()->after('notified_sum');
            $table->decimal('total_deductions', 15, 2)->nullable()->after('original_amount_due');
            $table->decimal('revised_amount_payable', 15, 2)->nullable()->after('total_deductions');
            $table->text('deduction_reason')->nullable()->after('revised_amount_payable');
            $table->text('detailed_deduction_notes')->nullable()->after('deduction_reason');
            $table->string('issued_by')->nullable()->after('detailed_deduction_notes');
            $table->foreignId('payment_notice_id')->nullable()->after('payment_application_id')->constrained('payment_notices')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pay_less_notices', function (Blueprint $table) {
            $table->dropForeign(['payment_notice_id']);
            $table->dropColumn([
                'original_amount_due', 'total_deductions', 'revised_amount_payable',
                'deduction_reason', 'detailed_deduction_notes', 'issued_by', 'payment_notice_id',
            ]);
        });
    }
};
