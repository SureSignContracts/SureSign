<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_application_variations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variation_id')->constrained()->cascadeOnDelete();
            $table->string('variation_number_at_inclusion');
            $table->string('title_at_inclusion');
            $table->text('description_at_inclusion')->nullable();
            $table->decimal('amount_at_inclusion', 15, 2)->default(0);
            $table->string('status_at_inclusion')->default('approved');
            $table->timestamps();

            $table->unique(['payment_application_id', 'variation_id'], 'pav_unique');
            $table->index('payment_application_id');
        });

        Schema::table('payment_applications', function (Blueprint $table) {
            $table->decimal('linked_variations_total', 15, 2)->nullable()->default(0)
                ->after('materials_on_site_total');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_application_variations');
        Schema::table('payment_applications', function (Blueprint $table) {
            $table->dropColumn('linked_variations_total');
        });
    }
};
