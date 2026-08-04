// Organisation URL Branding, Phase 4 — the one strict hostname-only
// syntax check, shared by both server code (proxy.ts,
// organisationBrandingServer.ts) and client code (the login handoff).
// Deliberately has no server-only imports (no `next/headers`) so it can
// be used from a 'use client' component too.
//
// Rejects anything that isn't a bare hostname: no scheme, path, port,
// userinfo, query, fragment, or whitespace. Used everywhere a hostname
// arrives as user/request-influenced input before it's ever placed in a
// URL or used as a cache/lookup key.
export function isHostnameSyntacticallyValid(host: string): boolean {
  if (!host || host.length > 253) return false;
  return /^(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))+$/i.test(host);
}
