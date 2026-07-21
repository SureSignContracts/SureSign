'use client';

import { useEffect, useState } from 'react';

const MIN_SPLASH_MS = 800;

/**
 * Shared "how long do we show the branded splash" gate for every
 * authenticated layout (admin, app, dashboard). Two independent things
 * both have to clear before the splash is dismissed:
 *
 * 1. A minimum on-screen duration (MIN_SPLASH_MS), so the branded splash
 *    doesn't flash for an imperceptible instant on a genuinely fast cold
 *    load — but only on a genuine cold load. If a session token is already
 *    in localStorage when this hook first mounts (switching between route
 *    groups, or reloading while already signed in), there's nothing to
 *    reveal, so the minimum is skipped entirely rather than forcing an
 *    artificial wait for an already-authenticated user.
 * 2. `isReady`, supplied by the caller — this hook has no opinion on what
 *    "ready" means for a given layout (auth rehydration, token/user
 *    presence, an organisation-branding fetch, or nothing at all beyond
 *    auth) so that layout-specific readiness never leaks into shared code.
 *
 * Returns whether the splash should still be shown.
 */
export function useAuthSplash(isReady: boolean): boolean {
  const alreadyAuthed = typeof window !== 'undefined' && !!localStorage.getItem('suresign_token');
  const [minTimeElapsed, setMinTimeElapsed] = useState(alreadyAuthed);

  useEffect(() => {
    if (alreadyAuthed) return;

    const timer = setTimeout(() => setMinTimeElapsed(true), MIN_SPLASH_MS);
    return () => clearTimeout(timer);
    // alreadyAuthed is read once, at mount, deliberately — this is a
    // "did we already have a session when this layout first appeared"
    // check, not a value that should retrigger the timer if it changes.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  return !isReady || !minTimeElapsed;
}
