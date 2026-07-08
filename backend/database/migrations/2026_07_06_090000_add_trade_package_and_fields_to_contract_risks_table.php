<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A risk belongs to a Contract OR a Trade Package, never neither —
        // validated in the controller, not enforced by the DB. Mirrors the
        // delay_events / payment_applications nullable-FK pattern.
        DB::statement('ALTER TABLE contract_risks DROP FOREIGN KEY contract_risks_contract_id_foreign');
        DB::statement('ALTER TABLE contract_risks MODIFY contract_id BIGINT UNSIGNED NULL');
        DB::statement('ALTER TABLE contract_risks ADD CONSTRAINT contract_risks_contract_id_foreign FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE CASCADE');

        DB::statement('ALTER TABLE contract_risks ADD COLUMN trade_package_id BIGINT UNSIGNED NULL AFTER contract_id');
        DB::statement('ALTER TABLE contract_risks ADD CONSTRAINT contract_risks_trade_package_id_foreign FOREIGN KEY (trade_package_id) REFERENCES trade_packages(id) ON DELETE SET NULL');
        DB::statement('ALTER TABLE contract_risks ADD INDEX contract_risks_trade_package_id_index (trade_package_id)');

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

        DB::statement('ALTER TABLE contract_risks DROP FOREIGN KEY contract_risks_trade_package_id_foreign');
        DB::statement('ALTER TABLE contract_risks DROP INDEX contract_risks_trade_package_id_index');
        DB::statement('ALTER TABLE contract_risks DROP COLUMN trade_package_id');

        DB::statement('ALTER TABLE contract_risks DROP FOREIGN KEY contract_risks_contract_id_foreign');
        DB::statement('ALTER TABLE contract_risks MODIFY contract_id BIGINT UNSIGNED NOT NULL');
        DB::statement('ALTER TABLE contract_risks ADD CONSTRAINT contract_risks_contract_id_foreign FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE CASCADE');
    }
};
