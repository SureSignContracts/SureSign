<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * User timezone is an optional override. Null means "inherit the
     * organisation timezone" (see App\Services\TimezoneResolver) — every
     * existing user row is left null by this migration, which is exactly
     * the correct value: no existing user had an explicit preference, so
     * none should be invented for them.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('timezone')->nullable()->after('country');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('timezone');
        });
    }
};
