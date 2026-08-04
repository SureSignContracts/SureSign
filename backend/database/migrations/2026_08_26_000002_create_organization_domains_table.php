<?php

use App\Support\Organizations\DomainStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Organisation URL Branding, Phase 2 — customer-owned domains (Bring Your
 * Own Domain), e.g. contracts.star-affinity.co.uk. Deliberately a separate
 * table from `organizations` (never overloaded onto it) — one organisation
 * may eventually own more than one domain, and this lifecycle (pending →
 * awaiting_dns → verified → active, or → failed/disabled/removed) has
 * nothing in common with the organisation row itself.
 *
 * `hostname` is globally unique and PERMANENT once claimed, regardless of
 * status (including `removed`) — unlike `organizations.url_slug`/its
 * history table, there is no same-organisation-reuse allowance here. A
 * customer domain is a much rarer, higher-stakes claim (a real external DNS
 * record someone had to configure) than an internal branded slug, so this
 * migration deliberately keeps the simpler, stricter invariant: once a
 * hostname has been claimed by anyone, freeing it for reuse (by the same or
 * a different organisation) requires an explicit Super Admin hard-delete of
 * the row, never an automatic transition. See DomainStatus's own docblock
 * for the full lifecycle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('hostname')->unique();
            $table->string('status', 20)->default(DomainStatus::PENDING);
            $table->string('verification_token');
            $table->string('verification_method', 20)->default('txt');
            $table->timestamp('last_checked_at')->nullable();
            $table->string('last_check_result')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_domains');
    }
};
