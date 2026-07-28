<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pricing_plan_features', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('pricing_plans')->cascadeOnDelete();
            $table->foreignId('feature_id')->constrained('pricing_features')->cascadeOnDelete();
            $table->enum('status', ['included', 'not_included', 'limited', 'custom'])->default('not_included');
            $table->string('value_text')->nullable();
            $table->string('icon_override')->nullable(); // allow-listed in app layer
            $table->timestamps();

            $table->unique(['plan_id', 'feature_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_plan_features');
    }
};
