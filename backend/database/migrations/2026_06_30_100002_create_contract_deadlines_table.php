<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_deadlines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('project_id')->index();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('contract_ai_analysis_id')->nullable()->index();

            $table->string('name');
            $table->string('category')->default('other');
            $table->string('responsible_party')->nullable();
            $table->string('time_period_text')->nullable();
            $table->unsignedSmallInteger('time_period_days')->nullable();
            $table->string('time_direction')->nullable();
            $table->string('trigger_event')->nullable();
            $table->string('recipient')->nullable();
            $table->string('clause_reference')->nullable();
            $table->text('consequence_of_non_compliance')->nullable();
            $table->boolean('is_statutory')->default(false);
            $table->boolean('is_recurring')->default(false);
            $table->string('recurrence_description')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('generates_calendar_event')->default(true);
            $table->boolean('generates_notification')->default(true);
            $table->boolean('is_ai_generated')->default(true);
            $table->timestamp('confirmed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['contract_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_deadlines');
    }
};
