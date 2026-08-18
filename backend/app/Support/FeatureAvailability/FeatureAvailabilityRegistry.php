<?php

namespace App\Support\FeatureAvailability;

/**
 * SureSign Feature Availability — the authoritative, code-defined catalogue
 * of pages/modules a Super Admin may place into Maintenance or Coming Soon.
 * Mirrors the App\Support\Entitlements\Feature / PlanEntitlementRepository
 * relationship structurally (code registry = catalogue, DB rows = mutable
 * overrides, missing row = default) — but is otherwise a COMPLETELY
 * SEPARATE system: this class must never be confused with, merged into, or
 * read by App\Support\Entitlements\Feature or App\Services\Entitlements\
 * FeatureGate. Those are per-organisation COMMERCIAL entitlements; this is
 * GLOBAL PLATFORM OPERATIONAL state, controlled only by Super Admin, with
 * no relationship to subscriptions, plans, or billing whatsoever.
 *
 * Also deliberately separate from App\Models\SuresignSetting's
 * `hidden_pages` column — that system answers "should this nav item be
 * removed from the sidebar entirely" (a binary visible/hidden toggle, with
 * the page still reachable and fully functional via direct URL). This
 * registry answers a different question: "should this page's own content
 * be replaced by a Maintenance/Coming Soon state, while the nav item stays
 * visible and the route still resolves." Neither system reads the other.
 *
 * Every entry here was confirmed against real routes/navigation during the
 * Feature Availability discovery + Phase A route-ownership audit (see
 * internal-docs/super-admin/feature-availability.md) — this is
 * deliberately NOT a blind transcription of an aspirational feature list.
 * Excluded on purpose (entry-point/cross-cutting areas that must remain
 * continuously reachable): project overview, the organisation dashboard,
 * the organisation projects list, and the project calendar (which
 * aggregates deadlines sourced from several other modules). Deferred on
 * purpose (a legitimate future module, not required for V1): Consultancy.
 *
 * `coming_soon_supported` is true for only two entries in V1 —
 * `ai.assistant` (CLAUDE.md confirms its controller methods genuinely do
 * not exist yet — the single cleanest, fully truthful Coming Soon case in
 * the platform) and `organization.reports` (the Report Library already
 * ships some reports with a hardcoded, uncentralized "(coming soon)" label
 * for others not yet built — see reports/page.tsx's own `report.available`
 * flags, left unmigrated in this phase as documented follow-up debt). Every
 * other already-shipped module is `coming_soon_supported => false` — being
 * recently shipped is not sufficient justification for a Coming Soon state
 * (Phase A instruction); only definitive repository evidence of genuinely
 * unreleased functionality would justify adding a new one later.
 *
 * DB rows only ever store the mutable override (status/message/
 * available_at/updated_by) for a key already listed here — see
 * FeatureAvailabilityService/FeatureAvailability model. An unregistered key
 * can never become unavailable "by accident": every read path validates
 * against ALL first and fails open to Active for anything not listed here.
 */
final class FeatureAvailabilityRegistry
{
    public const CATEGORY_PROJECT = 'Project';
    public const CATEGORY_ORGANIZATION = 'Organization';
    public const CATEGORY_PLATFORM = 'Platform';

