<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prepare variations to optionally belong to a trade package (subcontract)
 * instead of, or in addition to, the main contract.
 *
 * This is a preparatory schema change only — the full trade package variation
 * UI/workflow is intentionally NOT implemented here. Existing contract
 * variations continue to work unchanged (trade_package_id is nullable).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('variations', function (Blueprint $table) {
            $table->unsignedBigInteger('trade_package_id')->nullable()->after('contract_id');

            $table->foreign('trade_package_id')
                ->references('id')->on('trade_packages')
                ->nullOnDelete();

            $table->index('trade_package_id');
        });
    }

    public function down(): void
    {
        Schema::table('variations', function (Blueprint $table) {
            $table->dropForeign(['trade_package_id']);
            $table->dropIndex(['trade_package_id']);
            $table->dropColumn('trade_package_id');
        });
    }
};
