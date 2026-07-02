<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('retention_releases', function (Blueprint $table) {
            // half_1 = Practical Completion moiety; half_2 = Making Good Defects moiety
            $table->string('moiety', 20)->default('other')->after('release_reason');
        });
    }

    public function down(): void
    {
        Schema::table('retention_releases', function (Blueprint $table) {
            $table->dropColumn('moiety');
        });
    }
};
