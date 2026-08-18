<?php

namespace App\Services\FeatureAvailability;

use App\Models\FeatureAvailability;
use App\Models\User;
use App\Support\FeatureAvailability\FeatureAvailabilityCacheInvalidator;
use App\Support\FeatureAvailability\FeatureAvailabilityRegistry;
use App\Support\FeatureAvailability\FeatureAvailabilityStatus;
use App\Support\FeatureAvailability\FeatureAvailabilityUnavailableException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The authoritative read/evaluation layer for SureSign Feature Availability
 * — the single place every other part of the codebase should ask "is this
 * feature available right now, and to whom." Completely separate from
 * App\Services\Entitlements\FeatureGate (per-organisation commercial
 * entitlements) — this class holds no Subscription/Organization/billing
 * dependency of any kind, and resolves identically for every organisation
 * (this is global platform configuration, not tenant-scoped).
 *
 * Resolution order, mirroring App\Support\AI\AiCreditOperatingMode's
 * "one authoritative accessor, fail safe to the safe default" shape:
 *
 *   1. Unregistered/unknown feature key → ACTIVE (logged as a warning —
 *      this indicates a code bug, e.g. a typo'd key, never a real
 *      availability decision. Never allowed to disable something by
 *      accident just because it isn't in the registry.)
 *   2. No `feature_availabilities` row for a registered key → ACTIVE
 *      (the architecture's central invariant: "no row = Active").
 *   3. A row exists with a valid, recognised status → that status.
 *   4. A row exists with a corrupt/unrecognised status string → ACTIVE
 *      (FeatureAvailabilityStatus::normalize() enforces this) — logged as
 *      a warning, since this should never happen through this service's
 *      own write path, only through a direct DB anomaly.
 *   5. The underlying lookup (DB/cache) throws → ACTIVE for every feature
 *      (never Maintenance) — a broken availability subsystem must never
 *      accidentally take the whole platform offline. See allEffective().
 *
 * Bypass: Super Admin and Admin (both platform-wide roles in this
 * codebase — see CLAUDE.md's Authorization section) always resolve as
 * available for READ/ACCESS purposes via isAvailableToUser()/
 * requireAvailable() — this is an access bypass ONLY. It grants no
 * permission to manage Feature Availability itself; only a Super-Admin-only
 * management API/controller may mutate a row (see
 * FeatureAvailabilityAdminController). Client never bypasses.
 */
class FeatureAvailabilityService
{
    private const CACHE_TTL_SECONDS = 300;

    /**
     * The effective status for a feature key, ignoring who's asking —
     * ACTIVE for anything unregistered, anything with no row, or anything
     * whose lookup failed.
     */
    public function statusFor(string $featureKey): string
    {
        return $this->entryFor($featureKey)['status'];
    }

    /**
     * @return array{status: string, message: ?string, available_at: ?\Illuminate\Support\Carbon}
     */
    public function entryFor(string $featureKey): array
    {
        if (!FeatureAvailabilityRegistry::isValid($featureKey)) {
            Log::warning('FeatureAvailabilityService: unregistered feature key requested — resolving Active.', [
                'feature_key' => $featureKey,
            ]);

            return $this->activeEntry();
        }

        $overrides = $this->allOverridesSafe();

        return $overrides[$featureKey] ?? $this->activeEntry();
    }

    public function isActive(string $featureKey): bool
    {
        return $this->statusFor($featureKey) === FeatureAvailabilityStatus::ACTIVE;
    }

    public function isMaintenance(string $featureKey): bool
    {
        return $this->statusFor($featureKey) === FeatureAvailabilityStatus::MAINTENANCE;
    }

    public function isComingSoon(string $featureKey): bool
    {
        return $this->statusFor($featureKey) === FeatureAvailabilityStatus::COMING_SOON;
    }

    /**
     * @return array<string, array{status: string, message: ?string, available_at: ?\Illuminate\Support\Carbon}>
     *   Only NON-active overrides for registered keys — a missing key means
     *   Active, by design (mirrors the customer status API's own sparse
     *   payload contract).
     */
    public function allEffective(): array
    {
        $overrides = $this->allOverridesSafe();

        return array_filter($overrides, fn (array $entry) => $entry['status'] !== FeatureAvailabilityStatus::ACTIVE);
    }

    /**
     * Full registry combined with effective state, for the Super Admin
     * management screen — every registered key appears, including ones
     * with no override row (shown as Active with null message/available_at
     * and no updated_by).
     *
     * @return array<string, array{
     *   label: string, description: string, category: string, frontend_routes: string[],
     *   maintenance_supported: bool, coming_soon_supported: bool,
     *   status: string, message: ?string, available_at: ?\Illuminate\Support\Carbon,
     *   updated_by: ?int, updated_at: ?\Illuminate\Support\Carbon,
     * }>
     */
    public function fullRegistryWithState(): array
    {
        $overrides = $this->rawOverridesByKey();
        $result = [];

        foreach (FeatureAvailabilityRegistry::all() as $key => $meta) {
            /** @var FeatureAvailability|null $row */
            $row = $overrides[$key] ?? null;

            $result[$key] = array_merge($meta, [
                'status' => $row ? FeatureAvailabilityStatus::normalize($row->status) : FeatureAvailabilityStatus::ACTIVE,
                'message' => $row->message ?? null,
                'available_at' => $row->available_at ?? null,
                'updated_by' => $row->updated_by ?? null,
                'updated_at' => $row?->updated_at,
            ]);
        }

        return $result;
    }

    /**
     * Access-only bypass check — Super Admin/Admin are always treated as
     * available, regardless of the stored status. This is NOT a management
     * permission check; only the Super-Admin-only admin controller may
     * mutate availability.
     */
    public function isAvailableToUser(string $featureKey, ?User $user): bool
    {
        if ($this->userBypasses($user)) {
            return true;
        }

        return $this->isActive($featureKey);
    }

    /**
     * Throws when a feature is genuinely unavailable to this user (i.e. not
     * a Super Admin/Admin bypass) — the exception carries only the feature
     * key and effective status, safe for a caller to build a customer-facing
     * response from directly.
     */
    public function requireAvailable(string $featureKey, ?User $user): void
    {
        if ($this->isAvailableToUser($featureKey, $user)) {
            return;
        }

        throw new FeatureAvailabilityUnavailableException($featureKey, $this->statusFor($featureKey));
    }

    /**
     * Super Admin and Admin both bypass for ACCESS purposes — both are
     * platform-wide roles in this codebase (see CLAUDE.md's Authorization
     * conventions / project_admin_org_scoping_gap memory), unlike a
     * Client, who is organisation-scoped and never bypasses. This mirrors
     * the same role check every authorize() method in this codebase already
     * uses — never inferred from frontend state.
     */
    private function userBypasses(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return $user->hasRole('Super Admin') || $user->hasRole('Admin');
    }

    private function activeEntry(): array
    {
        return ['status' => FeatureAvailabilityStatus::ACTIVE, 'message' => null, 'available_at' => null];
    }

    /**
     * @return array<string, array{status: string, message: ?string, available_at: ?\Illuminate\Support\Carbon}>
     *   Keyed by feature_key, for every row currently in the table
     *   (regardless of its status) — fails safe to an empty array (i.e.
     *   every feature resolves Active) if the underlying lookup throws.
     *   Never allows a broken DB/cache to disable the platform.
     */
    private function allOverridesSafe(): array
    {
        try {
            return Cache::remember(
                FeatureAvailabilityCacheInvalidator::CACHE_KEY,
                self::CACHE_TTL_SECONDS,
                function (): array {
                    $result = [];
                    foreach (FeatureAvailability::query()->get() as $row) {
                        $result[$row->feature_key] = [
                            'status' => FeatureAvailabilityStatus::normalize($row->status),
                            'message' => $row->message,
                            'available_at' => $row->available_at,
                        ];
                    }

                    return $result;
                }
            );
        } catch (\Throwable $e) {
            Log::warning('FeatureAvailabilityService: lookup failed — resolving every feature as Active.', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @return array<string, FeatureAvailability>
     */
    private function rawOverridesByKey(): array
    {
        try {
            return FeatureAvailability::query()->get()->keyBy('feature_key')->all();
        } catch (\Throwable $e) {
            Log::warning('FeatureAvailabilityService: failed to load override rows for management view.', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }
}
