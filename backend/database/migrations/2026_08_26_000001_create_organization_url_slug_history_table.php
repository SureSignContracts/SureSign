<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Organisation URL Branding, Phase 2 — records every `organizations.url_slug`
 * value that has ever stopped being an organisation's CURRENT slug (via a
 * change or a removal). Deliberately NOT a full audit log duplicate of
 * ActivityLog — this table exists purely to answer two questions cheaply:
 *   1. "Has any organisation ever used this slug?" (reuse prevention — see
 *      App\Http\Requests\UpdateOrganizationUrlSlugRequest)
 *   2. "Which organisation used to own this slug, and is it still active?"
 *      (historical-slug redirect — see App\Services\Organizations\
 *      OrganisationHostResolver)
 *
 * Immutable/append-only (see App\Models\OrganizationUrlSlugHistory) — a row
 * is written once, when a slug is superseded, and never updated. No
 * `updated_at` column exists for that reason.
 *
 * `url_slug` is intentionally NOT globally unique here (unlike
 * `organizations.url_slug` itself): the SAME organisation may legitimately
 * cycle a slug in and out of use over time (adopt "foo", release it, later
 * re-adopt "foo", release it again), producing more than one history row
 * with the same value for that one organisation. Cross-organisation reuse
 * prevention is an application-level check (does a history row for this
 * slug belong to a DIFFERENT organisation?), not a DB constraint, precisely
 * because of that legitimate same-organisation cycling case.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_url_slug_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('url_slug', 63);
            $table->timestamp('released_at');
            $table->timestamp('created_at')->nullable();

            $table->index('url_slug');
            $table->index(['organization_id', 'released_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_url_slug_history');
    }
};
