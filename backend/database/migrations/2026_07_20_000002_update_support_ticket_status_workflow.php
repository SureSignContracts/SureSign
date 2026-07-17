<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Widens support_tickets.status from the old enum('open','in_progress',
    // 'resolved','closed') to a plain string so it can hold the new workflow
    // values (see SupportTicketStatusService::ALL). No doctrine/dbal is
    // installed, so an in-place ->change() on the enum isn't available —
    // instead: copy existing values into a new plain-string column (remapping
    // 'in_progress' to 'waiting_for_support', its closest equivalent — an
    // admin had started looking at it but nothing here recorded a reply),
    // drop the old enum column, then rename the new one into its place. Pure
    // add/drop/rename column operations only, no raw ALTER COLUMN.
    //
    // MySQL-specific wrinkle (SQLite doesn't have this): organization_id has
    // a foreign key, and the only index covering that column was the
    // composite (organization_id, status) index — InnoDB refuses to drop an
    // index still backing a foreign key ("Cannot drop index ... needed in a
    // foreign key constraint"). A temporary single-column index on
    // organization_id is added first purely to keep the FK satisfied while
    // the composite index is dropped/recreated, then removed once the real
    // composite index is back in place.
    public function up(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->index('organization_id', 'support_tickets_organization_id_tmp_index');
        });

        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'status']);
        });

        Schema::table('support_tickets', function (Blueprint $table) {
            $table->string('status_new', 30)->default('open')->after('status');
            $table->timestamp('client_last_read_at')->nullable()->after('resolved_at');
            $table->timestamp('support_last_read_at')->nullable()->after('client_last_read_at');
        });

        DB::statement('UPDATE support_tickets SET status_new = status');
        DB::table('support_tickets')->where('status_new', 'in_progress')->update(['status_new' => 'waiting_for_support']);

        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropColumn('status');
        });

        Schema::table('support_tickets', function (Blueprint $table) {
            $table->renameColumn('status_new', 'status');
        });

        Schema::table('support_tickets', function (Blueprint $table) {
            $table->index(['organization_id', 'status']);
        });

        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropIndex('support_tickets_organization_id_tmp_index');
        });
    }

    public function down(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->index('organization_id', 'support_tickets_organization_id_tmp_index');
        });

        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'status']);
            $table->dropColumn(['client_last_read_at', 'support_last_read_at']);
        });

        Schema::table('support_tickets', function (Blueprint $table) {
            $table->string('status_old', 30)->default('open')->after('status');
        });

        DB::statement('UPDATE support_tickets SET status_old = status');
        DB::table('support_tickets')->whereIn('status_old', ['waiting_for_support', 'waiting_for_you'])->update(['status_old' => 'in_progress']);

        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropColumn('status');
        });

        Schema::table('support_tickets', function (Blueprint $table) {
            $table->renameColumn('status_old', 'status');
        });

        Schema::table('support_tickets', function (Blueprint $table) {
            $table->index(['organization_id', 'status']);
        });

        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropIndex('support_tickets_organization_id_tmp_index');
        });
    }
};
