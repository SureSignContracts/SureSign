<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Threaded replies live in their own table rather than a growing JSON
        // column on support_tickets, so each reply is a real, queryable row
        // (ordering, pagination, per-message visibility/attachment) instead of
        // an opaque blob. The ticket's original message/screenshot stay on
        // support_tickets itself (unchanged) — the thread is everything that
        // comes after it, not a duplicate of it.
        Schema::create('support_ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // 'customer' | 'support' — stored explicitly at write time rather
            // than inferred from the author's role at render time, so a
            // later role change never rewrites who a past message reads as
            // having come from.
            $table->string('sender_type', 20);
            $table->text('body');
            // 'public' | 'internal' — internal notes are Admin/Super Admin
            // only and must never reach a Client API response.
            $table->string('visibility', 20)->default('public');
            $table->timestamps();

            $table->index(['support_ticket_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_messages');
    }
};
