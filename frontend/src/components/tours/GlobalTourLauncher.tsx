'use client';

import { useEffect } from 'react';
import { usePathname } from 'next/navigation';
import { useAuthStore } from '@/store/authStore';
import { useTour } from '@/lib/tours/useTour';
import { applyServerTourReset } from '@/lib/tours/storage';

const GLOBAL_TOUR_KEY = 'global-welcome';

// Mounted once in the authenticated app shell. Fires the first-login tour
// exactly once per user (tracked in localStorage — see lib/tours/storage.ts)
// and never re-shows it after it is completed or dismissed.
export default function GlobalTourLauncher() {
  const pathname = usePathname();
  const user = useAuthStore(s => s.user);
  const { startTour, isTourCompleted } = useTour();

  // A Super Admin may have reset this user's tours server-side (Sprint 8C) —
  // apply that before deciding whether the welcome tour still counts as done.
  useEffect(() => {
    if (!user) return;
    applyServerTourReset(user.id, user.tours_reset_at);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [user?.id, user?.tours_reset_at]);

  useEffect(() => {
    if (!user) return;
    if (pathname === '/app/onboarding') return;
    if (isTourCompleted(GLOBAL_TOUR_KEY)) return;

    // Small delay so the dashboard/sidebar have finished their first render
    // before driver.js measures target elements. Re-check the pathname and
    // onboarding state at fire time, not just at effect-setup time — a
    // fresh account is briefly on this same route before the onboarding
    // guard (AppLayout) redirects it away, and without this re-check the
    // tour would still fire 800ms later on the onboarding page itself.
    const t = setTimeout(() => {
      if (window.location.pathname === '/app/onboarding') return;
      const currentUser = useAuthStore.getState().user;
      if (currentUser?.organization && !currentUser.organization.is_onboarded) return;
      startTour(GLOBAL_TOUR_KEY);
    }, 800);
    return () => clearTimeout(t);
    // Deliberately re-evaluated only on user change or the onboarded flag
    // flipping true (finishing onboarding, client-side, with no page
    // reload) — NOT on every pathname change, which would re-launch the
    // tour mid-navigation before it's been marked complete.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [user?.id, user?.organization?.is_onboarded]);

  return null;
}
