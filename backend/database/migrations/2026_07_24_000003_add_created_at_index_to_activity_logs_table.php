<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Application Monitoring's "application actions" counts
     * (ApplicationMonitoringService::applicationActionsBlock) filter
     * activity_logs by created_at alone, with no organization_id/project_id
     * in the WHERE clause — neither existing composite index
     * (organization_id+created_at, project_id+created_at) has created_at as
     * a leading column, so those queries would fall back to a full table
     * scan as activity_logs grows. This index gives them a leading column
     * to range-scan on.
     */
    public function up(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
        });
    }
};
