<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('variations', function (Blueprint $table) {
            $table->string('valuation_status')->default('not_included')
                ->comment('not_included,partially_included,included,certified,rejected')
                ->after('agreed_in_writing');
            $table->unsignedBigInteger('included_in_pa_id')->nullable()
                ->after('valuation_status');
        });
    }
    public function down(): void
    {
        Schema::table('variations', function (Blueprint $table) {
            $table->dropColumn(['valuation_status', 'included_in_pa_id']);
        });
    }
};
