<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Project team members
        Schema::create('project_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role')->default('member')
                ->comment('project_manager, contract_admin, site_manager, member, observer');
            $table->timestamp('assigned_at')->useCurrent();
            $table->timestamps();

            $table->unique(['project_id', 'user_id']);
        });

        // Project contacts (external parties: clients, contractors, consultants)
        Schema::create('project_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('company')->nullable();
            $table->string('role')->nullable()
                ->comment('client, contractor, subcontractor, consultant, supplier, other');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->text('address')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_contacts');
        Schema::dropIfExists('project_users');
    }
};
