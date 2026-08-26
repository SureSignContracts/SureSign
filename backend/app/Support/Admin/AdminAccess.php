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
 * Deliberately EXCLUDES every module that is permanently Super-Admin-only
 * today (Users, Application Monitoring, AI Config, Storage, Support,
 * Announcements, System Logs, Audit Log — see that same doc for which of
 * these were already correctly Super-Admin-only at the route level, and
 * which had a real backend/frontend mismatch tightened as part of this
 * phase). Gate::before() in AppServiceProvider makes Super Admin bypass
 * every permission check here — Super Admin access never depends on any
 * row in this catalogue, or on any row ever being granted.
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
        ];
    }

    /** @return string[] */
    public static function keys(): array
    {
        return array_column(self::catalogue(), 'key');
    }

    public static function isValidKey(string $key): bool
    {
        return in_array($key, self::keys(), true);
    }
}
