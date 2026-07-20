<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            // Nullable, no default: an organisation with no configured currency
            // must fall through to the platform default (SuresignSetting), not
            // silently get a currency it never chose. See CurrencyService for
            // the full project -> organization -> platform -> GBP hierarchy.
            $table->string('currency', 3)->nullable()->after('country');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('currency');
        });
    }
};
