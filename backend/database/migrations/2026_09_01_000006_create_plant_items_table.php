<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Friday Pack Realignment, R1E.2D — Plant & Equipment. The fourth of the
 * six H&S domain tables approved by the R1E.2 corrected schema checkpoint.
 *
 * PLANT ITEM = reusable IDENTITY of a piece/type of plant or equipment on
 * this Project, reusable across reporting weeks/deployment periods —
 * deliberately NOT organisation-wide fleet management, asset accounting,
 * maintenance scheduling, telematics, or hire billing. `status`
 * (`active`/`inactive`) is a REGISTER/operational state only — it never
 * means "on site"/"off site"; physical presence belongs exclusively to
 * `plant_deployments` (see that migration's own docblock).
 *
 * `name`/`type`/`identifier`/`owner_supplier` are all deliberately free
 * text — no hardcoded global plant enum (SureSign is worldwide) and no
 * uniqueness constraint on any of them (two plant items may legitimately
 * share the same type/name; `identifier` is nullable and not assumed
 * globally unique).
 *
 * FK delete semantics mirror `hs_inspections`/`incidents`/
 * `site_inductions` exactly: organization_id/project_id cascadeOnDelete;
 * created_by constrained with no onDelete (non-cascading — Users are
 * soft-deleted in practice, never hard-removed).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plant_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users');

            $table->string('name');
            $table->string('type');
            $table->string('identifier')->nullable();
            $table->string('owner_supplier')->nullable();
            $table->string('status')->default('active')->comment('active, inactive — register/operational state only, never presence');

            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plant_items');
    }
};
