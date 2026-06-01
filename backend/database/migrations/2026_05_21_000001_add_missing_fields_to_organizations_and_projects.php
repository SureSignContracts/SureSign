<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add contact_name to organizations
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('contact_name')->nullable()->after('name');
        });

        // Add letterhead + template paths to branding_settings
        Schema::table('branding_settings', function (Blueprint $table) {
            $table->string('letterhead_path')->nullable()->after('logo_path');
            $table->string('header_template_path')->nullable()->after('letterhead_path');
            $table->string('footer_template_path')->nullable()->after('header_template_path');
        });

        // Add contract_type to projects
        Schema::table('projects', function (Blueprint $table) {
            $table->string('contract_type')->nullable()
                ->comment('JCT, NEC3, NEC4, FIDIC, bespoke, other')
                ->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('contact_name');
        });

        Schema::table('branding_settings', function (Blueprint $table) {
            $table->dropColumn(['letterhead_path', 'header_template_path', 'footer_template_path']);
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('contract_type');
        });
    }
};
