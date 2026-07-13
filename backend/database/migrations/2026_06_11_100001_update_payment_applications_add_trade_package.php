<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_applications', function (Blueprint $table) {
            // Make contract_id nullable (was NOT NULL with FK) — a payment
            // application belongs to a contract OR a trade package, never
            // neither (validated in the controller, not enforced by the DB).
            // Schema builder methods (not raw MySQL-only DDL) so this runs
            // correctly on both MySQL and the SQLite test database.
            $table->dropForeign(['contract_id']);
            $table->unsignedBigInteger('contract_id')->nullable()->change();
            $table->foreign('contract_id')->references('id')->on('contracts')->cascadeOnDelete();

            $table->foreignId('trade_package_id')->nullable()->after('contract_id')
                ->constrained('trade_packages')->nullOnDelete();
            $table->index('trade_package_id');
        });
    }

    public function down(): void
    {
        Schema::table('payment_applications', function (Blueprint $table) {
            $table->dropForeign(['trade_package_id']);
            $table->dropIndex(['trade_package_id']);
            $table->dropColumn('trade_package_id');

            $table->dropForeign(['contract_id']);
            $table->unsignedBigInteger('contract_id')->nullable(false)->change();
            $table->foreign('contract_id')->references('id')->on('contracts')->cascadeOnDelete();
        });
    }
};
