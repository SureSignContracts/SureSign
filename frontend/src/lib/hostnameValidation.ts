// Organisation URL Branding, Phase 4 — strict hostname-only syntax check
// for the login page's `brandHost` query parameter (see app/login/page.tsx).
// Mirrors marketing/src/lib/hostnameValidation.ts's identical check — kept
// as a separate small file rather than a cross-app import, since no
// shared-package convention exists between frontend/ and marketing/ in
// this repo. Rejects anything that isn't a bare hostname: no scheme,
// path, port, userinfo, query, fragment, or whitespace.
export function isHostnameSyntacticallyValid(host: string): boolean {
  if (!host || host.length > 253) return false;
  return /^(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))+$/i.test(host);
}