    /**
     * @var array<string, array{
     *   label: string,
     *   description: string,
     *   category: string,
     *   frontend_routes: string[],
     *   maintenance_supported: bool,
     *   coming_soon_supported: bool,
     * }>
     */
    private const REGISTRY = [
        // ─── Project workspace modules ──────────────────────────────────
        'project.contracts' => [
            'label' => 'Contracts',
            'description' => 'Contract records, AI analysis, and related documents for a project.',
            'category' => self::CATEGORY_PROJECT,
            'frontend_routes' => ['/app/projects/{id}/contracts'],
            'maintenance_supported' => true,
            'coming_soon_supported' => false,
        ],
        'project.commercial' => [
            'label' => 'Commercial',
            'description' => 'Payment applications, payment notices, and final accounts for a project.',
            'category' => self::CATEGORY_PROJECT,
            'frontend_routes' => ['/app/projects/{id}/commercial'],
            'maintenance_supported' => true,
            'coming_soon_supported' => false,
        ],
        'project.variations' => [
            'label' => 'Variations',
            'description' => 'Instruction, quotation, assessment, and approval of project variations.',
            'category' => self::CATEGORY_PROJECT,
            'frontend_routes' => ['/app/projects/{id}/variations'],
            'maintenance_supported' => true,
            'coming_soon_supported' => false,
        ],
        'project.notices' => [
            'label' => 'Notices',
            'description' => 'Pay-less notices, site instructions, and EOT request notices for a project.',
            'category' => self::CATEGORY_PROJECT,
            'frontend_routes' => ['/app/projects/{id}/notices'],
            'maintenance_supported' => true,
            'coming_soon_supported' => false,
        ],
        'project.programme' => [
            'label' => 'Programme',
            'description' => 'Contract programme milestones for a project.',
            'category' => self::CATEGORY_PROJECT,
            'frontend_routes' => ['/app/projects/{id}/programme'],
            'maintenance_supported' => true,
            'coming_soon_supported' => false,
        ],
        'project.delay_eot' => [
            'label' => 'Delay & EOT',
            'description' => 'Delay events, extension-of-time requests, and loss & expense claims.',
            'category' => self::CATEGORY_PROJECT,
            'frontend_routes' => ['/app/projects/{id}/delay-eot'],
            'maintenance_supported' => true,
            'coming_soon_supported' => false,
        ],
        'project.risks' => [
            'label' => 'Risk Register',
            'description' => 'Contract risk register for a project.',
            'category' => self::CATEGORY_PROJECT,
            'frontend_routes' => ['/app/projects/{id}/risks'],
            'maintenance_supported' => true,
            'coming_soon_supported' => false,
        ],
        'project.rfis' => [
            'label' => 'RFIs',
            'description' => 'Requests for information and their attachments.',
            'category' => self::CATEGORY_PROJECT,
            'frontend_routes' => ['/app/projects/{id}/rfis'],
            'maintenance_supported' => true,
            'coming_soon_supported' => false,
        ],
        'project.meetings' => [
            'label' => 'Meetings',
            'description' => 'Meeting minutes for a project.',
            'category' => self::CATEGORY_PROJECT,
            'frontend_routes' => ['/app/projects/{id}/meetings'],
            'maintenance_supported' => true,
            'coming_soon_supported' => false,
        ],
        'project.qa' => [
            'label' => 'QA Reports',
            'description' => 'Quality assurance reports and attachments.',
            'category' => self::CATEGORY_PROJECT,
            'frontend_routes' => ['/app/projects/{id}/qa'],
            'maintenance_supported' => true,
            'coming_soon_supported' => false,
        ],
        'project.snagging' => [
            'label' => 'Snagging',
            'description' => 'Snag items and their attachments.',
            'category' => self::CATEGORY_PROJECT,
            'frontend_routes' => ['/app/projects/{id}/snagging'],
            'maintenance_supported' => true,
            'coming_soon_supported' => false,
        ],
        'project.site_reports' => [
            'label' => 'Site Reports',
            'description' => 'Site diary entries for a project.',
            'category' => self::CATEGORY_PROJECT,
            'frontend_routes' => ['/app/projects/{id}/site-reports'],
            'maintenance_supported' => true,
            'coming_soon_supported' => false,
        ],
        'project.delivery_documents' => [
            'label' => 'Delivery Documents',
            'description' => 'Delivery/handover documents for a project.',
            'category' => self::CATEGORY_PROJECT,
            'frontend_routes' => ['/app/projects/{id}/delivery-documents'],
            'maintenance_supported' => true,
            'coming_soon_supported' => false,
        ],
        'project.drawings' => [
            'label' => 'Drawings',
            'description' => 'Drawing registers, revisions, and hotspot links for a project.',
            'category' => self::CATEGORY_PROJECT,
            'frontend_routes' => ['/app/projects/{id}/drawings'],
            'maintenance_supported' => true,
            // Recently shipped is not, by itself, sufficient justification
            // for Coming Soon (explicit Phase A instruction) — this module
            // is real and functional today.
            'coming_soon_supported' => false,
        ],
        'project.closeout' => [
            'label' => 'Closeout',
            'description' => 'Project closeout checklist and items.',
            'category' => self::CATEGORY_PROJECT,
            'frontend_routes' => ['/app/projects/{id}/closeout'],
            'maintenance_supported' => true,
            'coming_soon_supported' => false,
        ],
        'project.adjudication' => [
            'label' => 'Adjudication',
            'description' => 'Adjudication cases, documents, and deadlines for a project.',
            'category' => self::CATEGORY_PROJECT,
            'frontend_routes' => ['/app/projects/{id}/adjudication'],
            'maintenance_supported' => true,
            'coming_soon_supported' => false,
        ],
        'project.documents' => [
            'label' => 'Documents',
            'description' => 'The project document explorer and per-module document listings.',
            'category' => self::CATEGORY_PROJECT,
            'frontend_routes' => ['/app/projects/{id}/documents'],
            'maintenance_supported' => true,
            'coming_soon_supported' => false,
        ],

        // ─── Organization-level modules ─────────────────────────────────
        'organization.commercial' => [
            'label' => 'Commercial',
            'description' => 'Organisation-wide commercial monitoring/triage overview.',
            'category' => self::CATEGORY_ORGANIZATION,
            'frontend_routes' => ['/app/commercial'],
            'maintenance_supported' => true,
            'coming_soon_supported' => false,
        ],
        'organization.site_admin' => [
            'label' => 'Site Admin',
            'description' => 'Organisation-wide site administration overview.',
            'category' => self::CATEGORY_ORGANIZATION,
            'frontend_routes' => ['/app/site'],
            'maintenance_supported' => true,
            'coming_soon_supported' => false,
        ],
        'organization.documents' => [
            'label' => 'Documents',
            'description' => 'Organisation-wide document portfolio.',
            'category' => self::CATEGORY_ORGANIZATION,
            'frontend_routes' => ['/app/documents'],
            'maintenance_supported' => true,
            'coming_soon_supported' => false,
        ],
        'organization.reports' => [
            'label' => 'Reports',
            'description' => 'The organisation report library (summary, commercial summary, and future reports).',
            'category' => self::CATEGORY_ORGANIZATION,
            'frontend_routes' => ['/app/reports'],
            'maintenance_supported' => true,
            // The Report Library already contains real, shipped reports
            // alongside genuinely unreleased ones (see reports/page.tsx's
            // own per-report `available` flag) — a truthful Coming Soon
            // candidate at the module level for a future report-scale
            // rollout, distinct from the per-report flag which stays
            // unmigrated in this phase.
            'coming_soon_supported' => true,
        ],
        'organization.team' => [
            'label' => 'Team',
            'description' => 'Organisation user/team management.',
            'category' => self::CATEGORY_ORGANIZATION,
            'frontend_routes' => ['/app/team'],
            'maintenance_supported' => true,
            'coming_soon_supported' => false,
        ],

        // ─── Platform / AI ───────────────────────────────────────────────
        'ai.assistant' => [
            'label' => 'AI Assistant',
            'description' => 'The conversational AI assistant chat page.',
            'category' => self::CATEGORY_PLATFORM,
            'frontend_routes' => ['/app/ai'],
            // Nothing exists yet to "maintain" — see CLAUDE.md's confirmed
            // finding that AiController has no corresponding chat methods.
            'maintenance_supported' => false,
            'coming_soon_supported' => true,
        ],
    ];

