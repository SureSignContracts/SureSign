<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('project_id')->index();
            $table->unsignedBigInteger('contract_id')->nullable()->index();

            // Polymorphic source — every event originates from a real record
            $table->string('source_type');
            $table->unsignedBigInteger('source_id');
            // Distinguishes multiple events from the same source record
            // e.g. a PaymentApplication generates payment_notice_deadline, pay_less_notice_deadline, due_date
            $table->string('source_field')->default('default');

            $table->string('title');
            $table->text('description')->nullable();

            // commercial | programme | contract | compliance | payment | retention | risk | deliverables | notices
            $table->string('category')->default('other');

            $table->date('event_date');
            $table->date('due_date')->nullable();

            // pending | upcoming | due_today | overdue | completed | missed | cancelled
            $table->string('status')->default('pending');

            // low | medium | high | critical
            $table->string('priority')->default('medium');

            $table->boolean('is_recurring')->default(false);
            $table->string('recurrence_rule')->nullable();
            $table->boolean('generated_by_ai')->default(false);
            $table->boolean('generated_from_contract')->default(false);
            $table->json('metadata')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Idempotency key — one calendar event per (source, field)
            $table->unique(['source_type', 'source_id', 'source_field'], 'calendar_events_source_unique');
            $table->index(['project_id', 'event_date']);
            $table->index(['project_id', 'status']);
            $table->index(['project_id', 'category']);
            $table->index(['organization_id', 'event_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_events');
    }
};
