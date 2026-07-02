<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('final_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('contract_id')->nullable();
            $table->unsignedBigInteger('trade_package_id')->nullable();
            $table->boolean('is_trade_package')->default(false);

            $table->string('reference', 20)->nullable();
            $table->string('status', 30)->default('draft');

            // ── Snapshot columns (null until Agreement transition) ──────────
            // These are written once at the agreed→ transition and then locked.
            // Before that point, FinalAccountService computes them live.
            $table->decimal('original_contract_sum', 15, 2)->nullable();
            $table->decimal('approved_variations_total', 15, 2)->nullable();
            $table->decimal('loss_and_expense_total', 15, 2)->nullable();
            $table->decimal('dayworks_total', 15, 2)->nullable();
            $table->decimal('provisional_sum_adjustment', 15, 2)->nullable();
            $table->decimal('prime_cost_sum_adjustment', 15, 2)->nullable();
            $table->decimal('contra_charges_total', 15, 2)->nullable();
            $table->decimal('other_adjustments_total', 15, 2)->nullable();
            $table->decimal('certified_to_date', 15, 2)->nullable();
            $table->decimal('paid_to_date', 15, 2)->nullable();
            $table->decimal('retention_held', 15, 2)->nullable();
            $table->decimal('retention_released', 15, 2)->nullable();
            // adjusted_contract_sum, retention_outstanding, final_balance_due
            // are computed accessors on the model — never stored.

            // ── Lifecycle timestamps ─────────────────────────────────────────
            $table->timestamp('submitted_at')->nullable();
            $table->unsignedBigInteger('submitted_by')->nullable();

            $table->timestamp('reviewed_at')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();

            $table->timestamp('agreed_at')->nullable();
            $table->unsignedBigInteger('agreed_by')->nullable();

            $table->timestamp('signed_at')->nullable();
            $table->unsignedBigInteger('signed_by')->nullable();

            $table->timestamp('final_certificate_issued_at')->nullable();

            // 28 days after final_certificate_issued_at (JCT conclusiveness window)
            $table->date('dispute_window_expires_at')->nullable();

            $table->timestamp('closed_at')->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('organization_id')->references('id')->on('organizations')->onDelete('cascade');
            $table->foreign('project_id')->references('id')->on('projects')->onDelete('cascade');
            $table->foreign('contract_id')->references('id')->on('contracts')->onDelete('cascade');
            $table->foreign('trade_package_id')->references('id')->on('trade_packages')->onDelete('cascade');

            $table->index(['project_id', 'status']);
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('final_accounts');
    }
};
