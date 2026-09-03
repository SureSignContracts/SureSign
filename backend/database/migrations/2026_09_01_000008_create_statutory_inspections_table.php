<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Friday Pack Realignment, R1E.2E — Statutory Inspections. ONE ROW = ONE
 * INSPECTION/CHECK EVENT — deliberately not a DeliveryDocument (compliance
 * document metadata), not an HsInspection (general H&S inspection
 * activity), and not a PlantDeployment (physical site presence). All four
 * remain fully independent tables — see each model's own docblock.
 *
 * Supports BOTH plant/equipment-linked inspections (e.g. a Tower Crane's
 * lifting equipment inspection) AND non-plant inspection subjects (e.g. a
 * scaffold or temporary-works inspection) — `plant_item_id` is nullable
 * and MUST remain so; no fake PlantItem row is ever created for a
 * non-plant subject. `subject_description` is always required and always
 * an explicit, independently-recorded description of what was inspected,
 * even when `plant_item_id` is also supplied — the linked PlantItem may
 * later be renamed or soft-deleted, so a historical inspection record
 * must never rely exclusively on a live PlantItem lookup for its own
 * meaning.
 *
 * `plant_item_id` uses `restrictOnDelete()` (not `cascadeOnDelete()`) —
 * the same protective pattern `plant_deployments.plant_item_id` already
 * established in R1E.2D: normal UX deletion of a PlantItem is always a
 * SoftDelete, so this FK guards specifically against an accidental
 * PHYSICAL/hard deletion silently erasing or detaching a safety
 * inspection record. `organization_id`/`project_id` remain
 * cascadeOnDelete, matching every other Project-owned table in this
 * codebase.
 *
 * `outcome` (what the inspection found — satisfactory/issues_found) and
 * `status` (lifecycle — open/closed) are two separate columns,
 * deliberately never derived from one another — `issues_found` +
 * `closed` and `satisfactory` + `open` are both valid combinations.
 *
 * `next_due_date` is an explicitly recorded date only — SureSign never
 * calculates statutory inspection frequency (LOLER/PUWER/scaffold/any
 * jurisdiction-based formula) automatically. No validation ties it to
 * `inspection_date` — a migrated/imported record or a data-entry
 * correction may legitimately record a next-due date that already
 * exposes a past obligation; SureSign presents the recorded fact only,
 * never a derived compliance/overdue conclusion.
 *
 * Explicit short index names given proactively
 * (`stins_project_date_index`, `stins_item_date_index`), matching this
 * initiative's established discipline for names approaching MySQL's
 * 64-character limit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('statutory_inspections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plant_item_id')->nullable()->constrained('plant_items')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users');

            $table->date('inspection_date');
            $table->string('inspection_type');
            $table->string('subject_description');
            $table->string('reference')->nullable();

            $table->string('outcome');
            $table->string('status')->default('open');

            $table->text('notes')->nullable();
            $table->date('next_due_date')->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->index(['project_id', 'inspection_date'], 'stins_project_date_index');
            $table->index(['plant_item_id', 'inspection_date'], 'stins_item_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('statutory_inspections');
    }
};
