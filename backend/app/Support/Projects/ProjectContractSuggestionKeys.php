<?php

namespace App\Support\Projects;

/**
 * Phase E — Contract-Assisted Project Setup: the closed whitelist of
 * Project-summary suggestion keys `ProjectContractSetupSyncService` may
 * derive from a confirmed Contract and `ProjectContractSetupController`
 * may accept for application. Never an arbitrary Project column name —
 * see that service's own docblock for the full safety reasoning.
 *
 * `CONTRACT_VALUE_CURRENCY` is deliberately one grouped key, not two
 * separate ones — Contract value must never be applied without its
 * currency, and vice versa (see the service's money-safety handling).
 *
 * `PROJECT_LOCATION` is likewise one grouped key, not five separate ones
 * (address/city/region/postcode/country) — it's one logical user decision
 * ("use this confirmed location"), applied atomically. Latitude/longitude
 * are never an AI suggestion and are never *set* to any value by applying
 * this key — no coordinate is ever invented or looked up here. They ARE,
 * however, *cleared* (set to null) as an interim safety measure whenever
 * this key genuinely changes the Project's textual location, so an old map
 * pin can never keep pointing at a site the Project no longer names — see
 * ProjectContractSetupSyncService's own docblock for the full reasoning.
 */
class ProjectContractSuggestionKeys
{
    public const CONTRACT_VALUE_CURRENCY = 'contract_value_currency';
    public const START_DATE              = 'start_date';
    public const END_DATE                = 'end_date';
    public const CONTRACT_TYPE           = 'contract_type';
    public const RETENTION_PERCENTAGE    = 'retention_percentage';
    public const ORGANIZATION_ROLE       = 'organization_role';
    public const PROJECT_LOCATION        = 'project_location';

    public const ALL = [
        self::CONTRACT_VALUE_CURRENCY,
        self::START_DATE,
        self::END_DATE,
        self::CONTRACT_TYPE,
        self::RETENTION_PERCENTAGE,
        self::ORGANIZATION_ROLE,
        self::PROJECT_LOCATION,
    ];

    public static function isValid(string $key): bool
    {
        return in_array($key, self::ALL, true);
    }
}
