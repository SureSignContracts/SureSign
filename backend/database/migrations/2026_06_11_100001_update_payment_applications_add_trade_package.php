<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Make contract_id nullable (was NOT NULL with FK)
        DB::statement('ALTER TABLE payment_applications DROP FOREIGN KEY payment_applications_contract_id_foreign');
        DB::statement('ALTER TABLE payment_applications MODIFY contract_id BIGINT UNSIGNED NULL');
        DB::statement('ALTER TABLE payment_applications ADD CONSTRAINT payment_applications_contract_id_foreign FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE CASCADE');

        // Add trade_package_id
        DB::statement('ALTER TABLE payment_applications ADD COLUMN trade_package_id BIGINT UNSIGNED NULL AFTER contract_id');
        DB::statement('ALTER TABLE payment_applications ADD CONSTRAINT payment_applications_trade_package_id_foreign FOREIGN KEY (trade_package_id) REFERENCES trade_packages(id) ON DELETE SET NULL');
        DB::statement('ALTER TABLE payment_applications ADD INDEX payment_applications_trade_package_id_index (trade_package_id)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE payment_applications DROP FOREIGN KEY payment_applications_trade_package_id_foreign');
        DB::statement('ALTER TABLE payment_applications DROP INDEX payment_applications_trade_package_id_index');
        DB::statement('ALTER TABLE payment_applications DROP COLUMN trade_package_id');

        DB::statement('ALTER TABLE payment_applications DROP FOREIGN KEY payment_applications_contract_id_foreign');
        DB::statement('ALTER TABLE payment_applications MODIFY contract_id BIGINT UNSIGNED NOT NULL');
        DB::statement('ALTER TABLE payment_applications ADD CONSTRAINT payment_applications_contract_id_foreign FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE CASCADE');
    }
};
