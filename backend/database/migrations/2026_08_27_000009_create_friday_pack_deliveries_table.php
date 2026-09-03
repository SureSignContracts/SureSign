<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Automated Friday Pack, V1F — one durable row per recipient per Send
 * initiation. This IS the authoritative recipient snapshot for a given
 * delivery — created once, at Send time, from whatever
 * FridayPackSettings.recipients held at that exact moment. A later
 * settings change never touches an existing row here.
 *
 * `document_id` is copied from FridayPack.pdf_document_id at initiation
 * and never changes afterward — this is what makes "the distributed PDF
 * is locked once any delivery row exists" enforceable: as long as this
 * table has a row for a pack, FridayPackPdfService refuses to regenerate/
 * repoint that pack's PDF (see that service).
 *
 * `public_token` is the opaque, unguessable identifier used in the
 * signed public download link — never the numeric id, matching
 * Appointment.public_token's own established convention.
 *
 * UNIQUE(friday_pack_id, recipient_email) is the authoritative
 * duplicate-recipient guard — recipient_email is stored lowercased by
 * the writing service (FridayPackDeliveryService), so this constraint is
 * meaningfully case-insensitive regardless of what the application layer
 * does.
 *
 * No SoftDeletes — this is durable audit evidence; normal application
 * code never deletes a row here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('friday_pack_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('friday_pack_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_id')->constrained()->restrictOnDelete();

            $table->string('recipient_name')->nullable();
            $table->string('recipient_email');

            $table->foreignId('initiated_by')->constrained('users');

            $table->string('public_token')->unique();

            $table->enum('status', ['pending', 'sent', 'failed'])->default('pending');
            $table->unsignedInteger('attempt_count')->default(0);

            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('failure_reason')->nullable();

            $table->string('provider_message_id')->nullable();

            $table->timestamp('link_expires_at');

            $table->timestamps();

            $table->unique(['friday_pack_id', 'recipient_email'], 'friday_pack_deliveries_pack_email_unique');
            $table->index(['friday_pack_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('friday_pack_deliveries');
    }
};
