<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Automated Friday Pack, V1B — one settings row per project. Deliberately a
 * dedicated table, not a JSON column on `projects` — Friday Pack is a
 * substantial independent feature with its own lifecycle and future
 * scheduling/delivery settings (product decision, V1B spec). V1B supports
 * only `enabled`/`included_sections` — schedule/recipients/delivery/
 * reviewer/approver/timezone are deliberately deferred to later phases, not
 * added as dormant columns now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('friday_pack_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(true);
            $table->json('included_sections');
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('friday_pack_settings');
    }
};
