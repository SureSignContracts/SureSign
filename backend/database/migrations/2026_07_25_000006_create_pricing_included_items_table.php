<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pricing_included_items', function (Blueprint $table) {
            $table->id();
            $table->string('text');
            $table->string('icon')->nullable(); // allow-listed in app layer
            $table->unsignedInteger('order')->default(0);
            $table->boolean('is_visible')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_included_items');
    }
};
