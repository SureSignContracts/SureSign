<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Slice E2 VAT requirement — SureSign pricing is VAT-exclusive; the
 * commercial price stored in pricing_plans stays the pre-tax amount (no
 * change here), but the customer-facing suffix must say so. Only updates
 * rows still carrying the exact pre-VAT-policy default ("/month") so an
 * operator who has already customised a plan's wording via Super Admin
 * Pricing Management is never silently overwritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('pricing_plans')
            ->where('price_suffix', '/month')
            ->update(['price_suffix' => '/month + VAT']);
    }

    public function down(): void
    {
        DB::table('pricing_plans')
            ->where('price_suffix', '/month + VAT')
            ->update(['price_suffix' => '/month']);
    }
};
