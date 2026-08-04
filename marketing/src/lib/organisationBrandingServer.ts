import { cache } from 'react';
import { headers } from 'next/headers';
import type { OrganisationBranding } from './organisationBranding';
import { isHostnameSyntacticallyValid } from './hostnameValidation';

export { isHostnameSyntacticallyValid };

// Organisation URL Branding, Phase 4 — the server-side counterpart to
// organisationBranding.ts's client-only fetch. Metadata generation,
// middleware, and per-segment opengraph-image/icon routes all run on the
// server and have no `window.location` to read — this resolves the same
// thing from the *request's own* Host header instead, via the same
// backend endpoint the client uses.
//
// Server-side fetches from this app use BACKEND_INTERNAL_URL (the
// Docker-internal service address), never NEXT_PUBLIC_API_URL — see
// lib/pricing.ts's own docblock for why (`localhost` inside this
// container means the container itself, not the backend container).

const API_BASE = process.env.BACKEND_INTERNAL_URL || process.env.NEXT_PUBLIC_API_URL || 'http://localhost:8000/api';

const FETCH_TIMEOUT_MS = 2500;

/**
 * Reads the browser's real requested hostname from the incoming request —
 * `x-forwarded-host` first (set by a reverse proxy in front of this
 * container), falling back to the plain `host` header. Strips a port
 * suffix (local dev only — e.g. "acme.localhost:3002") since the backend
 * resolver/UrlSlugValidator never deal in ports.
 */
export async function resolveRequestHost(): Promise<string | null> {
  const h = await headers();
  const raw = h.get('x-forwarded-host') || h.get('host');
  if (!raw) return null;

  const withoutPort = raw.split(':')[0].toLowerCase().trim();
  return withoutPort || null;
}

export type BrandingLookupResult =
  | { status: 'resolved'; data: OrganisationBranding }
  | { status: 'not_found' }
  | { status: 'unavailable' };

/**
 * Tri-state, not boolean — the whole point (see Phase 4 architecture
 * notes): an authoritative "no such organisation" (a clean 404) must
 * never be conflated with "couldn't tell" (network error, timeout,
 * unexpected status from the backend). Every caller (middleware,
 * generateMetadata, opengraph-image/icon routes) treats `unavailable`
 * identically to "render the normal default experience" — never a false
 * 404 caused by a transient backend hiccup.
 *
 * Wrapped in React's `cache()` so multiple callers within the SAME
 * request/render (generateMetadata + the page component + its
 * opengraph-image/icon siblings) share one resolution rather than each
 * independently hitting the network. Middleware runs as a genuinely
 * separate invocation (no shared React cache scope with the route
 * handler that follows it) — that one remaining duplicate call is
 * accepted rather than engineered away, since the underlying `fetch()`
 * below still goes through Next's own Data Cache (`revalidate: 60`), so
 * the second call is a cheap cache hit, not a second real network
 * round-trip, in the common case.
 */
export const fetchOrganisationBrandingServer = cache(async (host: string): Promise<BrandingLookupResult> => {
  if (!isHostnameSyntacticallyValid(host)) {
    return { status: 'unavailable' };
  }

  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), FETCH_TIMEOUT_MS);

  try {
    const res = await fetch(`${API_BASE}/public/organisation-branding/${encodeURIComponent(host)}`, {
      headers: { Accept: 'application/json' },
      signal: controller.signal,
      // Deliberately shorter than the backend's own 10-minute
      // (org-branding:{host}) cache TTL — the backend already owns real
      // invalidation on a branding save (see
      // App\Support\Organizations\BrandingCacheInvalidator); this just
      // bounds how long THIS app's own fetch cache can keep serving a
      // pre-invalidation response, without needing a cross-app
      // revalidation callback.
      next: { revalidate: 60 },
    });

    if (res.ok) {
      const body = await res.json();
      if (body?.data) {
        return { status: 'resolved', data: body.data as OrganisationBranding };
      }
      return { status: 'unavailable' };
    }

    if (res.status === 404) {
      return { status: 'not_found' };
    }

    return { status: 'unavailable' };
  } catch {
    return { status: 'unavailable' };
  } finally {
    clearTimeout(timeout);
  }
});
