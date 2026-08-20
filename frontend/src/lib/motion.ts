/**
 * Shared motion vocabulary for the Global modules (Dashboard, Projects,
 * Commercial, Documents, Reports) — extracted because the same easing curve,
 * stagger cap, and duration values were already being repeated ad hoc across
 * these pages (Projects' card grid, Reports' summary cards) before this pass.
 * Not a general design-system abstraction — just the handful of values that
 * were already duplicated, given one name each so every module agrees on
 * "what does a hover feel like" / "how long does a stagger take".
 *
 * Entrance animation itself is CSS (`.ss-animate-in` in globals.css), which
 * already respects `prefers-reduced-motion`. These constants only control
 * the inline `animationDelay` stagger and Tailwind transition-duration
 * classes layered on top.
 */

/** Same spring-like curve already used for hover/press feedback app-wide (globals.css `a, button` transitions). */
export const EASE = 'ease-[cubic-bezier(0.32,0.72,0,1)]';

/** Per-item stagger step for repeated cards/rows, and the cap so a long list doesn't take seconds to finish entering. */
export const STAGGER_STEP_MS = 45;
export const STAGGER_CAP_MS = 360;

/** `animationDelay` for the nth item in a staggered entrance list. */
export function staggerDelay(index: number): string {
  return `${Math.min(index * STAGGER_STEP_MS, STAGGER_CAP_MS)}ms`;
}

/** Standard hover-elevation classes for a card-like surface (shadow step-up + slight lift). */
export const CARD_HOVER = `transition-all duration-300 ${EASE} hover:-translate-y-0.5 shadow-[var(--shadow-card)] hover:shadow-[var(--shadow-pop)]`;

/** Standard fast interaction feedback for buttons/pills/rows (press + hover, no shadow change). */
export const INTERACTIVE = `transition-all duration-200 ${EASE} active:scale-[0.97]`;

/**
 * GSAP-specific vocabulary (GSAP Motion Polish phase). The constants above
 * are CSS-only and predate GSAP's use anywhere but the SureSignLoader mark
 * assembly — these cover the handful of values shared by that loader and
 * the workspace content transition (`WorkspaceTransition.tsx`), the second
 * real GSAP call site introduced in this phase. Kept in this same file
 * rather than a second `gsapMotion.ts` — one shared motion vocabulary file,
 * not two competing ones.
 */

/** True once, read synchronously — never re-evaluated mid-animation (a user toggling the OS
 * setting mid-timeline is not a case any of these call sites need to react to live). */
export const prefersReducedMotion = (): boolean =>
  typeof window !== 'undefined' &&
  !!window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;

/** A restrained entrance for content that just became visible after a route/tab change — see
 * `WorkspaceTransition`. Deliberately short and opacity-led (no y-shift) so it never fights a
 * page's own richer internal entrance (`.ss-animate-in` / `.ss-workspace-page-in` staggers). */
export const WORKSPACE_TRANSITION_DURATION = 0.16;
export const GSAP_EASE_OUT = 'power1.out';
