<?php

namespace App\Support\Admin;

/**
 * Super Admin Configurable Admin Access — the ONE authoritative catalogue
 * of configurable Admin modules. Every permission key, its route-topology
 * classification, and its frontend label live here — nowhere else defines
 * or duplicates this list. Derived directly from AdminSidebar.tsx's real
 * nav items and their actual backend route groups (routes/api.php), not
 * invented independently — see internal-docs/super-admin/admin-access.md
 * for the full per-module route/frontend map this catalogue was built from.
 *
 * Full Parity Access Expansion (2026-08-26): every module a Super Admin
 * has is now in this catalogue, including the eight that were previously
 * permanently Super-Admin-only (Users, Application Monitoring, AI Config,
 * Storage, Support, Announcements, System Logs, Audit Log). The ONE
 * deliberate exception is not a missing catalogue key at all — it's a
 * carve-out INSIDE the Users module: granting `admin.module.users` lets
 * an Admin manage ordinary Admin/Client accounts, but an Admin can never
 * act on, or even see/list, an existing Super Admin account, and can
 * never create one (see App\Support\Auth\SuperAdminGuard's
 * assertActorMayActOnTarget()/assertActorMayAssignRole(), enforced in
 * UserController — this cannot be expressed as a route-level permission
 * check, since it depends on the specific target/intended role, not just
 * the module). The Access configuration endpoints themselves (GET/PUT
 * /users/{id}/permissions) remain role:Super Admin ONLY regardless of
 * admin.module.users — an Admin can never configure any Admin's Access,
 * full parity or not. Gate::before() in AppServiceProvider makes Super
 * Admin bypass every permission check here — Super Admin access never
 * depends on any row in this catalogue, or on any row ever being granted.
 *
 * Default-Baseline Correction (2026-08-27): `catalogue()`/`keys()` is the
 * CONFIGURABLE surface (what a Super Admin may choose to grant) — it is
 * deliberately NOT what a new Admin receives automatically. See
 * `defaultKeys()` below for the DEFAULT baseline (the original 15
 * modules); the eight modules the Full Parity Access Expansion added are
 * configurable but never default-granted.
 *
 * guard_name is always 'web' — the same single guard every Role in this
 * codebase already uses (Spatie's config('permission.teams') is false; no
 * multi-guard split exists anywhere here).
 */
class AdminAccess
{
    public const GUARD = 'web';

    /**
     * Internal initialisation sentinel — marks "this Admin's access has
     * been explicitly initialised" (full baseline OR a deliberate
     * restriction, including all the way down to zero modules).
     * Deliberately NOT a catalogue module: never appears in catalogue()/
     * keys(), is never accepted by the PUT /users/{id}/permissions
     * validation (which only accepts Rule::in(AdminAccess::keys())), and
     * never protects any module route. Its sole purpose is to distinguish
     * a legacy/never-initialised Admin (who should receive the full
     * baseline) from an Admin who has been explicitly configured — even
     * down to zero modules — who must never have that configuration
     * silently "healed" back to full access. See
     * App\Services\Admin\AdminAccessService and
     * App\Console\Commands\BackfillAdminAccess.
     */
    public const INITIALIZED_SENTINEL = 'admin.access.initialized';

