<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Mark whether an organization has completed first-time onboarding
        Schema::table('organizations', function (Blueprint $table) {
            $table->boolean('is_onboarded')->default(false)->after('is_active');
        });

        // Extended branding fields
        Schema::table('branding_settings', function (Blueprint $table) {
            $table->string('cover_image_path')->nullable()->after('logo_path');
            $table->text('description')->nullable()->after('tagline');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('is_onboarded');
        });

        Schema::table('branding_settings', function (Blueprint $table) {
            $table->dropColumn(['cover_image_path', 'description']);
        });
    }
};
