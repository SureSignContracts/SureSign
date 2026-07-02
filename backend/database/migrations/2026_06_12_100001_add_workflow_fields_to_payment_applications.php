<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE payment_applications ADD COLUMN submitted_at TIMESTAMP NULL AFTER status');
        DB::statement('ALTER TABLE payment_applications ADD COLUMN submitted_by BIGINT UNSIGNED NULL AFTER submitted_at');
        DB::statement('ALTER TABLE payment_applications ADD COLUMN certified_at TIMESTAMP NULL AFTER submitted_by');
        DB::statement('ALTER TABLE payment_applications ADD COLUMN certified_by BIGINT UNSIGNED NULL AFTER certified_at');
        DB::statement('ALTER TABLE payment_applications ADD COLUMN certificate_reference VARCHAR(100) NULL AFTER certified_by');
        DB::statement('ALTER TABLE payment_applications ADD COLUMN certificate_notes TEXT NULL AFTER certificate_reference');
        DB::statement('ALTER TABLE payment_applications ADD COLUMN paid_at TIMESTAMP NULL AFTER certificate_notes');
        DB::statement('ALTER TABLE payment_applications ADD COLUMN paid_by BIGINT UNSIGNED NULL AFTER paid_at');
        DB::statement('ALTER TABLE payment_applications ADD COLUMN payment_reference VARCHAR(100) NULL AFTER paid_by');
        DB::statement('ALTER TABLE payment_applications ADD COLUMN cancelled_at TIMESTAMP NULL AFTER payment_reference');
    }

    public function down(): void
    {
        foreach ([
            'submitted_at', 'submitted_by', 'certified_at', 'certified_by',
            'certificate_reference', 'certificate_notes',
            'paid_at', 'paid_by', 'payment_reference', 'cancelled_at',
        ] as $col) {
            DB::statement("ALTER TABLE payment_applications DROP COLUMN {$col}");
        }
    }
};
