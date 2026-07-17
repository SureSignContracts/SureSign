import { APP_VERSION_LABEL } from '@/config/app-version';

export const SUPPORT_CATEGORIES: { value: string; label: string }[] = [
  { value: 'technical_issue', label: 'Technical issue' },
  { value: 'account_access', label: 'Account access' },
  { value: 'project_or_contract_issue', label: 'Project or contract issue' },
  { value: 'document_or_file_issue', label: 'Document or file issue' },
  { value: 'ai_analysis_issue', label: 'AI analysis issue' },
  { value: 'commercial_workflow_issue', label: 'Commercial workflow issue' },
  { value: 'billing_or_subscription', label: 'Billing or subscription' },
  { value: 'feature_request', label: 'Feature request' },
  { value: 'other', label: 'Other' },
];

// Batch 5 status workflow. 'open' is kept only for tickets created before
// this workflow existed (see backend SupportTicketStatusService) — no
// code path assigns it going forward, but it must still render sensibly.
export const SUPPORT_STATUSES = ['open', 'waiting_for_support', 'waiting_for_you', 'resolved', 'closed'] as const;
export type SupportTicketStatus = typeof SUPPORT_STATUSES[number];

export const SUPPORT_STATUS_LABELS: Record<string, string> = {
  open:                'Open',
  waiting_for_support: 'Waiting for Support',
  waiting_for_you:     'Waiting for You',
  resolved:            'Resolved',
  closed:              'Closed',
};

export const SUPPORT_STATUS_COLORS: Record<string, { bg: string; text: string }> = {
  open:                { bg: 'rgba(234,179,8,0.12)',  text: '#facc15' },
  waiting_for_support: { bg: 'rgba(234,179,8,0.12)',  text: '#facc15' },
  waiting_for_you:     { bg: 'rgba(59,130,246,0.12)', text: '#60a5fa' },
  resolved:            { bg: 'rgba(34,197,94,0.12)',  text: '#4ade80' },
  closed:              { bg: 'rgba(90,86,82,0.2)',    text: '#9a9490' },
};

/**
 * Best-effort label for "which part of SureSign was the user in" when they
 * opened Contact Support / Report a Bug / Feature Request — shown to the
 * user as removable context and stored alongside the ticket. Not exhaustive;
 * a route that matches nothing below just falls back to "Other".
 */
export function resolveModule(pathname: string): string {
  if (pathname === '/app') return 'Dashboard';
  if (pathname.startsWith('/app/projects')) return 'Projects';
  if (pathname.startsWith('/app/commercial')) return 'Commercial';
  if (pathname.startsWith('/app/site')) return 'Site Admin';
  if (pathname.startsWith('/app/documents')) return 'Documents';
  if (pathname.startsWith('/app/reports')) return 'Reports';
  if (pathname.startsWith('/app/ai')) return 'AI Assistant';
  if (pathname.startsWith('/app/team')) return 'Team';
  if (pathname.startsWith('/app/settings')) return 'Settings';
  if (pathname.startsWith('/app/help')) return 'Help';
  return 'Other';
}

/**
 * Pulls a project/trade-package id out of the route for display and as a
 * candidate to send to the backend — the backend re-validates that either id
 * actually belongs to the authenticated user's organization before trusting
 * it for anything, this is purely "what does the URL look like".
 */
export function parseRouteContext(pathname: string): { projectId: number | null; tradePackageId: number | null } {
  const projectMatch = pathname.match(/^\/app\/projects\/(\d+)/);
  const packageMatch = pathname.match(/^\/app\/projects\/\d+\/subcontracts\/(\d+)/);

  return {
    projectId: projectMatch ? Number(projectMatch[1]) : null,
    tradePackageId: packageMatch ? Number(packageMatch[1]) : null,
  };
}

/** Builds a Contact Support / Report a Bug / Feature Request link that carries the current route as context. */
export function buildSupportHref(category: string | null, pathname: string): string {
  const params = new URLSearchParams();
  if (category) params.set('category', category);
  params.set('route', pathname);
  params.set('module', resolveModule(pathname));
  return `/app/help/support?${params.toString()}`;
}

function parseBrowser(ua: string): string {
  if (/Edg\//.test(ua)) return 'Edge';
  if (/OPR\//.test(ua)) return 'Opera';
  if (/Firefox\//.test(ua)) return 'Firefox';
  if (/Chrome\//.test(ua) && !/Chromium/.test(ua)) return 'Chrome';
  if (/Safari\//.test(ua) && !/Chrome\//.test(ua)) return 'Safari';
  return 'Unknown';
}

function parseOS(ua: string): string {
  if (/Windows/.test(ua)) return 'Windows';
  if (/Mac OS X/.test(ua)) return 'macOS';
  if (/Android/.test(ua)) return 'Android';
  if (/iPhone|iPad|iPod/.test(ua)) return 'iOS';
  if (/Linux/.test(ua)) return 'Linux';
  return 'Unknown';
}

export interface Diagnostics {
  browser: string;
  os: string;
  viewport_width: number;
  viewport_height: number;
  language: string;
  timezone: string;
  app_version: string;
}

/**
 * Only ever reads navigator/window fields that are safe to share — never
 * tokens, headers, cookies, or storage contents. See the Batch 2 report /
 * project-context.md for the full list of what this deliberately excludes.
 */
export function collectDiagnostics(): Diagnostics {
  const ua = navigator.userAgent;
  return {
    browser: parseBrowser(ua),
    os: parseOS(ua),
    viewport_width: window.innerWidth,
    viewport_height: window.innerHeight,
    language: navigator.language,
    timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
    app_version: APP_VERSION_LABEL,
  };
}
