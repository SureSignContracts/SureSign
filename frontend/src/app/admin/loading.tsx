// Next.js route-segment Suspense fallback for `/admin/**` navigations.
//
// Loading UX convention (see CLAUDE.md): normal authenticated page
// navigation must never be blocked by a large centered branded loader.
// This file previously rendered the full SureSignLoader GSAP reveal
// (`fullScreen={false}`) here — every navigation to an admin page whose
// route-segment transition took even a moment (no active data fetch
// involved; this boundary resolves once the target page's own client
// component has mounted) showed the ~3s brand-mark assembly animation in
// the content pane, with AdminLayout's sidebar/header still visible
// around it — the exact "blank main content behind a centered SureSign
// loader" production behaviour this convention retires.
//
// Returning null keeps this Suspense boundary in place (so a genuinely
// slow transition still resolves cleanly rather than suspending past an
// ancestor boundary) without imposing any branded animation or generic
// invented skeleton — a fast navigation shows no interim state at all
// (React/Next's own transition semantics keep the previous page on
// screen until the next one is ready), and each page owns its own
// accurate, content-shaped skeleton for its real data loading (see e.g.
// `/admin/prompts`'s own `isLoading` skeleton grid).
//
// The branded SureSignLoader remains exactly where it belongs — the
// auth-bootstrap gate in `AdminLayout` (`useAuthSplash`) — untouched by
// this file.
export default function AdminLoading() {
  return null;
}
