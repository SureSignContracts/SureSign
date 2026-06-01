<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add local-mirror tracking columns to the three document-storage tables.
 *
 * mirror_status  — nullable enum-like string:
 *                    null     = mirror not attempted (feature disabled or upload pre-dates feature)
 *                    mirrored = successfully copied to local mirror
 *                    failed   = mirror attempt failed (see Laravel log for details)
 *                    disabled = mirroring explicitly skipped (path not configured)
 * mirror_path    — absolute filesystem path of the mirrored copy
 * mirrored_at    — timestamp of last successful mirror
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('file_uploads', function (Blueprint $table) {
            $table->string('mirror_status', 20)->nullable()->after('status');
            $table->string('mirror_path', 2000)->nullable()->after('mirror_status');
            $table->timestamp('mirrored_at')->nullable()->after('mirror_path');
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->string('mirror_status', 20)->nullable()->after('status');
            $table->string('mirror_path', 2000)->nullable()->after('mirror_status');
            $table->timestamp('mirrored_at')->nullable()->after('mirror_path');
        });

        Schema::table('adjudication_documents', function (Blueprint $table) {
            $table->string('mirror_status', 20)->nullable()->after('status');
            $table->string('mirror_path', 2000)->nullable()->after('mirror_status');
            $table->timestamp('mirrored_at')->nullable()->after('mirror_path');
        });
    }

    public function down(): void
    {
        Schema::table('file_uploads', function (Blueprint $table) {
            $table->dropColumn(['mirror_status', 'mirror_path', 'mirrored_at']);
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn(['mirror_status', 'mirror_path', 'mirrored_at']);
        });

        Schema::table('adjudication_documents', function (Blueprint $table) {
            $table->dropColumn(['mirror_status', 'mirror_path', 'mirrored_at']);
        });
    }
};
