<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per user per calendar day of meaningful authenticated
     * activity — the durable source for DAU/WAU/MAU. Distinct from
     * `module_usage_daily` (which aggregates per module, not per user
     * across modules) and from `activity_logs` (which only records
     * specific business actions, not general page usage). Written at most
     * once per user per day, gated by the same Redis dedup pattern used
     * for module usage — never once per request.
     */
    public function up(): void
    {
        Schema::create('daily_active_users', function (Blueprint $table) {
            $table->id();
            $table->date('activity_date');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            // No separate index('activity_date') — the unique key below
            // already covers lookups on activity_date alone via
            // leftmost-prefix matching.
            $table->unique(['activity_date', 'user_id'], 'daily_active_users_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_active_users');
    }
};
