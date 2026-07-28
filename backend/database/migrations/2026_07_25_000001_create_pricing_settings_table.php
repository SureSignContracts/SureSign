<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pricing_settings', function (Blueprint $table) {
            $table->id();

            $table->string('hero_title')->nullable();
            $table->text('hero_subtitle')->nullable();
            $table->string('section_title')->nullable();

            $table->boolean('monthly_billing_enabled')->default(true);
            $table->boolean('annual_billing_enabled')->default(true);
            $table->string('discount_label')->nullable();

            $table->string('everything_included_title')->nullable();
            $table->text('everything_included_subtitle')->nullable();

            $table->string('final_cta_title')->nullable();
            $table->text('final_cta_subtitle')->nullable();

            $table->string('primary_cta_text')->nullable();
            $table->string('primary_cta_url')->nullable();
            $table->boolean('primary_cta_new_tab')->default(false);
            $table->string('secondary_cta_text')->nullable();
            $table->string('secondary_cta_url')->nullable();
            $table->boolean('secondary_cta_new_tab')->default(false);

            // Whether the whole Pricing page is live on the marketing site —
            // lets Super Admin stage plans/copy before flipping it public.
            $table->boolean('published')->default(false);

            $table->timestamps();
        });

        DB::table('pricing_settings')->insert([
            'hero_title'    => 'Simple, transparent pricing',
            'hero_subtitle' => 'Choose the plan that fits how your team runs contracts.',
            'section_title' => 'Plans',
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_settings');
    }
};
