<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users');
            $table->string('name');
            $table->string('code')->nullable()->comment('Internal project reference code');
            $table->text('description')->nullable();
            $table->string('status')->default('active')
                ->comment('active, on_hold, completed, cancelled');
            $table->string('type')->nullable()
                ->comment('new_build, refurbishment, fitout, infrastructure, other');
            $table->decimal('contract_value', 15, 2)->nullable();
            $table->string('currency', 3)->default('AUD');
            $table->decimal('retention_percentage', 5, 2)->default(0);
            $table->decimal('retention_cap_percentage', 5, 2)->default(0);
            $table->integer('payment_terms_days')->default(30);
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->date('practical_completion_date')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('postcode')->nullable();
            $table->string('country')->default('AU');
            $table->json('metadata')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
