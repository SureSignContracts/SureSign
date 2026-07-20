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
