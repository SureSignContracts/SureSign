<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contract_ai_analyses', function (Blueprint $table) {
            // Raw model text is kept even when JSON parsing fails, so the (already paid for)
            // response can be re-parsed without calling Claude again.
            $table->longText('raw_response_text')->nullable()->after('raw_response_json');
            // Anthropic stop_reason: 'end_turn' (complete) vs 'max_tokens' (truncated).
            $table->string('stop_reason', 32)->nullable()->after('raw_response_text');
        });
    }

    public function down(): void
    {
        Schema::table('contract_ai_analyses', function (Blueprint $table) {
            $table->dropColumn(['raw_response_text', 'stop_reason']);
        });
    }
};
