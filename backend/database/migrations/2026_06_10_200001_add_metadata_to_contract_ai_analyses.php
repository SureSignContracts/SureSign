<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contract_ai_analyses', function (Blueprint $table) {
            $table->string('summary', 1000)->nullable()->after('model');
            $table->unsignedBigInteger('tokens_input')->nullable()->after('error_message');
            $table->unsignedBigInteger('tokens_output')->nullable()->after('tokens_input');
            $table->decimal('estimated_cost', 10, 6)->nullable()->after('tokens_output');
            $table->timestamp('started_at')->nullable()->after('estimated_cost');
            $table->timestamp('completed_at')->nullable()->after('started_at');
        });
    }

    public function down(): void
    {
        Schema::table('contract_ai_analyses', function (Blueprint $table) {
            $table->dropColumn(['summary', 'tokens_input', 'tokens_output', 'estimated_cost', 'started_at', 'completed_at']);
        });
    }
};
