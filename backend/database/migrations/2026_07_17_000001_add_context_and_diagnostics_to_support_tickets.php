<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->string('route')->nullable()->after('priority');
            $table->string('module')->nullable()->after('route');
            $table->foreignId('project_id')->nullable()->after('module')->constrained()->nullOnDelete();
            $table->foreignId('trade_package_id')->nullable()->after('project_id')->constrained('trade_packages')->nullOnDelete();
            $table->json('diagnostics')->nullable()->after('trade_package_id');
        });
    }

    public function down(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
            $table->dropForeign(['trade_package_id']);
            $table->dropColumn(['route', 'module', 'project_id', 'trade_package_id', 'diagnostics']);
        });
    }
};
