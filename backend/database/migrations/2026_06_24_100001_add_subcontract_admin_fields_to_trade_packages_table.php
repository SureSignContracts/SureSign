<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extend trade_packages so each package can act as a complete subcontract
 * administration workspace.
 *
 * Adds:
 *   - Subcontract procurement / lifecycle dates
 *   - Extended contractor details (kept alongside the existing contractor_name)
 *   - Payment rule offset fields mirroring contracts, so trade package payment
 *     applications can calculate statutory dates.
 *
 * The existing `status` column stays a string — new lifecycle values
 * (tendering, awarded, executed, completed, closed, …) are handled in
 * application/validation logic. No status column change is required, which
 * keeps every existing record valid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trade_packages', function (Blueprint $table) {
            // ── Subcontract dates ──────────────────────────────────────────
            $table->date('letter_of_intent_date')->nullable()->after('payment_frequency');
            $table->date('award_date')->nullable()->after('letter_of_intent_date');
            $table->date('execution_date')->nullable()->after('award_date');
            $table->date('commencement_date')->nullable()->after('execution_date');
            $table->date('completion_date')->nullable()->after('commencement_date');
            $table->date('defects_liability_end_date')->nullable()->after('completion_date');

            // ── Extended contractor details ────────────────────────────────
            $table->string('contractor_contact_name')->nullable()->after('defects_liability_end_date');
            $table->string('contractor_email')->nullable()->after('contractor_contact_name');
            $table->string('contractor_phone', 60)->nullable()->after('contractor_email');
            $table->string('contractor_address')->nullable()->after('contractor_phone');
            $table->string('contractor_company_reg_no', 60)->nullable()->after('contractor_address');
            $table->string('contractor_vat_number', 60)->nullable()->after('contractor_company_reg_no');

            // ── Payment rule offsets (mirror contracts) ────────────────────
            $table->unsignedSmallInteger('due_date_offset_days')->nullable()->after('contractor_vat_number')
                ->comment('Days from application date → due date');
            $table->unsignedSmallInteger('final_date_offset_days')->nullable()->after('due_date_offset_days')
                ->comment('Days from due date → final date for payment');
            $table->unsignedSmallInteger('payment_notice_offset_days')->nullable()->after('final_date_offset_days')
                ->comment('Days from due date → payment notice deadline');
            $table->unsignedSmallInteger('pay_less_notice_offset_days')->nullable()->after('payment_notice_offset_days')
                ->comment('Days before final date → pay less notice deadline');
        });
    }

    public function down(): void
    {
        Schema::table('trade_packages', function (Blueprint $table) {
            $table->dropColumn([
                'letter_of_intent_date',
                'award_date',
                'execution_date',
                'commencement_date',
                'completion_date',
                'defects_liability_end_date',
                'contractor_contact_name',
                'contractor_email',
                'contractor_phone',
                'contractor_address',
                'contractor_company_reg_no',
                'contractor_vat_number',
                'due_date_offset_days',
                'final_date_offset_days',
                'payment_notice_offset_days',
                'pay_less_notice_offset_days',
            ]);
        });
    }
};
