<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qa_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->foreignId('project_id')->constrained()->onDelete('cascade');
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
            $table->foreignId('inspected_by')->nullable()->constrained('users')->onDelete('set null');
            $table->unsignedSmallInteger('report_number')->default(1);
            $table->string('title');
            $table->string('inspection_type')->nullable();
            $table->string('area')->nullable();
            $table->date('inspection_date')->nullable();
            $table->enum('status', ['draft', 'open', 'failed', 'passed', 'closed'])->default('draft');
            $table->string('result')->nullable();
            $table->text('observations')->nullable();
            $table->text('corrective_action')->nullable();
            $table->boolean('follow_up_required')->default(false);
            $table->timestamps();

            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qa_reports');
    }
};
