<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_risks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('project_id')->index();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('contract_ai_analysis_id')->nullable()->index();

            $table->string('title');
            $table->text('description')->nullable();
            $table->string('severity')->default('medium');
            $table->string('category')->default('other');
            $table->string('clause_reference')->nullable();
            $table->string('commercial_impact')->nullable();
            $table->string('programme_impact')->nullable();
            $table->string('compliance_impact')->nullable();
            $table->string('urgency')->default('monitor');
            $table->text('recommended_action')->nullable();
            $table->string('risk_owner')->nullable();
            $table->boolean('is_non_standard_amendment')->default(false);
            $table->string('status')->default('open');
            $table->boolean('is_ai_generated')->default(true);
            $table->timestamp('confirmed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['contract_id', 'severity']);
            $table->index(['contract_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_risks');
    }
};
