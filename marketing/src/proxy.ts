import { NextResponse, type NextRequest } from 'next/server';
import { fetchOrganisationBrandingServer, isHostnameSyntacticallyValid } from '@/lib/organisationBrandingServer';

// Organisation URL Branding, Phase 4 — the marketing edge's host
// classification. Deliberately narrow in scope (see the Phase 4
// architecture notes in internal-docs/super-admin/organisation-url-branding.md):
// this proxy ONLY decides whether to pass a request through untouched or
// rewrite it to the neutral "workspace not found" page. It never
// authenticates, never resolves tenant permissions or authorisation, never
// mutates branding state, never generates customer URLs, and makes at
// most one backend call per request.
//
// Named `proxy.ts` (not `middleware.ts`) — Next.js 16.2 deprecated the
// `middleware` file convention in favour of `proxy`
// (https://nextjs.org/docs/messages/middleware-to-proxy). A Proxy file
// always runs on the Node.js runtime (Next enforces this — a `runtime`
// route-segment config is actually disallowed here, unlike the old
// middleware convention), which is exactly what this needs to reach
// BACKEND_INTERNAL_URL over the Docker network — confirmed empirically in
// the dev compose stack (see the Phase 4 docs for the exact commands and
// observations), not merely assumed from framework docs.
export const config = {
  matcher: [
    // Everything except Next internals, static assets, and known file
    // extensions — a request for the actual page content is what needs
    // classifying; a request for a font/image doesn't.
    '/((?!_next/static|_next/image|favicon.ico|.*\\.(?:svg|png|jpg|jpeg|gif|webp|ico|css|js|txt|xml)$).*)',
  ],
};

// Hosts that are always the plain, default SureSign marketing experience —
// checked first, with zero branding lookup, since this is overwhelmingly
// the common case. "localhost" covers local dev (docker-compose.dev.yml
// exposes this app on localhost:3002); the production hosts mirror the
// ones already hardcoded across this app's own SEO metadata (layout.tsx,
// sitemap.ts, etc.) plus an operator-configurable override for a staging
// domain that isn't literally suresigncontracts.app.
const DEFAULT_HOSTS = new Set(
  [
    'localhost',
    '127.0.0.1',
    'suresigncontracts.app',
    'www.suresigncontracts.app',
    ...(process.env.NEXT_PUBLIC_MARKETING_DEFAULT_HOSTS?.split(',').map((h) => h.trim().toLowerCase()).filter(Boolean) ?? []),
  ],
);

export default async function proxy(request: NextRequest) {
  const rawHost = request.headers.get('x-forwarded-host') || request.headers.get('host') || '';
  const host = rawHost.split(':')[0].toLowerCase().trim();

  if (!host || DEFAULT_HOSTS.has(host) || !isHostnameSyntacticallyValid(host)) {
    // No host, a recognised default host, or a syntactically-invalid Host
    // header (never a lookup key) — all fall through to the ordinary,
    // unbranded experience untouched.
    return NextResponse.next();
  }

  const result = await fetchOrganisationBrandingServer(host);

  if (result.status === 'not_found') {
    // Authoritative: the backend has no organisation/customer-domain for
    // this exact host. Never the default site's own content under a host
    // that looks like it was meant to be branded — a neutral 404 instead.
    const url = request.nextUrl.clone();
    url.pathname = '/branding-not-found';
    return NextResponse.rewrite(url);
  }

  // 'resolved' (the page itself already knows how to brand) and
  // 'unavailable' (fail OPEN to the normal experience — a backend outage
  // must never make every branded organisation look nonexistent) both
  // pass through identically here.
  return NextResponse.next();
}
