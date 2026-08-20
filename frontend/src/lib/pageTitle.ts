// Shared browser-title architecture — the one place a `document.title`
// string gets composed and the one place a route maps to a human-readable
// page/module label. See CLAUDE.md's "Dynamic Browser Titles" section.
//
// Hierarchy: Page/Module -> Project (when relevant) -> Organisation (when
// relevant) -> SureSign. SureSign always stays the final platform suffix —
// this phase does not remove it or make it configurable.
//
// Never hardcode a real company/project name here — every contextual value
// is passed in by the caller from already-loaded auth/organisation/project
// data, never fetched by this module.

const SURESIGN = 'SureSign';

// Presentation-only truncation for an unusually long Project/Organisation
// name — never mutates the stored value, only what's shown in the title.
const MAX_SEGMENT_LENGTH = 60;

function truncate(value: string): string {
  const trimmed = value.trim();
  if (trimmed.length <= MAX_SEGMENT_LENGTH) return trimmed;
  return `${trimmed.slice(0, MAX_SEGMENT_LENGTH - 1).trimEnd()}…`;
}

/**
 * Composes the final `document.title` string.
 *
 * - No page              -> "SureSign"
 * - Page only            -> "Dashboard | SureSign"
 * - Page + Organisation  -> "Settings | Star Pacific | SureSign"
 * - Page + Project (+Org)-> "Commercial — Colchester | Star Pacific | SureSign"
 */
export function buildPageTitle(opts: {
  page?: string | null;
  project?: string | null;
  organization?: string | null;
}): string {
  const page = opts.page?.trim();
  if (!page) return SURESIGN;

  const project = opts.project?.trim();
  const organization = opts.organization?.trim();

  const heading = project ? `${page} — ${truncate(project)}` : page;
  const parts = [heading];
  if (organization) parts.push(truncate(organization));
  parts.push(SURESIGN);
  return parts.join(' | ');
}

// ---------------------------------------------------------------------------
// Project workspace modules — one label per top-level route segment under
// /app/projects/{id}/, matching ProjectSidebar.tsx's own nav labels. A
// deeper detail route (e.g. a single drawing or subcontract package) falls
// back to its owning module's label rather than fetching the record's own
// name a second time.
// ---------------------------------------------------------------------------

const PROJECT_MODULE_LABELS: Record<string, string> = {
  overview: 'Overview',
  contracts: 'Contracts',
  commercial: 'Commercial',
  variations: 'Variations',
  notices: 'Notices',
  programme: 'Programme',
  'delay-eot': 'Delay & EOT',
  risks: 'Risk Register',
  rfis: 'RFIs',
  meetings: 'Meetings',
  qa: 'QA Reports',
  snagging: 'Snagging',
  'site-reports': 'Site Reports',
  'delivery-documents': 'Delivery Documents',
  drawings: 'Drawings',
  closeout: 'Closeout',
  adjudication: 'Adjudication',
  documents: 'Documents',
  calendar: 'Calendar',
  subcontracts: 'Subcontracts',
  setup: 'Project Setup',
};

const PROJECT_ROUTE_PATTERN = /^\/app\/projects\/[^/]+\/([^/]+)/;

export function getProjectModuleLabel(pathname: string | null | undefined): string | null {
  if (!pathname) return null;
  const match = pathname.match(PROJECT_ROUTE_PATTERN);
  if (!match) return null;
  return PROJECT_MODULE_LABELS[match[1]] ?? null;
}

/**
 * Resolves the Organisation segment for a Project workspace title, reusing
 * the SAME branded display-name source Organisation-level pages use
 * (`branding_settings.company_display_name`) whenever it's already
 * available in memory with zero extra request — the authenticated user's
 * own `organization.branding`, already loaded onto `useAuthStore` at
 * login/`fetchUser()`.
 *
 * That's only safe when the viewer's own organisation IS the project's
 * organisation — true for every ordinary Client user (tenant isolation
 * means a Client can only ever reach their own organisation's projects),
 * but not for a Super Admin/Admin viewing another organisation's project.
 * In that mismatched (or no-viewer-organisation) case, this falls back to
 * the plain organisation name `ProjectController::show()` already returns
 * with the Project — never a guess, never a second fetch, never another
 * organisation's branding leaking onto this one's project.
 */
export function resolveProjectOrganizationTitleName(params: {
  projectOrganizationId?: number | null;
  projectOrganizationName?: string | null;
  viewerOrganizationId?: number | null;
  viewerOrganizationBrandName?: string | null;
}): string | null {
  const {
    projectOrganizationId,
    projectOrganizationName,
    viewerOrganizationId,
    viewerOrganizationBrandName,
  } = params;

  const sameOrganization =
    projectOrganizationId != null &&
    viewerOrganizationId != null &&
    projectOrganizationId === viewerOrganizationId;

  if (sameOrganization && viewerOrganizationBrandName) {
    return viewerOrganizationBrandName;
  }
  return projectOrganizationName ?? null;
}