    public const ALL = [
        'project.contracts',
        'project.commercial',
        'project.variations',
        'project.notices',
        'project.programme',
        'project.delay_eot',
        'project.risks',
        'project.rfis',
        'project.meetings',
        'project.qa',
        'project.snagging',
        'project.site_reports',
        'project.delivery_documents',
        'project.drawings',
        'project.closeout',
        'project.adjudication',
        'project.documents',
        'organization.commercial',
        'organization.site_admin',
        'organization.documents',
        'organization.reports',
        'organization.team',
        'ai.assistant',
    ];

    public static function isValid(string $featureKey): bool
    {
        return array_key_exists($featureKey, self::REGISTRY);
    }

    /**
     * @return array{label: string, description: string, category: string, frontend_routes: string[], maintenance_supported: bool, coming_soon_supported: bool}|null
     */
    public static function get(string $featureKey): ?array
    {
        return self::REGISTRY[$featureKey] ?? null;
    }

    /**
     * @return array<string, array{label: string, description: string, category: string, frontend_routes: string[], maintenance_supported: bool, coming_soon_supported: bool}>
     */
    public static function all(): array
    {
        return self::REGISTRY;
    }

    /**
     * Whether $status is a status this specific registry entry supports —
     * ACTIVE is always supported for every registered feature; MAINTENANCE/
     * COMING_SOON are gated per-entry by maintenance_supported/
     * coming_soon_supported. Returns false for an unregistered key (nothing
     * is "supported" for a key that doesn't exist).
     */
    public static function supportsStatus(string $featureKey, string $status): bool
    {
        $entry = self::get($featureKey);
        if ($entry === null) {
            return false;
        }

        return match ($status) {
            FeatureAvailabilityStatus::ACTIVE => true,
            FeatureAvailabilityStatus::MAINTENANCE => $entry['maintenance_supported'],
            FeatureAvailabilityStatus::COMING_SOON => $entry['coming_soon_supported'],
            default => false,
        };
    }
}
