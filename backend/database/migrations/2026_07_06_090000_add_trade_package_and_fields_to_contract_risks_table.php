<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A risk belongs to a Contract OR a Trade Package, never neither —
        // validated in the controller, not enforced by the DB. Mirrors the
        // delay_events / payment_applications nullable-FK pattern. Schema
        // builder methods (not raw MySQL-only DDL) so this runs correctly
        // on both MySQL and the SQLite test database.
        Schema::table('contract_risks', function (Blueprint $table) {
            $table->dropForeign(['contract_id']);
            $table->unsignedBigInteger('contract_id')->nullable()->change();
            $table->foreign('contract_id')->references('id')->on('contracts')->cascadeOnDelete();

            $table->foreignId('trade_package_id')->nullable()->after('contract_id')
                ->constrained('trade_packages')->nullOnDelete();
            $table->index('trade_package_id');
        });

        Schema::table('contract_risks', function (Blueprint $table) {
            // Genuinely missing fields only — severity/urgency/risk_owner/
            // recommended_action already cover most of the Sprint 6F brief.
            $table->string('probability')->nullable()->after('severity')
                ->comment('low, medium, high');
            $table->text('mitigation')->nullable()->after('recommended_action');
            $table->date('review_date')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('contract_risks', function (Blueprint $table) {
            $table->dropColumn(['probability', 'mitigation', 'review_date']);
        });

        Schema::table('contract_risks', function (Blueprint $table) {
            $table->dropForeign(['trade_package_id']);
            $table->dropIndex(['trade_package_id']);
            $table->dropColumn('trade_package_id');

            $table->dropForeign(['contract_id']);
            $table->unsignedBigInteger('contract_id')->nullable(false)->change();
            $table->foreign('contract_id')->references('id')->on('contracts')->cascadeOnDelete();
        });
    }
};
