// Hands a resolved tour key across a client-side navigation. The Guided
// Tours page resolves a destination route, stashes the tour key here, then
// pushes the route — PendingTourLauncher (mounted in the app shell) picks it
// up on the other side once that page's own data-tour targets have mounted.
// sessionStorage, not state/context, since the navigation is a real route
// change to a different page tree (often a different Next.js layout, e.g.
// project detail pages), not a re-render of the same component.
const KEY = 'suresign-pending-tour';

export function setPendingTour(tourKey: string) {
  try { sessionStorage.setItem(KEY, tourKey); } catch {}
}

export function consumePendingTour(): string | null {
  try {
    const value = sessionStorage.getItem(KEY);
    if (value) sessionStorage.removeItem(KEY);
    return value;
  } catch {
    return null;
  }
}
