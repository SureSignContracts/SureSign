// Next.js route-segment Suspense fallback for `/app/**` navigations.
//
// Loading UX convention (see CLAUDE.md): normal authenticated page
// navigation must never be blocked by a large centered branded loader.
// This file previously rendered the full SureSignLoader GSAP reveal
// (`fullScreen={false}`) here — every navigation to an app page whose
// route-segment transition took even a moment (no active data fetch
// involved; this boundary resolves once the target page's own client
// component has mounted) showed the ~3s brand-mark assembly animation in
// the content pane, with AppLayout's sidebar/header still visible around
// it — the same "blank main content behind a centered SureSign loader"
// production behaviour already retired from `/admin/**` in the previous
// checkpoint.
//
// Returning null keeps this Suspense boundary in place (so a genuinely
// slow transition still resolves cleanly rather than suspending past an
// ancestor boundary) without imposing any branded animation or generic
// invented skeleton — a fast navigation shows no interim state at all
// (React/Next's own transition semantics keep the previous page on
// screen until the next one is ready), and each page owns its own
// accurate, content-shaped skeleton for its real data loading. Next.js
// prefers the nearest ancestor `loading.tsx` to the segment actually
// changing, so this does NOT affect the more specific, already-correct
// `app/app/projects/[id]/loading.tsx` (a real content-shaped skeleton,
// left untouched) — that boundary still applies for navigations into a
// project's own segment tree.
//
// The branded SureSignLoader remains exactly where it belongs — the
// auth-bootstrap gate in `AppLayout` (`useAuthSplash`) — untouched by
// this file.
export default function AppLoading() {
  return null;
}
