import SureSignLoader from '@/components/ui/SureSignLoader';

// Next.js route-segment loading UI — shown while a not-yet-compiled/loaded
// admin route resolves (dev: Turbopack's on-demand compile; any mode: a
// slower data fetch). Renders inside AdminLayout's already-mounted sidebar
// chrome, replacing only the content area next to it, so `fullScreen`
// stays off (see SureSignLoader's own docblock on why). This does NOT
// touch the one gap that can't be fixed this way — a hard reload of a
// route the dev server hasn't compiled yet has no React tree running to
// show this fallback in; that's a browser-native "waiting for a response"
// state, absent entirely in production (this route prerenders statically).
export default function AdminLoading() {
  return <SureSignLoader fullScreen={false} />;
}
