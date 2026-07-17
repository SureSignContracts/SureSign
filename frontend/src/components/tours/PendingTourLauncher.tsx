'use client';

import { useEffect } from 'react';
import { usePathname } from 'next/navigation';
import { useTour } from '@/lib/tours/useTour';
import { consumePendingTour } from '@/lib/tours/pending';

// After the Guided Tours page resolves a tour's destination route and
// navigates there (see lib/tours/launch.ts + pending.ts), the tour can't
// start until that page's own data-tour targets have mounted — which can lag
// the route change by a render or two while its data fetch resolves. Mounted
// once per app shell (both AppLayout and the project-detail ProjectLayout,
// since project pages use a separate layout tree), this picks up the pending
// tour key left in sessionStorage and polls briefly for any tour target to
// appear before starting it. useTour().startTour already filters each step
// against the live DOM, so this only needs a cheap "has the page rendered
// anything tour-related yet" signal, not per-step precision.
export default function PendingTourLauncher() {
  const pathname = usePathname();
  const { startTour } = useTour();

  useEffect(() => {
    const tourKey = consumePendingTour();
    if (!tourKey) return;

    let attempts = 0;
    const maxAttempts = 40; // ~4s at 100ms
    const interval = setInterval(() => {
      attempts += 1;
      const targetPresent = document.querySelector('[data-tour]');
      if (targetPresent || attempts >= maxAttempts) {
        clearInterval(interval);
        startTour(tourKey, { force: true });
      }
    }, 100);

    return () => clearInterval(interval);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [pathname]);

  return null;
}
