<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Deadlines: add operational status + resolved absolute date
        Schema::table('contract_deadlines', function (Blueprint $table) {
            $table->string('status')->default('pending')->after('is_ai_generated');
            $table->date('resolved_date')->nullable()->after('status')
                ->comment('Absolute date resolved from trigger_event + contract dates by CalendarSyncService');
        });

        // Deliverables already have a status column (added in Sprint 1).
        // Add resolved_date for calendar sync.
        Schema::table('contract_deliverables', function (Blueprint $table) {
            $table->date('resolved_date')->nullable()->after('status')
                ->comment('Absolute date resolved from due_event + contract dates by CalendarSyncService');
        });
    }

    public function down(): void
    {
        Schema::table('contract_deadlines', function (Blueprint $table) {
            $table->dropColumn(['status', 'resolved_date']);
        });

        Schema::table('contract_deliverables', function (Blueprint $table) {
            $table->dropColumn(['resolved_date']);
        });
    }
};
