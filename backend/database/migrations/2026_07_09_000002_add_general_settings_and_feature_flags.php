<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suresign_settings', function (Blueprint $table) {
            $table->string('platform_name')->nullable();
            $table->string('support_email')->nullable();
            $table->unsignedInteger('max_upload_mb')->default(50);
            $table->boolean('feature_document_generation')->default(true);
            $table->boolean('feature_white_label')->default(true);
            $table->boolean('feature_self_registration')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('suresign_settings', function (Blueprint $table) {
            $table->dropColumn([
                'platform_name',
                'support_email',
                'max_upload_mb',
                'feature_document_generation',
                'feature_white_label',
                'feature_self_registration',
            ]);
        });
    }
};
