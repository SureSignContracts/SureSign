<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Deliberately a lean "which updates has this user dismissed" record
        // — NOT a per-user row fanned out at publish time (unlike
        // suresign_notifications). A row here exists only once a user has
        // actually clicked "Don't show this update again" for that specific
        // update; a user with no row for a given update simply hasn't
        // dismissed it yet.
        Schema::create('product_update_dismissals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_update_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('dismissed_at');
            $table->timestamps();

            $table->unique(['product_update_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_update_dismissals');
    }
};
