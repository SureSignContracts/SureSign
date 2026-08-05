// Organisation URL Branding, Phase 5 (Stage 2B) — the ONE authoritative
// frontend hostname-context resolver. Mirrors
// marketing/lib/organisationBranding.ts's own discipline exactly (these
// are two separate Next.js deployments with no shared package, so this is
// a deliberate parallel copy, not an accidental duplication within one
// app) — never guesses locally whether a hostname is a branded subdomain
// vs. a customer-owned domain; always asks the backend's one authoritative
// resolver.
//
// This is PRE-AUTH, requested-workspace context only. After login,
// `/auth/me` and the authenticated user's own organization membership
// remain authoritative — see AppLayout's post-login enforcement (Stage 3).
// A resolved 'organisation'/'historical_redirect' context here never
// proves membership, only which workspace the visitor is requesting.

const API_BASE = process.env.NEXT_PUBLIC_API_URL || 'http://localhost:8000/api';

export type HostContextType =
  | 'platform'
  | 'organisation'
  | 'historical_redirect'
  | 'not_found'
  | 'unavailable';

export interface HostContextBranding {
  organisation_name: string;
  logo_url: string | null;
  accent_color: string;
}

export interface HostContextResult {
  type: HostContextType;
  host: string;
  branding?: HostContextBranding;
  /** Only present when type === 'historical_redirect'. Always backend-supplied — never construct a redirect destination from user input. */
  redirect_base_url?: string;
}

/**
 * The fixed platform app host (bare hostname, e.g. "app.suresigncontracts.app"),
 * derived from NEXT_PUBLIC_APP_HOST (a full origin) — build-time only, see
 * frontend/Dockerfile. Falsy/malformed configuration is treated the same
 * as "no platform host configured," never thrown. Exported — AppLayout's
 * outage-behaviour split (Stage 3 policy revisit) reuses this SAME check
 * rather than re-parsing NEXT_PUBLIC_APP_HOST itself.
 */
export function isCurrentHostPlatform(host: string): boolean {
  return host === 'localhost' || host === '127.0.0.1' || host === platformHost();
}

function platformHost(): string | null {
  const raw = process.env.NEXT_PUBLIC_APP_HOST;
  if (!raw) return null;
  try {
    return new URL(raw).hostname.toLowerCase();
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
 * Resolves the requested workspace context for the current browser
 * hostname. Never called with a caller-supplied hostname — always the
 * real, un-spoofable `window.location.hostname`.
 *
 * Outage safety: a network failure resolves to 'unavailable', explicitly
 * distinct from 'not_found' — see this function's own contract: neither
 * outcome ever authorizes anything, but a genuine backend outage must
 * never be misreported as "this workspace doesn't exist," which could
 * needlessly alarm a legitimate customer.
 */
export async function resolveHostContext(): Promise<HostContextResult> {
  const host = currentHostname();
  if (!host) {
    // Server-side render with no real browser hostname available yet —
    // treat as unavailable, never guess.
    return { type: 'unavailable', host: '' };
  }

  if (isCurrentHostPlatform(host)) {
    return { type: 'platform', host };
  }

  try {
    const res = await fetch(`${API_BASE}/public/organisation-branding/${encodeURIComponent(host)}`, {
      headers: { Accept: 'application/json' },
    });

    if (res.status === 404) {
      return { type: 'not_found', host };
    }

    if (!res.ok) {
      // A real error (5xx, malformed response) — outage-safe, not a
      // negative classification.
      return { type: 'unavailable', host };
    }

    const body = await res.json();
    const data = body?.data;
    if (!data?.organisation_name) {
      return { type: 'unavailable', host };
    }

    if (data.host_type === 'historic_slug') {
      return {
        type: 'historical_redirect',
        host,
        redirect_base_url: data.redirect_base_url,
      };
    }

    return {
      type: 'organisation',
      host,
      branding: {
        organisation_name: data.organisation_name,
        logo_url: data.logo_url ?? null,
        accent_color: data.accent_color,
      },
    };
  } catch {
    return { type: 'unavailable', host };
  }
}
