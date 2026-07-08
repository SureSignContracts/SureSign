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
        // Allow a milestone/activity to belong to a trade package instead of
        // (or as well as tracking against) the main contract.
        Schema::table('contract_programme_milestones', function (Blueprint $table) {
            $table->dropForeign(['contract_id']);
        });
        Schema::table('contract_programme_milestones', function (Blueprint $table) {
            $table->unsignedBigInteger('contract_id')->nullable()->change();
            $table->foreign('contract_id')->references('id')->on('contracts')->cascadeOnDelete();

            $table->unsignedBigInteger('trade_package_id')->nullable()->after('contract_id');
            $table->foreign('trade_package_id')->references('id')->on('trade_packages')->nullOnDelete();

            // Activity-level programme fields (Gantt). Nullable — existing
            // milestone rows simply leave these unset; see planned_finish /
            // forecast_finish / actual_finish accessors on the model for the
            // date these fall back to when an activity has no distinct duration.
            $table->date('planned_start')->nullable()->after('planned_date');
            $table->date('forecast_start')->nullable()->after('forecast_date');
            $table->date('actual_start')->nullable()->after('actual_date');
            $table->unsignedSmallInteger('duration_days')->nullable()->after('actual_start');
            $table->unsignedTinyInteger('progress_pct')->nullable()->after('duration_days');
            $table->json('depends_on')->nullable()->after('progress_pct')
                ->comment('IDs of predecessor milestones/activities');
            $table->string('group_name')->nullable()->after('depends_on')
                ->comment('Section grouping label, e.g. "Block A"');

            $table->index('trade_package_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contract_programme_milestones', function (Blueprint $table) {
            $table->dropForeign(['trade_package_id']);
            $table->dropForeign(['contract_id']);
            $table->dropColumn([
                'trade_package_id', 'planned_start', 'forecast_start', 'actual_start',
                'duration_days', 'progress_pct', 'depends_on', 'group_name',
            ]);
            $table->unsignedBigInteger('contract_id')->nullable(false)->change();
            $table->foreign('contract_id')->references('id')->on('contracts')->cascadeOnDelete();
        });
    }
};
