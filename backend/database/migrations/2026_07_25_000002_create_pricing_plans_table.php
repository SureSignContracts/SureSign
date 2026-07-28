<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pricing_plans', function (Blueprint $table) {
            $table->id();

            // Permanent internal identifier (e.g. "essential", "professional",
            // "enterprise"). Set once at creation and never editable — future
            // subscription providers (Stripe, Paddle, etc.) key off this, not
            // the display name, which sales may rename freely.
            $table->string('code')->unique();
            $table->string('slug')->unique();
            $table->string('name');
            $table->unsignedInteger('order')->default(0);

            $table->decimal('monthly_price', 10, 2)->nullable();
            // Total price billed for a full annual term — not a
            // monthly-equivalent figure. Caption via price_suffix (e.g. "/year").
            $table->decimal('annual_price', 10, 2)->nullable();
            $table->char('currency', 3)->default('GBP'); // ISO 4217

            $table->string('price_prefix')->nullable();  // e.g. "From"
            $table->string('price_suffix')->nullable();  // e.g. "/user/month"

            $table->text('description')->nullable();
            $table->string('summary')->nullable();

            $table->string('cta_text')->nullable();
            $table->string('cta_url')->nullable();
            $table->boolean('cta_new_tab')->default(false);

            $table->boolean('is_visible')->default(true);
            $table->boolean('is_popular')->default(false);

            $table->string('badge_text')->nullable();
            $table->string('badge_color')->nullable();       // enum-constrained in app layer
            $table->string('accent_color')->nullable();      // enum-constrained in app layer
            $table->string('background_style')->nullable();  // enum-constrained in app layer
            $table->string('icon')->nullable();               // allow-listed in app layer
            $table->string('custom_label')->nullable();

            $table->enum('status', ['draft', 'active', 'archived'])->default('draft');
            $table->timestamp('published_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'is_visible', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_plans');
    }
};
