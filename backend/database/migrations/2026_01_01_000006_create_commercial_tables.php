<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Payment applications / interim certificates
        Schema::create('payment_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users');
            $table->integer('application_number');
            $table->string('reference')->nullable();
            $table->date('application_date');
            $table->date('due_date')->nullable();
            $table->decimal('gross_valuation', 15, 2)->default(0);
            $table->decimal('less_retention', 15, 2)->default(0);
            $table->decimal('less_previous_payments', 15, 2)->default(0);
            $table->decimal('amount_due', 15, 2)->default(0);
            $table->decimal('certified_amount', 15, 2)->nullable();
            $table->date('certified_date')->nullable();
            $table->date('payment_date')->nullable();
            $table->decimal('paid_amount', 15, 2)->nullable();
            $table->string('status')->default('draft')
                ->comment('draft, submitted, certified, pay_less_notice_issued, paid, disputed');
            $table->text('notes')->nullable();
            $table->json('breakdown')->nullable()->comment('Line item breakdown JSON');
            $table->softDeletes();
            $table->timestamps();

            $table->index(['contract_id', 'application_number']);
        });

        // Pay Less Notices
        Schema::create('pay_less_notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users');
            $table->date('notice_date');
            $table->decimal('notified_sum', 15, 2);
            $table->text('basis_of_difference');
            $table->string('status')->default('draft')->comment('draft, issued, disputed');
            $table->timestamps();
        });

        // Variations
        Schema::create('variations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users');
            $table->integer('variation_number');
            $table->string('reference')->nullable();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('instruction_type')
                ->comment('addition, omission, substitution, provisional_sum, daywork');
            $table->decimal('quoted_amount', 15, 2)->nullable();
            $table->decimal('agreed_amount', 15, 2)->nullable();
            $table->string('status')->default('pending')
                ->comment('pending, submitted, approved, rejected, on_hold');
            $table->integer('programme_impact_days')->default(0);
            $table->date('instruction_date')->nullable();
            $table->date('response_due_date')->nullable();
            $table->text('notes')->nullable();
            $table->json('supporting_data')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['contract_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('variations');
        Schema::dropIfExists('pay_less_notices');
        Schema::dropIfExists('payment_applications');
    }
};
