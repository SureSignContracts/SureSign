<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_notices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('project_id')->index();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('contract_ai_analysis_id')->nullable()->index();

            $table->string('name');
            $table->string('notice_type')->default('other');
            $table->boolean('is_statutory')->default(false);
            $table->string('responsible_party')->nullable();
            $table->text('trigger')->nullable();
            $table->unsignedSmallInteger('time_limit_days')->nullable();
            $table->string('time_direction')->nullable();
            $table->string('time_reference_point')->nullable();
            $table->string('recipient')->nullable();
            $table->string('clause_reference')->nullable();
            $table->json('required_content')->nullable();
            $table->text('consequence_if_missed')->nullable();
            $table->boolean('can_be_withheld')->default(false);
            $table->text('notes')->nullable();
            $table->boolean('is_ai_generated')->default(true);
            $table->timestamp('confirmed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['contract_id', 'notice_type']);
            $table->index(['contract_id', 'is_statutory']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_notices');
    }
};