    /**
     * @return array<int, array{key: string, label: string, group: string}>
     */
    public static function catalogue(): array
    {
        return [
            ['key' => 'admin.module.dashboard',          'label' => 'Dashboard',            'group' => 'Platform'],
            ['key' => 'admin.module.companies',           'label' => 'Companies',             'group' => 'Platform'],
            ['key' => 'admin.module.projects',            'label' => 'Projects',              'group' => 'Platform'],
            ['key' => 'admin.module.documents',           'label' => 'Documents',             'group' => 'Platform'],
            ['key' => 'admin.module.appointments',        'label' => 'Appointments',          'group' => 'Platform'],
            ['key' => 'admin.module.consultancy',         'label' => 'Consultancy',           'group' => 'Platform'],
            ['key' => 'admin.module.templates',           'label' => 'Templates',             'group' => 'Tools'],
            ['key' => 'admin.module.prompt_library',      'label' => 'Prompt Library',        'group' => 'Tools'],
            ['key' => 'admin.module.find_company',        'label' => 'Find Company',          'group' => 'Tools'],
            ['key' => 'admin.module.pricing',              'label' => 'Pricing',               'group' => 'Tools'],
            ['key' => 'admin.module.product_updates',     'label' => 'Product Updates',       'group' => 'Tools'],
            ['key' => 'admin.module.branding',            'label' => 'SureSign Branding',     'group' => 'Tools'],
            ['key' => 'admin.module.ai_credits',          'label' => 'AI Credits',            'group' => 'AI Credits'],
            ['key' => 'admin.module.ai_usage',             'label' => 'AI Usage & Cost',        'group' => 'System'],
            ['key' => 'admin.module.google_integration',  'label' => 'Google Integration',    'group' => 'System'],
            // Full Parity Access Expansion (2026-08-26) — see this class's
            // own docblock for the Users module's Super Admin carve-out.
            ['key' => 'admin.module.users',                'label' => 'Users',                 'group' => 'System'],
            ['key' => 'admin.module.application_monitoring', 'label' => 'Application Monitoring', 'group' => 'System'],
            ['key' => 'admin.module.ai_config',           'label' => 'AI Config',             'group' => 'System'],
            ['key' => 'admin.module.storage',              'label' => 'Storage',                'group' => 'System'],
            ['key' => 'admin.module.support',              'label' => 'Support',                'group' => 'System'],
            ['key' => 'admin.module.announcements',       'label' => 'Announcements',          'group' => 'System'],
            ['key' => 'admin.module.system_logs',          'label' => 'System Logs',            'group' => 'System'],
            ['key' => 'admin.module.audit_log',            'label' => 'Audit Log',              'group' => 'System'],
        ];
    }

    /** @return string[] */
    public static function keys(): array
    {
        return array_column(self::catalogue(), 'key');
    }

    /**
     * Default-Baseline Correction (2026-08-27): the set a newly-created,
     * restored, or promoted-to-Admin user actually receives —
     * deliberately NOT `catalogue()`/`keys()`, and deliberately NOT
     * derived by slicing/filtering that list. The Full Parity Access
     * Expansion made 23 modules configurable, but "configurable" was
     * never meant to mean "granted by default" — that conflation was a
     * real oversight this correction fixes:
     * `AdminAccessService::grantDefaultAccess()` (renamed from
     * `grantFullAccess()`) now grants exactly this list, never
     * `keys()`, so a new Admin gets the same 15-module baseline they
     * always did, and the eight formerly-permanently-Super-Admin-only
     * modules (Users, AI Config, Application Monitoring, Storage,
     * Support, Announcements, System Logs, Audit Log) require an
     * explicit Super Admin grant regardless of how new the Admin is.
     * Listed explicitly, one by one, rather than computed from
     * `catalogue()` minus the expansion set — so this list can never
     * silently drift just because the catalogue changes again later.
     *
     * @return string[]
     */
    public static function defaultKeys(): array
    {
        return [
            'admin.module.dashboard',
            'admin.module.companies',
            'admin.module.projects',
            'admin.module.documents',
            'admin.module.appointments',
            'admin.module.consultancy',
            'admin.module.templates',
            'admin.module.prompt_library',
            'admin.module.find_company',
            'admin.module.pricing',
            'admin.module.product_updates',
            'admin.module.branding',
            'admin.module.ai_credits',
            'admin.module.ai_usage',
            'admin.module.google_integration',
        ];
    }

    public static function isValidKey(string $key): bool
    {
        return in_array($key, self::keys(), true);
    }
}
