<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_obligations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('project_id')->index();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('contract_ai_analysis_id')->nullable()->index();

            $table->string('party');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('clause_reference')->nullable();
            $table->string('time_period_text')->nullable();
            $table->unsignedSmallInteger('time_period_days')->nullable();
            $table->string('trigger_event')->nullable();
            $table->text('consequence_if_missed')->nullable();
            $table->boolean('generates_deadline')->default(false);
            $table->string('category')->default('other');
            $table->boolean('is_ai_generated')->default(true);
            $table->timestamp('confirmed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['contract_id', 'party']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_obligations');
    }
};
