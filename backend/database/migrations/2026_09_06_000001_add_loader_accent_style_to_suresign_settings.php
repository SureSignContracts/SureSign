<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suresign_settings', function (Blueprint $table) {
            // 'mint' | 'monochrome' — which accent the global branded loading
            // screen (SureSignLoader) draws itself in. 'monochrome' is the
            // default (a deliberate choice, not merely the column default —
            // see SuresignSettingController::guestShow()'s own fallback).
            $table->string('loader_accent_style')->default('monochrome')->after('favicon_path');
        });
    }

    public function down(): void
    {
        Schema::table('suresign_settings', function (Blueprint $table) {
            $table->dropColumn('loader_accent_style');
        });
    }
};
