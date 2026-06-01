<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users');
            $table->string('type')
                ->comment('main_contract, subcontract, consultant_appointment, supplier_agreement');
            $table->string('title');
            $table->string('reference_number')->nullable();
            $table->string('form_of_contract')->nullable()
                ->comment('JCT SBC, JCT IFC, NEC4, FIDIC, bespoke, etc.');
            $table->string('party_name')->nullable()->comment('The other contracting party');
            $table->decimal('contract_sum', 15, 2)->nullable();
            $table->string('currency', 3)->default('AUD');
            $table->decimal('retention_percentage', 5, 2)->default(0);
            $table->decimal('retention_cap_percentage', 5, 2)->default(0);
            $table->integer('payment_terms_days')->default(30);
            $table->date('execution_date')->nullable();
            $table->date('commencement_date')->nullable();
            $table->date('completion_date')->nullable();
            $table->string('status')->default('draft')
                ->comment('draft, active, on_hold, completed, terminated, disputed');
            $table->text('notes')->nullable();
            $table->json('key_dates')->nullable();
            $table->json('key_obligations')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contracts');
    }
};
