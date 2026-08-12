'use client';

import { useEffect, useState } from 'react';
import { usePathname } from 'next/navigation';
import { useAuthStore } from '@/store/authStore';
import { useTour } from '@/lib/tours/useTour';
import { applyServerTourReset, markTourCompleted } from '@/lib/tours/storage';
import { isWelcomeSeen, markWelcomeSeen } from '@/lib/welcomeStorage';
import FirstAccountWelcomeModal from '@/components/onboarding/FirstAccountWelcomeModal';

const GLOBAL_TOUR_KEY = 'global-welcome';

// Mounted once in the authenticated app shell. The first account welcome is
// shown before the existing guided tour so new users choose when that more
// involved walkthrough begins. Both states remain per-user in localStorage.
export default function GlobalTourLauncher() {
  const pathname = usePathname();
  const user = useAuthStore(s => s.user);
  const { startTour } = useTour();
  const [welcomeOpen, setWelcomeOpen] = useState(false);

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
    if (isWelcomeSeen(user.id, user.tours_reset_at)) return;

    // Small delay so the dashboard/sidebar have finished their first render
    // before opening over the newly-mounted workspace. Re-check the pathname and
    // onboarding state at fire time, not just at effect-setup time — a
    // fresh account is briefly on this same route before the onboarding
    // guard (AppLayout) redirects it away, and without this re-check the
    // tour would still fire 800ms later on the onboarding page itself.
    const t = setTimeout(() => {
      if (window.location.pathname === '/app/onboarding') return;
      const currentUser = useAuthStore.getState().user;
      if (currentUser?.organization && !currentUser.organization.is_onboarded) return;
      setWelcomeOpen(true);
    }, 800);
    return () => clearTimeout(t);
    // Deliberately re-evaluated only on user change or the onboarded flag
    // flipping true (finishing onboarding, client-side, with no page
    // reload) — NOT on every pathname change, which would re-launch the
    // tour mid-navigation before it's been marked complete.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [user?.id, user?.organization?.is_onboarded]);

  if (!user || !welcomeOpen) return null;

  return (
    <FirstAccountWelcomeModal
      firstName={user.first_name || user.name.split(/\s+/)[0] || ''}
      onComplete={(action) => {
        markWelcomeSeen(user.id, user.tours_reset_at);
        markTourCompleted(user.id, GLOBAL_TOUR_KEY);
        setWelcomeOpen(false);

        if (action === 'tour') {
          window.setTimeout(() => startTour(GLOBAL_TOUR_KEY, { force: true }), 220);
        }
      }}
    />
  );
}
