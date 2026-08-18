<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contract_ai_analyses', function (Blueprint $table) {
            $table->unsignedTinyInteger('progress_percent')->default(0)->after('status');
            $table->string('progress_stage', 40)->nullable()->after('progress_percent');
            $table->string('progress_message')->nullable()->after('progress_stage');
            $table->timestamp('progress_updated_at')->nullable()->after('progress_message');
        });
    }

    public function down(): void
    {
        Schema::table('contract_ai_analyses', function (Blueprint $table) {
            $table->dropColumn(['progress_percent', 'progress_stage', 'progress_message', 'progress_updated_at']);
        });
    }
};
