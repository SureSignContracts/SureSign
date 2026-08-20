'use client';

import { useLayoutEffect, useRef } from 'react';
import { usePathname } from 'next/navigation';
import { gsap } from 'gsap';
import { prefersReducedMotion, WORKSPACE_TRANSITION_DURATION, GSAP_EASE_OUT } from '@/lib/motion';

/**
 * Wraps a persistent layout's `<main>{children}</main>` region (the
 * authenticated app shell, the admin shell, and the per-project workspace
 * shell) so navigating between sibling routes gets a restrained entrance
 * instead of the new page just snapping into place — the gap discovery
 * found: many top-level pages (Settings, AI, Notifications, What's New,
 * Consultations, and most project workspace tabs — RFIs, Risks, QA,
 * Snagging, etc.) had no entrance treatment at all, while others already
 * carry their own richer `.ss-animate-in`/`.ss-workspace-page-in` CSS
 * entrance on individual cards/sections.
 *
 * Deliberately a single, subtle, opacity-only fade (no y-shift, ~160ms) —
 * short and understated enough that it never visibly competes with a
 * page's own internal stagger where one already exists, while still fixing
 * the "nothing happens" gap on every page that has none.
 *
 * Keyed on `pathname`, not on `children` identity or a data refetch — a
 * React Query background refetch never changes the pathname, so this never
 * replays because a dashboard/table simply revalidated its own data.
 *
 * Sidebar/header stay outside this wrapper entirely (each layout only
 * wraps its own `<main>`) — navigation itself is never delayed by this:
 * the new route has already fully rendered and committed to the DOM by
 * the time this effect runs; it only fades what's already there.
 */
export default function WorkspaceTransition({ children }: { children: React.ReactNode }) {
  const pathname = usePathname();
  const ref = useRef<HTMLDivElement>(null);

  useLayoutEffect(() => {
    const el = ref.current;
    if (!el) return;

    if (prefersReducedMotion()) {
      gsap.set(el, { opacity: 1 });
      return;
    }

    const tween = gsap.fromTo(
      el,
      { opacity: 0 },
      { opacity: 1, duration: WORKSPACE_TRANSITION_DURATION, ease: GSAP_EASE_OUT, overwrite: true },
    );

    return () => {
      tween.kill();
    };
  }, [pathname]);

  return <div ref={ref}>{children}</div>;
}
