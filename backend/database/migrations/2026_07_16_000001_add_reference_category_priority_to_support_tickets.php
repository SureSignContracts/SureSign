<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->string('reference')->nullable()->unique()->after('id');
            $table->string('category')->nullable()->after('subject');
            $table->string('priority')->default('normal')->after('category');
        });
    }

    public function down(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropColumn(['reference', 'category', 'priority']);
        });
    }
};
