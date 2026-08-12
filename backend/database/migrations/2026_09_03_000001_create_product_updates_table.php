<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_updates', function (Blueprint $table) {
            $table->id();
            $table->string('title', 150);
            $table->string('summary', 500);
            $table->text('content');
            // Deliberately small, non-extensible taxonomy per spec ("do not
            // create excessive taxonomy") — mirrors PlatformAnnouncement's
            // own enum('severity', ...) convention.
            $table->enum('category', ['new_feature', 'improvement', 'important_update', 'tip'])->default('new_feature');
            $table->string('cta_label', 60)->nullable();
            $table->string('cta_url', 255)->nullable();
            // Who this update is shown to — see ProductUpdate::AUDIENCES.
            // 'client' is the default: the primary use case is customer-
            // facing product communication, matching the spec's own default
            // audience description ("All authenticated customer users").
            $table->enum('audience', ['all', 'client', 'operator'])->default('client');
            $table->enum('status', ['draft', 'published', 'archived'])->default('draft');
            // Set once, the first time status becomes 'published' — never
            // overwritten on a later edit or re-publish, so ordering by this
            // column always reflects when an update first went live.
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'audience', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_updates');
    }
};
