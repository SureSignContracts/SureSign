'use client';

import { useEffect, useState, useSyncExternalStore } from 'react';
import {
  clearPostLoginEntrance,
  hasPostLoginEntrance,
  subscribePostLoginEntrance,
} from '@/lib/authStorage';

// Long enough for SureSignLoader's real one-time reveal to actually finish
// on screen — entrance + the mark drawing itself in piece by piece +
// crossfade + settle-pop + a themed decoration popping in comes to
// roughly 2.9-3.1s (New Year's extra firework bursts run a bit past that,
// into what would otherwise be the idle loop — an acceptable trade-off
// rather than holding every login for the single longest theme). Tuned
// specifically to this animation, not an arbitrary constant — if the
// reveal's own timeline durations change materially, revisit this too.
// Deliberately login-only (see `playEntrance`'s docblock below) — every
// other read (an already-authenticated reload, ordinary navigation)
// skips this wait entirely and shows the real app the moment it's ready.
const MIN_SPLASH_MS = 3000;
const ENTRANCE_ANIMATION_MS = 1100;

// How long the loader NODE stays mounted after `showSplash` itself has
// already gone false, purely so SureSignLoader's GSAP exit tween (~200ms)
// has time to actually play before the caller swaps to the real UI. This
// never delays `showSplash`/app readiness by even one tick — it only
// affects when the now-unnecessary loader is removed from the tree.
// Exported so the Super Admin Preview panel's loader test button can play
// the exact same exit timing rather than a duplicated magic number.
export const LOADER_EXIT_MS = 220;

/**
 * Shared "how long do we show the branded splash" gate for every
 * authenticated layout (admin, app, dashboard). Two independent things
 * both have to clear before the splash is dismissed:
 *
 * 1. A minimum on-screen duration (MIN_SPLASH_MS) after a freshly completed
 *    login — long enough for the branded loader to actually finish playing
 *    its reveal, rather than the app potentially becoming ready mid-draw
 *    and cutting it off. Existing sessions/ordinary navigation skip that
 *    wait entirely and show the real app the instant it's ready — this is
 *    a "let a fresh login enjoy the moment" grace period, not a general
 *    loading-screen delay. The browser-only marker is read with
 *    useSyncExternalStore so server rendering cannot freeze a false value
 *    into the hydrated layout.
 * 2. `isReady`, supplied by the caller — this hook has no opinion on what
 *    "ready" means for a given layout (auth rehydration, token/user
 *    presence, an organisation-branding fetch, or nothing at all beyond
 *    auth) so that layout-specific readiness never leaks into shared code.
 *
 * `playEntrance` stays true until the CSS sequence has completed, then the
 * marker is consumed so route changes and reloads do not replay it.
 *
 * `showLoaderNode`/`loaderExiting` are a thin presentation-only addition on
 * top of `showSplash` (which keeps its exact original meaning/timing,
 * still the correct field for anything that isn't literally "should the
 * loader element still be mounted"). React unmounts a component the
 * instant its parent stops rendering it — a child has no way to animate
 * its own exit otherwise — so `showLoaderNode` stays true for
 * `LOADER_EXIT_MS` after `showSplash` first goes false, giving
 * SureSignLoader's GSAP exit tween a real window to play, while
 * `loaderExiting` tells it which of the two states it's in.
 */
export function useAuthSplash(isReady: boolean): {
  showSplash: boolean;
  playEntrance: boolean;
  showLoaderNode: boolean;
  loaderExiting: boolean;
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

  // Initialised from showSplash's own first value, not a hardcoded `true`
  // — an already-ready mount (showSplash false from the start) must never
  // flash the loader on for LOADER_EXIT_MS just because this started true.
  const [loaderMounted, setLoaderMounted] = useState(showSplash);

  // Resetting `loaderMounted` back to true belongs in render, not an
  // effect — it's purely "adjust state when a prop changed", which
  // React's own docs handle with a second piece of state tracking the
  // previous value (compared during render), not a ref — this project's
  // lint config (react-hooks/refs) forbids reading/writing refs during
  // render, since it isn't safe under the React Compiler/concurrent
  // rendering, so the ref-based version of this idiom isn't an option here.
  const [prevShowSplash, setPrevShowSplash] = useState(showSplash);
  if (showSplash !== prevShowSplash) {
    setPrevShowSplash(showSplash);
    if (showSplash) setLoaderMounted(true);
  }

  // The false branch genuinely needs an effect (an external timer), but
  // only ever calls setState asynchronously from the timeout callback,
  // never synchronously in the effect body itself.
  useEffect(() => {
    if (showSplash) return;
    const timer = setTimeout(() => setLoaderMounted(false), LOADER_EXIT_MS);
    return () => clearTimeout(timer);
  }, [showSplash]);

  return {
    showSplash,
    playEntrance,
    showLoaderNode: showSplash || loaderMounted,
    loaderExiting: !showSplash && loaderMounted,
  };
}
