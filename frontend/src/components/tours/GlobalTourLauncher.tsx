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
    // before driver.js measures target elements.
    const t = setTimeout(() => startTour(GLOBAL_TOUR_KEY), 800);
    return () => clearTimeout(t);
    // Deliberately only re-evaluated when the user changes — this component
    // stays mounted across every /app navigation, and re-running on each
    // pathname change would re-launch the tour mid-navigation before it's
    // been marked complete.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [user?.id]);

  return null;
}
