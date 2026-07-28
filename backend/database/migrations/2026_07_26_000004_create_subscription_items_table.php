<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Supports future seat billing, add-ons, AI usage products, and storage
     * packages — a subscription may have several. This checkpoint only ever
     * creates one primary plan item per subscription; the schema allows more
     * without a later migration.
     */
    public function up(): void
    {
        Schema::create('subscription_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();

            $table->string('item_type', 30); // e.g. 'plan', 'seat', 'addon', 'ai_usage', 'storage'
            $table->string('code');
            $table->string('name');

            $table->string('provider_subscription_item_id')->nullable();
            $table->string('provider_price_id')->nullable();

            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedBigInteger('unit_amount')->nullable(); // minor units
            $table->char('currency', 3);
            $table->string('billing_interval', 20)->nullable();

            $table->json('metadata_json')->nullable();

            $table->timestamps();

            $table->index('subscription_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_items');
    }
};
