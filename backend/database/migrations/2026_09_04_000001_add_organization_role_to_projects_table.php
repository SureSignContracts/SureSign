<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            // Phase A — Project Organization Role foundation. Describes how the
            // owning Organization is acting on THIS Project (main_contractor,
            // subcontractor, employer, consultant, other) — deliberately
            // independent of Organization identity (no organizations.type
            // exists, and none is being added here), of Contract/TradePackage
            // party fields (which remain authoritative for each individual
            // agreement), and of SureSign user roles/permissions. See
            // App\Support\Projects\ProjectOrganizationRole for the canonical
            // value list.
            //
            // Nullable, no default, no backfill — every existing Project gets
            // null ("Role not set"), never a fabricated historical value.
            $table->string('organization_role')->nullable()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('organization_role');
        });
    }
};
