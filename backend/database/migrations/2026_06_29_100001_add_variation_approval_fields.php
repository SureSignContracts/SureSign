<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('variations', function (Blueprint $table) {
            // Submission
            $table->timestamp('submitted_at')->nullable()->after('agreed_in_writing');
            $table->unsignedBigInteger('submitted_by')->nullable()->after('submitted_at');

            // Instruction (employer formally issues instruction)
            $table->timestamp('instructed_at')->nullable()->after('submitted_by');
            $table->unsignedBigInteger('instructed_by')->nullable()->after('instructed_at');
            $table->text('instruction_notes')->nullable()->after('instructed_by');

            // Quotation (contractor submits price — date already in quotation_submitted_at)
            $table->unsignedBigInteger('quoted_by')->nullable()->after('instruction_notes');

            // Assessment (employer formally assesses the quotation)
            $table->timestamp('assessed_at')->nullable()->after('quoted_by');
            $table->unsignedBigInteger('assessed_by')->nullable()->after('assessed_at');
            $table->text('assessment_notes')->nullable()->after('assessed_by');

            // Approval
            $table->timestamp('approved_at')->nullable()->after('assessment_notes');
            $table->unsignedBigInteger('approved_by')->nullable()->after('approved_at');
            $table->text('approval_notes')->nullable()->after('approved_by');

            // Rejection
            $table->timestamp('rejected_at')->nullable()->after('approval_notes');
            $table->unsignedBigInteger('rejected_by')->nullable()->after('rejected_at');
            $table->string('rejection_reason', 500)->nullable()->after('rejected_by');

            // Extension point for future programme/EOT linkage — intentionally nullable
            $table->unsignedBigInteger('eot_request_id')->nullable()->after('rejection_reason');

            $table->index('eot_request_id');
        });
    }

    public function down(): void
    {
        Schema::table('variations', function (Blueprint $table) {
            $table->dropIndex(['eot_request_id']);
            $table->dropColumn([
                'submitted_at', 'submitted_by',
                'instructed_at', 'instructed_by', 'instruction_notes',
                'quoted_by',
                'assessed_at', 'assessed_by', 'assessment_notes',
                'approved_at', 'approved_by', 'approval_notes',
                'rejected_at', 'rejected_by', 'rejection_reason',
                'eot_request_id',
            ]);
        });
    }
};
