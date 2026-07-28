<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Subscription Suspension Completion checkpoint — promotes suspension
     * INTENT from the untyped, unindexed `metadata_json['planned_suspension_reason']`
     * bag (checkpoints 4/13/14) into two real, additive columns, mirroring
     * the existing `pending_pricing_plan_id`/`plan_change_effective_at`
     * convention for scheduled plan changes:
     *
     *   - `pending_suspension_reason` — the requested reason, carried
     *     forward into `suspension_reason` once the suspension actually
     *     takes effect (see SubscriptionLifecycleService::suspend()).
     *   - `pending_suspension_effective_at` — the ONE authoritative field
     *     this checkpoint's automation discovery query needs
     *     (`pending_suspension_effective_at <= now()`). Indexed for that
     *     reason. Nullable: no pending suspension exists when null.
     *
     * `metadata_json['planned_suspension_reason']` is retired by this
     * checkpoint's code changes — see
     * SubscriptionLifecycleService::scheduleSuspension()'s updated
     * docblock. No data migration/backfill step is included: this is a
     * request-intent field, not purchased commercial history, so an empty
     * bag being retired loses nothing worth preserving.
     */
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('pending_suspension_reason')->nullable()->after('metadata_json');
            $table->timestamp('pending_suspension_effective_at')->nullable()->after('pending_suspension_reason');

            $table->index('pending_suspension_effective_at');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropIndex(['pending_suspension_effective_at']);
            $table->dropColumn(['pending_suspension_reason', 'pending_suspension_effective_at']);
        });
    }
};
