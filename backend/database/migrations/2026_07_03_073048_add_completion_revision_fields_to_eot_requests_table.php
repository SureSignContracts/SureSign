<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('eot_requests', function (Blueprint $table) {
            // trade_package_id lets an EOT be raised against a subcontract
            // instead of (or alongside) the main contract, mirroring the
            // contract_id/trade_package_id either-or pattern used on
            // delay_events and contract_programme_milestones.
            $table->foreignId('trade_package_id')->nullable()->after('contract_id')
                ->constrained('trade_packages')->nullOnDelete();

            // Optional link back to the delay event that prompted this EOT.
            $table->foreignId('delay_event_id')->nullable()->after('trade_package_id')
                ->constrained('delay_events')->nullOnDelete();

            // Computed at decision time (see EotRequestController::decide) —
            // base completion date (contract or trade package) plus the
            // cumulative granted days of this and all prior granted/
            // partially-granted EOTs for the same contract/trade package.
            // This is deliberately NOT a separate ledger table for 5C — the
            // sequence of EOT rows themselves *is* the history; the "current"
            // revised completion date is just the latest one.
            $table->date('revised_completion_date')->nullable()->after('days_granted');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('eot_requests', function (Blueprint $table) {
            $table->dropForeign(['trade_package_id']);
            $table->dropForeign(['delay_event_id']);
            $table->dropColumn(['trade_package_id', 'delay_event_id', 'revised_completion_date']);
        });
    }
};
