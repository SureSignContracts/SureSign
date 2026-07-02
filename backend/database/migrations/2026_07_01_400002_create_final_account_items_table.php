<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('final_account_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('final_account_id');
            $table->string('category', 50);
            $table->string('description');
            $table->string('source_type', 100)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->decimal('amount', 15, 2)->default(0);
            $table->boolean('is_auto_seeded')->default(false);
            $table->text('notes')->nullable();
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('final_account_id')->references('id')->on('final_accounts')->onDelete('cascade');

            $table->index(['final_account_id', 'category']);
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('final_account_items');
    }
};
