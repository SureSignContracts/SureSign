<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            // v2 Contract Intelligence fields
            $table->string('standard_form_edition')->nullable()->after('form_of_contract');
            $table->string('procurement_route')->nullable()->after('standard_form_edition');
            $table->string('governing_law')->nullable()->after('procurement_route');
            $table->string('design_responsibility')->nullable()->after('governing_law');

            // Extended party fields
            $table->string('employer_name')->nullable()->after('party_name');
            $table->string('qs_name')->nullable()->after('employer_name');
            $table->string('principal_designer')->nullable()->after('qs_name');
            $table->string('principal_contractor')->nullable()->after('principal_designer');

            // Extended commercial fields
            $table->string('valuation_method')->nullable()->after('payment_frequency');
            $table->boolean('vat_reverse_charge')->default(false)->after('valuation_method');
            $table->boolean('performance_bond_required')->default(false)->after('vat_reverse_charge');
            $table->string('fluctuations_clause')->nullable()->after('performance_bond_required');

            // Extended date fields
            $table->date('possession_date')->nullable()->after('commencement_date');
            $table->date('base_date')->nullable()->after('possession_date');
            $table->unsignedSmallInteger('defects_liability_period_months')->nullable()->after('defects_liability_period');

            // Retention release trigger labels
            $table->string('retention_half1_release')->nullable()->after('retention_cap_percentage');
            $table->string('retention_half2_release')->nullable()->after('retention_half1_release');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn([
                'standard_form_edition', 'procurement_route', 'governing_law', 'design_responsibility',
                'employer_name', 'qs_name', 'principal_designer', 'principal_contractor',
                'valuation_method', 'vat_reverse_charge', 'performance_bond_required', 'fluctuations_clause',
                'possession_date', 'base_date', 'defects_liability_period_months',
                'retention_half1_release', 'retention_half2_release',
            ]);
        });
    }
};
