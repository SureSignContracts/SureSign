<?php

namespace App\Support\Organizations;

use App\Models\Organization;
use App\Models\OrganizationDomain;

/**
 * Organisation URL Branding, Phase 2 — the result of
 * App\Services\Organizations\OrganisationHostResolver::resolve(). A plain,
 * immutable value object rather than a bare array so every caller checks
 * `$type` explicitly instead of guessing which keys are populated.
 */
final class HostResolution
{
    public const TYPE_ORGANISATION = 'organisation';
    public const TYPE_CUSTOMER_DOMAIN = 'customer_domain';
    public const TYPE_HISTORIC_SLUG = 'historic_slug';
    public const TYPE_NONE = 'none';

    private function __construct(
        public readonly string $type,
        public readonly ?Organization $organization = null,
        public readonly ?OrganizationDomain $domain = null,
    ) {
    }

    public static function organisation(Organization $organization): self
    {
        return new self(self::TYPE_ORGANISATION, $organization);
    }

    public static function customerDomain(Organization $organization, OrganizationDomain $domain): self
    {
        return new self(self::TYPE_CUSTOMER_DOMAIN, $organization, $domain);
    }

    /** The host matched a slug that USED to be this (still-active) organisation's current one. */
    public static function historicSlug(Organization $organization): self
    {
        return new self(self::TYPE_HISTORIC_SLUG, $organization);
    }

    /** No organisation resolved — either a platform host, or genuinely unknown. Callers must not distinguish the two (see resolver docblock). */
    public static function none(): self
    {
        return new self(self::TYPE_NONE);
    }

    public function isResolved(): bool
    {
        return $this->type !== self::TYPE_NONE;
    }
}
