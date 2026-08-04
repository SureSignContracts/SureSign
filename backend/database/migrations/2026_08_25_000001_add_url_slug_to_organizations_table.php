<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Organisation URL Branding, Phase 1 — a dedicated `url_slug` column for
 * organisation-branded SureSign hostnames (e.g. star-affinity.suresigncontracts.app),
 * deliberately SEPARATE from the existing `organizations.slug` column.
 *
 * `slug` already drives local storage folder naming (ProjectStorageService)
 * and is silently regenerated whenever an organisation's name changes
 * (OrganizationController) — coupling a public, DNS-facing hostname to that
 * existing behaviour would mean a routine name edit silently breaks a
 * customer's bookmarked/shared branded URL. `url_slug` is therefore:
 *   - nullable (branding is opt-in; no slug means the organisation keeps
 *     using the default SureSign hostname);
 *   - never auto-generated from the organisation name;
 *   - changed only via an explicit Super Admin action
 *     (App\Http\Controllers\Api\OrganizationController::updateUrlSlug()).
 *
 * Validation (format, reserved names, DNS-label length) lives in
 * App\Support\Organizations\UrlSlugValidator — this migration only enforces
 * uniqueness at the database layer as the final safety net.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('url_slug', 63)->nullable()->unique()->after('slug');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('url_slug');
        });
    }
};
