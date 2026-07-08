<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('eot_requests', function (Blueprint $table) {
            $table->foreignId('decided_by')->nullable()->after('revised_completion_date')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable()->after('decided_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('eot_requests', function (Blueprint $table) {
            $table->dropForeign(['decided_by']);
            $table->dropColumn(['decided_by', 'decided_at']);
        });
    }
};
