'use client';

import { useCallback } from 'react';
import { useAuthStore } from '@/store/authStore';
import { getTour } from './registry';
import { isTourCompleted, markTourCompleted, resetTourCompletion } from './storage';

export function useTour() {
  const userId = useAuthStore(s => s.user?.id ?? null);
  const roles = useAuthStore(s => s.user?.roles ?? []);

  const startTour = useCallback(async (tourKey: string, opts?: { force?: boolean }) => {
    if (typeof document === 'undefined') return;

    const def = getTour(tourKey);
    if (!def) return;
    if (def.roles && !def.roles.some(r => roles.includes(r))) return;
    if (!opts?.force && isTourCompleted(userId, tourKey)) return;

    // Role-hidden or not-yet-rendered elements are skipped rather than
    // blocking the tour — construction users on restricted roles or pages
    // with empty data must never see a broken/stuck tour.
    const steps = def.steps
      .filter(s => !s.roles || s.roles.some(r => roles.includes(r)))
      .filter(s => document.querySelector(s.target))
      .map(s => ({
        element: s.target,
        popover: { title: s.title, description: s.description },
      }));

    if (steps.length === 0) return;

    const { driver } = await import('driver.js');
    const driverObj = driver({
      showProgress: true,
      allowClose: true,
      smoothScroll: true,
      overlayOpacity: 0.45,
      stagePadding: 6,
      stageRadius: 12,
      popoverClass: 'ss-tour-popover',
      steps,
      onDestroyed: () => markTourCompleted(userId, tourKey),
    });
    driverObj.drive();
  }, [userId, roles]);

  return {
    startTour,
    isTourCompleted: (tourKey: string) => isTourCompleted(userId, tourKey),
    resetTour: (tourKey: string) => resetTourCompletion(userId, tourKey),
  };
}
