<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suresign_notifications', function (Blueprint $table) {
            // Operational metadata
            $table->string('category', 50)->nullable()->after('type');
            $table->string('priority', 20)->nullable()->after('category');   // info|reminder|warning|critical

            // Lifecycle status (replaces the binary is_read flag for engine-generated notifications)
            // Existing rows will get 'unread' default; controller syncs is_read ↔ status.
            $table->string('status', 20)->default('unread')->after('priority');

            // Source record — used for idempotency and routing
            $table->string('source_type', 100)->nullable()->after('status');
            $table->unsignedBigInteger('source_id')->nullable()->after('source_type');
            $table->string('source_field', 100)->nullable()->after('source_id');
            $table->string('action_url', 500)->nullable()->after('source_field');

            // Tenant / project scope
            $table->unsignedBigInteger('organization_id')->nullable()->after('action_url');
            $table->unsignedBigInteger('project_id')->nullable()->after('organization_id');
        });

        // Backfill status for existing read notifications
        DB::table('suresign_notifications')
            ->where('is_read', true)
            ->update(['status' => 'read']);
    }

    public function down(): void
    {
        Schema::table('suresign_notifications', function (Blueprint $table) {
            $table->dropColumn([
                'category', 'priority', 'status',
                'source_type', 'source_id', 'source_field', 'action_url',
                'organization_id', 'project_id',
            ]);
        });
    }
};
