import type { NextConfig } from "next";

// Derived from the same env var the app already uses for its own API calls
// (see marketing/src/lib/pricing.ts and friends), so connect-src always
// matches whatever backend this build is actually configured to call,
// in every environment, without hardcoding a domain here.
const apiOrigin = (() => {
  try {
    return new URL(process.env.NEXT_PUBLIC_API_URL || 'http://localhost:8000/api').origin;
  } catch {
    return "'none'";
  }
})();

// style-src needs 'unsafe-inline' because several components set real inline
// `style` attributes (e.g. HeroBlueprint's mask-image/opacity) rather than
// Tailwind classes — CSP governs the style attribute the same as a <style>
// block. script-src needs it for the two small inline scripts in
// layout.tsx/page.tsx (pre-hydration theme flash prevention, JSON-LD) —
// both are static, non-user-influenced markup, not a real injection vector.
//
// static.cloudflareinsights.com/cloudflareinsights.com: this app has no
// analytics code of its own — Cloudflare auto-injects its Web Analytics
// beacon at the edge whenever the site is proxied through it, so the CSP
// has to allow it regardless of anything in this repo, or Cloudflare's own
// script gets blocked with no code-level fix possible.
const csp = [
  "default-src 'self'",
  "base-uri 'self'",
  "frame-ancestors 'none'",
  "script-src 'self' 'unsafe-inline' https://static.cloudflareinsights.com",
  "style-src 'self' 'unsafe-inline'",
  "img-src 'self' data:",
  "font-src 'self'",
  `connect-src 'self' ${apiOrigin} https://cloudflareinsights.com`,
  "form-action 'self'",
].join('; ');

const nextConfig: NextConfig = {
  output: 'standalone',
  images: {
    // Next's image optimizer defaults to Content-Disposition: attachment,
    // which some browsers (confirmed: Chromium here) refuse to paint as an
    // inline <img> — the hero background silently failed to render because
    // of this, not because of any mask/opacity/CSS issue.
    contentDispositionType: 'inline',
  },
  async headers() {
    return [
      {
        source: '/:path*',
        headers: [
          { key: 'X-Content-Type-Options', value: 'nosniff' },
          { key: 'X-Frame-Options', value: 'DENY' },
          { key: 'Referrer-Policy', value: 'strict-origin-when-cross-origin' },
          { key: 'Permissions-Policy', value: 'camera=(), microphone=(), geolocation=()' },
          { key: 'Content-Security-Policy', value: csp },
        ],
      },
    ];
  },
};

export default nextConfig;
