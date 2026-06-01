<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branding_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('logo_path')->nullable();
            $table->string('logo_dark_path')->nullable();
            $table->string('favicon_path')->nullable();
            $table->string('primary_color')->default('#B99566');
            $table->string('secondary_color')->default('#1a1a1a');
            $table->string('accent_color')->default('#B99566');
            $table->string('font_family')->default('Inter');
            $table->string('company_display_name')->nullable();
            $table->string('tagline')->nullable();
            $table->text('email_footer')->nullable();
            $table->string('signature_path')->nullable();
            $table->json('custom_css')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branding_settings');
    }
};
