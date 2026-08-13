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
 */
class ProjectContractSuggestionKeys
{
    public const CONTRACT_VALUE_CURRENCY = 'contract_value_currency';
    public const START_DATE              = 'start_date';
    public const END_DATE                = 'end_date';
    public const CONTRACT_TYPE           = 'contract_type';
    public const RETENTION_PERCENTAGE    = 'retention_percentage';
    public const ORGANIZATION_ROLE       = 'organization_role';

    public const ALL = [
        self::CONTRACT_VALUE_CURRENCY,
        self::START_DATE,
        self::END_DATE,
        self::CONTRACT_TYPE,
        self::RETENTION_PERCENTAGE,
        self::ORGANIZATION_ROLE,
    ];

    public static function isValid(string $key): bool
    {
        return in_array($key, self::ALL, true);
    }
}
