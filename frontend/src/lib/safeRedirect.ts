// Organisation URL Branding, Phase 5 (Stage 3, Part F) — validates a
// post-login `?next=` deep-link destination. Deliberately narrow: only a
// same-app relative path under /app/ is ever accepted — no scheme, no
// protocol-relative "//", no host, no userinfo. Anything else is rejected
// outright (falls back to the caller's own default destination), never
// "tolerantly" interpreted. This is the ONLY place `next` is read/trusted
// anywhere in the frontend.
export function isSafeAppDeepLink(next: string): boolean {
  if (!next) return false;
  if (!next.startsWith('/app/')) return false;
  // Reject a protocol-relative URL ("//evil.com/app/...") and any
  // embedded scheme/backslash trick a browser might still normalise into
  // a different host.
  if (next.startsWith('//') || next.includes('\\')) return false;
  if (/^\/app\/[a-z0-9\-/_?=&%.]*$/i.test(next) === false) return false;
  return true;
}
