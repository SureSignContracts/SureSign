'use client';

import { useEffect, useState, useSyncExternalStore } from 'react';
import {
  clearPostLoginEntrance,
  hasPostLoginEntrance,
  subscribePostLoginEntrance,
} from '@/lib/authStorage';

const MIN_SPLASH_MS = 800;
const ENTRANCE_ANIMATION_MS = 1100;

/**
 * Shared "how long do we show the branded splash" gate for every
 * authenticated layout (admin, app, dashboard). Two independent things
 * both have to clear before the splash is dismissed:
 *
 * 1. A minimum on-screen duration (MIN_SPLASH_MS) after a freshly completed
 *    login, so the branded handoff has room to register before the workspace
 *    enters. Existing sessions skip that artificial wait. The browser-only
 *    marker is read with useSyncExternalStore so server rendering cannot
 *    freeze a false value into the hydrated layout.
 * 2. `isReady`, supplied by the caller — this hook has no opinion on what
 *    "ready" means for a given layout (auth rehydration, token/user
 *    presence, an organisation-branding fetch, or nothing at all beyond
 *    auth) so that layout-specific readiness never leaks into shared code.
 *
 * `playEntrance` stays true until the CSS sequence has completed, then the
 * marker is consumed so route changes and reloads do not replay it.
 */
export function useAuthSplash(isReady: boolean): {
  showSplash: boolean;
  playEntrance: boolean;
} {
  const playEntrance = useSyncExternalStore(
    subscribePostLoginEntrance,
    hasPostLoginEntrance,
    () => false,
  );
  const [minTimeElapsed, setMinTimeElapsed] = useState(false);

  useEffect(() => {
    if (!playEntrance) return;

    const timer = setTimeout(() => setMinTimeElapsed(true), MIN_SPLASH_MS);
    return () => clearTimeout(timer);
  }, [playEntrance]);

  const showSplash = !isReady || (playEntrance && !minTimeElapsed);

  useEffect(() => {
    if (!playEntrance || showSplash) return;

    // Keep the marker (and therefore the animation class) alive until the
    // CSS sequence has fully settled. Clearing immediately can remove the
    // class mid-animation when child data causes an incidental re-render.
    const timer = setTimeout(clearPostLoginEntrance, ENTRANCE_ANIMATION_MS);
    return () => clearTimeout(timer);
  }, [playEntrance, showSplash]);

  return { showSplash, playEntrance };
}