// ---------------------------------------------------------------------------
// Authenticated /app pages (Organisation-scoped, non-project). Project
// workspace routes are deliberately excluded here — ProjectLayout owns
// those exclusively via getProjectModuleLabel above.
// ---------------------------------------------------------------------------

const APP_PAGE_LABELS: Record<string, string> = {
  '/app': 'Dashboard',
  '/app/projects': 'Projects',
  '/app/commercial': 'Commercial',
  '/app/site': 'Site Admin',
  '/app/documents': 'Documents',
  '/app/reports': 'Reports',
  '/app/ai': 'AI Assistant',
  '/app/consultations': 'Consultancy',
  '/app/team': 'Team',
  '/app/help': 'Help',
  '/app/notifications': 'Notifications',
  '/app/onboarding': 'Onboarding',
  '/app/whats-new': "What's New",
  '/app/settings': 'Settings',
  '/app/settings/billing': 'Billing',
  '/app/settings/billing/subscription': 'Subscription',
  '/app/settings/usage': 'Usage',
  '/app/settings/privacy': 'Privacy Policy',
  '/app/settings/terms': 'Terms of Service',
  '/app/settings/releases': 'Release Notes',
};

// Longest prefix first, so e.g. /app/settings/billing/subscription doesn't
// get caught by the shorter /app/settings/billing entry.
const APP_PAGE_PREFIXES = Object.keys(APP_PAGE_LABELS).sort((a, b) => b.length - a.length);

export function getAppPageLabel(pathname: string | null | undefined): string | null {
  if (!pathname) return null;
  // Project workspace routes own their own title — never resolved here,
  // regardless of whether the caller already knows to skip this route.
  if (/^\/app\/projects\/[^/]+/.test(pathname)) return null;
  for (const prefix of APP_PAGE_PREFIXES) {
    if (pathname === prefix || pathname.startsWith(`${prefix}/`)) {
      return APP_PAGE_LABELS[prefix];
    }
  }
  return null;
}

// ---------------------------------------------------------------------------
// Admin / Super Admin platform-management pages — deliberately never carry
// an Organisation segment (see CLAUDE.md: these remain "<Page> | SureSign").
// /admin/suresign is excluded — it's a single tab-driven settings hub whose
// active tab lives in local component state, not the URL, so it owns its
// own title directly (see app/admin/suresign/page.tsx).
// ---------------------------------------------------------------------------

const ADMIN_PAGE_LABELS: Record<string, string> = {
  '/admin': 'Dashboard',
  '/admin/companies': 'Companies',
  '/admin/projects': 'Projects',
  '/admin/documents': 'Documents',
  '/admin/appointments': 'Appointments',
  '/admin/consultancy': 'Consultancy',
  '/admin/users': 'Users',
  '/admin/templates': 'Templates',
  '/admin/prompts': 'Prompt Library',
  '/admin/find': 'Find Company',
  '/admin/pricing': 'Pricing',
  '/admin/product-updates': 'Product Updates',
  '/admin/ai-credits': 'AI Credits',
  '/admin/ai-configurations': 'AI Config',
  '/admin/ai-usage': 'AI Usage & Cost',
  '/admin/google-integration': 'Google Integration',
  '/admin/application-monitoring': 'Application Monitoring',
  '/admin/storage': 'Storage',
  '/admin/support': 'Support',
  '/admin/announcements': 'Announcements',
  '/admin/system-logs': 'System Logs',
  '/admin/audit-log': 'Audit Log',
  '/admin/settings': 'System Settings',
  '/admin/notifications': 'Notifications',
};

const ADMIN_PAGE_PREFIXES = Object.keys(ADMIN_PAGE_LABELS).sort((a, b) => b.length - a.length);

/**
 * Returns `undefined` for a route that owns its own title elsewhere
 * (currently only /admin/suresign) — the caller should skip setting
 * `document.title` entirely in that case, rather than briefly overwriting
 * that page's own, more specific title.
 */
export function getAdminPageLabel(pathname: string | null | undefined): string | null | undefined {
  if (!pathname) return null;
  if (pathname === '/admin/suresign' || pathname.startsWith('/admin/suresign/')) return undefined;
  for (const prefix of ADMIN_PAGE_PREFIXES) {
    if (pathname === prefix || pathname.startsWith(`${prefix}/`)) {
      return ADMIN_PAGE_LABELS[prefix];
    }
  }
  return null;
}
