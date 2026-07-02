<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('variations', function (Blueprint $table) {
            $table->string('instruction_method')->default('written')
                ->comment('written,verbal_emergency')->after('instruction_date');
            $table->date('written_confirmation_due')->nullable()->after('instruction_method');
            $table->date('quotation_due_date')->nullable()->after('written_confirmation_due');
            $table->date('quotation_submitted_at')->nullable()->after('quotation_due_date');
            $table->string('valuation_method')->nullable()
                ->comment('schedule_rates,fair_reasonable,daywork')->after('quotation_submitted_at');
            $table->boolean('agreed_in_writing')->default(false)->after('valuation_method');
        });
    }

    public function down(): void
    {
        Schema::table('variations', function (Blueprint $table) {
            $table->dropColumn([
                'instruction_method', 'written_confirmation_due',
                'quotation_due_date', 'quotation_submitted_at',
                'valuation_method', 'agreed_in_writing',
            ]);
        });
    }
};
