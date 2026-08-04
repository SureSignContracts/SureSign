// Organisation URL Branding (Phase 1, upgraded Phase 2).
//
// The marketing app is reached via ordinary hostname routing (no Next.js
// middleware layer across the whole site — see
// internal-docs/super-admin/organisation-url-branding.md's "frontend
// scope" section). The Appointment/Consultation experience components
// resolve branding client-side from the real, un-spoofable
// `window.location.hostname` the browser is actually on and forward it
// to the backend, which is the ONE authoritative place a hostname is
// classified (App\Services\Organizations\OrganisationHostResolver) —
// this file never guesses locally whether a hostname is a branded
// subdomain vs. a customer-owned domain; it always asks the backend.

const API_BASE = process.env.NEXT_PUBLIC_API_URL || 'http://localhost:8000/api';

export type HostType = 'organisation' | 'customer_domain' | 'historic_slug';

export interface OrganisationBranding {
  host_type: HostType;
  organisation_name: string;
  logo_url: string | null;
  accent_color: string;
  /** Only present when host_type === 'historic_slug' — the organisation's CURRENT canonical base URL to redirect to. Always backend-supplied, never derived from user input. */
  redirect_base_url?: string;
  /** Phase 4 — cache-busting version (the branding row's own updated_at timestamp). Already baked into logo_url's own query string; exposed separately for any other asset that needs it. */
  branding_version?: number | null;
}

const cache = new Map<string, { at: number; value: OrganisationBranding | null }>();
const CACHE_TTL_MS = 10 * 60 * 1000;

/**
 * Fetches branding for the raw hostname the browser is currently on
 * (branded subdomain OR verified customer domain — the backend resolver
 * decides which). Short client-side cache mirrors the backend's own
 * 10-minute TTL.
 */
export async function fetchOrganisationBranding(hostname: string): Promise<OrganisationBranding | null> {
  const cached = cache.get(hostname);
  if (cached && Date.now() - cached.at < CACHE_TTL_MS) {
    return cached.value;
  }

  try {
    const res = await fetch(`${API_BASE}/public/organisation-branding/${encodeURIComponent(hostname)}`, {
      headers: { Accept: 'application/json' },
    });

    // Only a DEFINITIVE outcome (a resolved payload, or a clean "no such
    // host" 404) is worth caching. Anything else — a network error, a
    // timeout, a 5xx — is transient: caching it would poison the next
    // real attempt for the rest of the TTL window, silently hiding a
    // branded organisation behind a stale failure. Those cases return
    // null WITHOUT being cached, so the very next call retries for real.
    if (res.ok) {
      const body = await res.json();
      const value: OrganisationBranding | null = body?.data ?? null;
      cache.set(hostname, { at: Date.now(), value });
      return value;
    }

    if (res.status === 404) {
      cache.set(hostname, { at: Date.now(), value: null });
      return null;
    }

    return null;
  } catch {
    return null;
  }
}

/** The current browser's real hostname, or null server-side. */
export function currentHostname(): string | null {
  if (typeof window === 'undefined') return null;
  return window.location.hostname;
}

/**
 * The header every public, token-based request should include — see
 * App\Support\Organizations\EnforcesPublicOrganizationHost on the backend.
 * Empty object server-side, so callers can always spread it safely.
 * Deliberately the RAW hostname (never pre-classified here) — the backend
 * resolver decides platform/organisation/customer-domain/unknown.
 */
export function orgHostHeader(): Record<string, string> {
  const host = currentHostname();
  return host ? { 'X-Suresign-Org-Host': host } : {};
}
