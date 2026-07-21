<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Persistent daily module-usage aggregate. One row per
     * organization/module/day — never per-request and never per-visit.
     * Platform-wide totals are derived by summing across organizations at
     * read time; there is deliberately no separate "organization_id = null"
     * platform row, since MySQL treats multiple NULLs in a unique index as
     * distinct and that would allow duplicate platform rows to slip in.
     */
    public function up(): void
    {
        Schema::create('module_usage_daily', function (Blueprint $table) {
            $table->id();
            $table->date('usage_date');
            $table->string('module_key', 60);
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('total_visits')->default(0);
            $table->unsignedInteger('unique_users')->default(0);
            $table->timestamps();

            // No separate index(['usage_date', 'module_key']) — the unique
            // key below already covers lookups on usage_date alone or
            // usage_date+module_key via leftmost-prefix matching.
            $table->unique(['usage_date', 'module_key', 'organization_id'], 'module_usage_daily_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_usage_daily');
    }
};
