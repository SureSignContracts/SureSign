<?php

namespace App\Support\Projects;

/**
 * How the owning Organization is acting on a specific Project — e.g. a
 * specialist contractor may be `subcontractor` on one Project and
 * `main_contractor` on another. Deliberately independent of:
 *   - the SureSign user role (Super Admin/Admin/Client) — an application
 *     permission, never a construction position;
 *   - `App\Models\Organization` — which has no general business-type field
 *     and does not gain one here; the same Organization can hold Projects
 *     with different roles without any Organization-level value changing;
 *   - `App\Models\Client` / Contract / TradePackage party fields — those
 *     remain the authoritative legal parties for each individual agreement,
 *     never derived from or overwritten by this value.
 *
 * Deliberately does NOT include:
 *   - `client` — already a three-way overloaded term in this codebase (the
 *     Spatie `Client` account role, the `App\Models\Client` Employer/Customer
 *     CRM record, and `ContractRisk::category`'s `client` value) — adding a
 *     fourth stored meaning here was explicitly rejected during discovery.
 *   - a generic "contractor" — deliberately split into `main_contractor`/
 *     `subcontractor` so the value is unambiguous worldwide.
 *
 * Nullable at the database level — `null` means "not set," never a
 * fabricated default. Never read by any authorization check, billing
 * calculation, AI workflow, or Contract/TradePackage logic.
 */
class ProjectOrganizationRole
{
    public const MAIN_CONTRACTOR = 'main_contractor';
    public const SUBCONTRACTOR   = 'subcontractor';
    public const EMPLOYER        = 'employer';
    public const CONSULTANT      = 'consultant';
    public const OTHER           = 'other';

    public const ALL = [
        self::MAIN_CONTRACTOR,
        self::SUBCONTRACTOR,
        self::EMPLOYER,
        self::CONSULTANT,
        self::OTHER,
    ];

    public static function isValid(string $role): bool
    {
        return in_array($role, self::ALL, true);
    }

    /**
     * For `Illuminate\Validation` `in:` rules — see ProjectController.
     */
    public static function validationRule(): string
    {
        return 'in:' . implode(',', self::ALL);
    }
}
