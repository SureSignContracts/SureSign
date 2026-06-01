<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trade_package_folders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('trade_package_id');
            $table->string('name');
            $table->string('key');
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('trade_package_id')->references('id')->on('trade_packages')->onDelete('cascade');
            $table->unique(['trade_package_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trade_package_folders');
    }
};
