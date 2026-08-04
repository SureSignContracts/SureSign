<?php

namespace App\Services\Organizations;

use App\Models\ActivityLog;
use App\Models\Organization;
use App\Models\OrganizationUrlSlugHistory;
use App\Models\User;
use App\Support\Organizations\BrandingCacheInvalidator;
use App\Support\Organizations\UrlSlugValidator;

/**
 * Organisation URL Branding — the ONE authoritative place a `url_slug`
 * mutation is validated and applied, used by BOTH the Super Admin
 * management endpoints (`OrganizationController::updateUrlSlug()`/
 * `removeUrlSlug()`) and the customer self-service endpoints
 * (`OrganizationBrandingUrlController`). Neither controller/Form Request
 * duplicates this logic — see each caller's own docblock.
 *
 * Deliberately does NOT check entitlement or authorisation itself — those
 * are the caller's job (a `FeatureGate`/`SubscriptionAccessPolicy` check
 * for the customer path, `role:Super Admin` route middleware for the
 * admin path). This class only knows how to validate a candidate slug and
 * apply an already-authorised change, identically regardless of who's
 * making it.
 */
class OrganizationUrlSlugService
{
    /**
     * @return string[] validation error messages — empty means valid.
     * Never distinguishes "reserved" from "taken by someone else" in a
     * way that would leak which organisation owns a hostname; every
     * message here is safe to show verbatim to the acting user (Super
     * Admin or customer).
     */
    public function validateCandidate(string $normalizedSlug, ?int $excludingOrganizationId): array
    {
        if (! UrlSlugValidator::isValidFormat($normalizedSlug)) {
            return [
                'The URL slug must be ' . UrlSlugValidator::MIN_LENGTH . '-' . UrlSlugValidator::MAX_LENGTH
                    . ' lowercase letters, numbers, or hyphens, starting and ending with a letter or number, with no consecutive hyphens.',
            ];
        }

        if (UrlSlugValidator::isReserved($normalizedSlug)) {
            return ['That URL is reserved and cannot be used.'];
        }

        $taken = Organization::where('url_slug', $normalizedSlug)
            ->when($excludingOrganizationId, fn ($q) => $q->where('id', '!=', $excludingOrganizationId))
            ->exists();

        if ($taken) {
            return ['That URL is already in use.'];
        }

        // Cross-organisation slug-reuse prevention (Phase 2) — a slug a
        // DIFFERENT organisation has ever released stays permanently
        // reserved to it. The SAME organisation reclaiming its own
        // historical slug remains allowed.
        $historicallyOwnedByAnother = OrganizationUrlSlugHistory::where('url_slug', $normalizedSlug)
            ->when($excludingOrganizationId, fn ($q) => $q->where('organization_id', '!=', $excludingOrganizationId))
            ->exists();

        if ($historicallyOwnedByAnother) {
            return ['That URL was previously used by a different organisation and cannot be reassigned.'];
        }

        return [];
    }

    /**
     * Applies an already-validated, already-authorised slug change.
     * $normalizedSlug null means "remove". Records history (Phase 2) and
     * an Activity Log entry BEFORE returning — every caller gets this for
     * free, whether Super Admin or a customer's own action.
     *
     * $actorType is a plain, safe-to-log string ('super_admin' /
     * 'organisation_customer') — recorded in the Activity Log metadata so
     * a Super Admin reviewing history can always tell whether a change
     * was self-service or an operator action, per the approved audit
     * requirement.
     */
    public function apply(
        Organization $organization,
        ?string $normalizedSlug,
        string $actorType,
        ?User $actor,
        ?string $reason = null,
    ): Organization {
        $previous = $organization->url_slug;

        if ($previous !== null && $previous !== $normalizedSlug) {
            $organization->urlSlugHistory()->create(['url_slug' => $previous, 'released_at' => now()]);
        }

        // Invalidate the PREVIOUS host's cached branding response before it
        // changes underneath it (forgetForOrganization() below only knows
        // how to compute the organisation's CURRENT host once url_slug is
        // updated) — the new host is covered by the call after update().
        $rootDomain = config('organisation_branding.root_domain');
        if ($previous !== null && $rootDomain) {
            BrandingCacheInvalidator::forgetForOrganization($organization, strtolower($previous) . '.' . strtolower($rootDomain));
        }

        $organization->update(['url_slug' => $normalizedSlug]);

        BrandingCacheInvalidator::forgetForOrganization($organization);

        $action = $normalizedSlug === null
            ? 'organization.url_branding_removed'
            : ($previous === null ? 'organization.url_branding_created' : 'organization.url_branding_changed');

        $descriptionReason = $reason ?? ($actorType === 'organisation_customer' ? 'Self-service change by organisation user.' : 'No reason provided.');

        ActivityLog::record(
            $action,
            "Organisation URL branding for \"{$organization->name}\" changed from "
                . ($previous ?? '(none)') . ' to ' . ($normalizedSlug ?? '(none)') . " (actor: {$actorType}): {$descriptionReason}",
            $actor,
            $organization,
            [
                'previous_url_slug' => $previous,
                'new_url_slug' => $normalizedSlug,
                'actor_type' => $actorType,
                'changed_by' => $actor?->id,
                'reason' => $descriptionReason,
                'changed_at' => now()->toIso8601String(),
            ],
            null,
            $organization->id,
        );

        return $organization->fresh();
    }
}
