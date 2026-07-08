<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('loss_and_expense_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            // Belongs to a contract OR a trade package, same either-or pattern
            // as delay_events / eot_requests.
            $table->foreignId('contract_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('trade_package_id')->nullable()->constrained('trade_packages')->nullOnDelete();

            // Optional grounds — an L&E claim is usually raised off the back
            // of a delay event and/or its EOT, but doesn't have to be.
            $table->foreignId('delay_event_id')->nullable()->constrained('delay_events')->nullOnDelete();
            $table->foreignId('eot_request_id')->nullable()->constrained('eot_requests')->nullOnDelete();

            // Set once a Final Account item has been created from this claim
            // (either immediately on agreement, or seeded when the Final
            // Account is later created) — prevents double-seeding.
            $table->foreignId('final_account_item_id')->nullable()->constrained('final_account_items')->nullOnDelete();

            $table->foreignId('created_by')->constrained('users');

            $table->integer('claim_number');
            $table->string('title');
            $table->text('description')->nullable();

            $table->decimal('amount_claimed', 15, 2)->nullable();
            $table->decimal('amount_assessed', 15, 2)->nullable();
            $table->decimal('amount_agreed', 15, 2)->nullable();

            $table->string('status')->default('draft')
                ->comment('draft, submitted, under_assessment, agreed, rejected');
            $table->text('notes')->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->index(['project_id', 'status']);
            $table->index('contract_id');
            $table->index('trade_package_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loss_and_expense_claims');
    }
};
