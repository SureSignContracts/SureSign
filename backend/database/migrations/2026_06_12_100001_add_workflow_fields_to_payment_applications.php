<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Schema builder methods (not raw MySQL-only DDL with an AFTER
        // clause SQLite doesn't support) so this runs on both MySQL and the
        // SQLite test database. submitted_by/certified_by/paid_by are
        // intentionally plain nullable columns, matching the original raw
        // SQL — no FK constraint was added for them there either.
        Schema::table('payment_applications', function (Blueprint $table) {
            $table->timestamp('submitted_at')->nullable()->after('status');
            $table->unsignedBigInteger('submitted_by')->nullable()->after('submitted_at');
            $table->timestamp('certified_at')->nullable()->after('submitted_by');
            $table->unsignedBigInteger('certified_by')->nullable()->after('certified_at');
            $table->string('certificate_reference', 100)->nullable()->after('certified_by');
            $table->text('certificate_notes')->nullable()->after('certificate_reference');
            $table->timestamp('paid_at')->nullable()->after('certificate_notes');
            $table->unsignedBigInteger('paid_by')->nullable()->after('paid_at');
            $table->string('payment_reference', 100)->nullable()->after('paid_by');
            $table->timestamp('cancelled_at')->nullable()->after('payment_reference');
        });
    }

    public function down(): void
    {
        Schema::table('payment_applications', function (Blueprint $table) {
            $table->dropColumn([
                'submitted_at', 'submitted_by', 'certified_at', 'certified_by',
                'certificate_reference', 'certificate_notes',
                'paid_at', 'paid_by', 'payment_reference', 'cancelled_at',
            ]);
        });
    }
};
