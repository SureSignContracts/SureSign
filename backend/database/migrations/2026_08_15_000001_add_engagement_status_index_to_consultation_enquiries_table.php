<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Consultancy Phase C2, Batch 6B (production-readiness performance review) —
// engagement_status is filtered/grouped on by the operator queue
// (ConsultancyOperationsController::index()), the dashboard
// (dashboardSummary()), and the ageing/overdue helpers, but had no index
// since it was introduced (2026_07_29_000001). Additive only — no behaviour
// change, no new capability.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consultation_enquiries', function (Blueprint $table) {
            $table->index('engagement_status');
        });
    }

    public function down(): void
    {
        Schema::table('consultation_enquiries', function (Blueprint $table) {
            $table->dropIndex(['engagement_status']);
        });
    }
};
