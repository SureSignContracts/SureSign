<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_deliverables', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('project_id')->index();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('contract_ai_analysis_id')->nullable()->index();

            $table->string('name');
            $table->string('category')->default('other');
            $table->boolean('required')->default(true);
            $table->string('responsible_party')->nullable();
            $table->string('due_event')->nullable();
            $table->smallInteger('due_days_before_after_event')->nullable();
            $table->string('format')->nullable();
            $table->string('copies_required')->nullable();
            $table->string('clause_reference')->nullable();
            $table->string('recipient')->nullable();
            $table->text('consequence_if_late')->nullable();
            $table->text('notes')->nullable();
            $table->string('status')->default('pending');
            $table->boolean('is_ai_generated')->default(true);
            $table->timestamp('confirmed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['contract_id', 'status']);
            $table->index(['contract_id', 'due_event']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_deliverables');
    }
};
