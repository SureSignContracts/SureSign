<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suresign_settings', function (Blueprint $table) {
            $table->id();

            // Branding
            $table->string('logo_path')->nullable();

            // Document Settings
            $table->string('letterhead_header_path')->nullable();
            $table->string('letterhead_footer_path')->nullable();
            $table->string('letterhead_pdf_path')->nullable();

            // Email Settings
            $table->string('email_header_path')->nullable();
            $table->string('email_footer_path')->nullable();
            $table->string('email_reply_to')->nullable();
            $table->string('email_subject_line')->nullable()->default('You have a new document from SureSign');
            $table->text('email_body_template')->nullable();
            $table->string('brevo_api_key')->nullable();

            // Site Settings
            $table->string('currency')->default('GBP');
            $table->string('currency_symbol')->default('£');
            $table->string('date_format')->default('DD/MM/YYYY');
            $table->string('timezone')->default('Europe/London');

            $table->timestamps();
        });

        // Seed a single default row so the app always has a settings record
        DB::table('suresign_settings')->insert([
            'currency'           => 'GBP',
            'currency_symbol'    => '£',
            'date_format'        => 'DD/MM/YYYY',
            'timezone'           => 'Europe/London',
            'email_subject_line' => 'You have a new document from SureSign',
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('suresign_settings');
    }
};
