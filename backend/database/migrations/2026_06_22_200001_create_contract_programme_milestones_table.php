<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('contract_programme_milestones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('analysis_id')->nullable()->constrained('contract_ai_analyses')->nullOnDelete();
            $table->string('name');
            $table->string('milestone_type')->default('milestone')
                ->comment('commencement,sectional_completion,completion,handover,obligation,other');
            $table->date('planned_date')->nullable();
            $table->date('forecast_date')->nullable();
            $table->date('actual_date')->nullable();
            $table->string('responsible_party')->default('contractor')
                ->comment('contractor,employer,both');
            $table->string('status')->default('not_started')
                ->comment('not_started,in_progress,complete,delayed,at_risk');
            $table->text('source_text')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_ai_generated')->default(false);
            $table->integer('sort_order')->default(0);
            $table->softDeletes();
            $table->timestamps();

            $table->index(['contract_id', 'status']);
            $table->index(['project_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_programme_milestones');
    }
};
