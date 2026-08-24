'use client';

import { useEffect, useRef } from 'react';
import { gsap } from 'gsap';
import { prefersReducedMotion } from '@/lib/motion';

interface CheckboxProps {
  checked: boolean;
  /** Some, but not all, of a group is checked (e.g. a "select all on this page" header checkbox). Visually distinct from both checked and unchecked — never conflated with either. */
  indeterminate?: boolean;
  onChange: (checked: boolean) => void;
  disabled?: boolean;
  title?: string;
  'aria-label'?: string;
}

// A CHECK_PATH-style tick (see SureSignLoader's own path-draw convention) is
// overkill for a 16px control — this is a plain two-segment tick, but drawn
// with the same stroke-dasharray/dashoffset GSAP technique, because a
// checkbox tick is exactly the "genuinely needs JS-driven sequencing" case
// CLAUDE.md's Motion/Animation section calls out (a CSS transition can't
// stagger the tick's draw-in after the box's own fill/border settle without
// hand-rolled keyframe timing per state change).
const TICK_PATH = 'M 3.5 8.2 L 6.5 11.2 L 12.5 4.8';

/**
 * Replaces the native checkbox appearance (Admin Users' bulk-select column)
 * with a small custom control — the native input stays mounted (sr-only,
 * still the real form/a11y target: keyboard, screen readers, click target)
 * so this is a skin over real semantics, not a from-scratch widget.
 */
export default function Checkbox({ checked, indeterminate = false, onChange, disabled, title, 'aria-label': ariaLabel }: CheckboxProps) {
  const boxRef = useRef<HTMLSpanElement>(null);
  const tickRef = useRef<SVGPathElement>(null);
  const dashRef = useRef<HTMLSpanElement>(null);
  const lengthRef = useRef(0);

  useEffect(() => {
    const tick = tickRef.current;
    if (tick && !lengthRef.current) lengthRef.current = tick.getTotalLength();
  }, []);

  useEffect(() => {
    const box = boxRef.current;
    const tick = tickRef.current;
    const dash = dashRef.current;
    if (!box || !tick || !dash) return;
    const length = lengthRef.current || tick.getTotalLength();

    if (prefersReducedMotion()) {
      gsap.set(box, { backgroundColor: checked ? 'var(--gold)' : 'transparent', borderColor: checked || indeterminate ? 'var(--gold)' : 'var(--text-muted)' });
      gsap.set(tick, { strokeDashoffset: checked ? 0 : length, opacity: checked ? 1 : 0 });
      gsap.set(dash, { opacity: indeterminate && !checked ? 1 : 0, scaleX: 1 });
      return;
    }

    const tl = gsap.timeline();

    if (checked) {
      tl.to(box, { backgroundColor: 'var(--gold)', borderColor: 'var(--gold)', duration: 0.15, ease: 'power1.out' })
        .set(tick, { strokeDasharray: length, strokeDashoffset: length, opacity: 1 })
        .to(dash, { opacity: 0, scaleX: 0, duration: 0.1 }, '<')
        .to(tick, { strokeDashoffset: 0, duration: 0.22, ease: 'power2.out' }, '-=0.05');
    } else if (indeterminate) {
      tl.to(box, { backgroundColor: 'transparent', borderColor: 'var(--gold)', duration: 0.15, ease: 'power1.out' })
        .to(tick, { opacity: 0, duration: 0.1 }, '<')
        .to(dash, { opacity: 1, scaleX: 1, duration: 0.15, ease: 'power2.out' }, '<');
    } else {
      tl.to(tick, { strokeDashoffset: length, opacity: 0, duration: 0.15, ease: 'power1.in' })
        .to(dash, { opacity: 0, scaleX: 0, duration: 0.1 }, '<')
        .to(box, { backgroundColor: 'transparent', borderColor: 'var(--text-muted)', duration: 0.15, ease: 'power1.out' }, '<');
    }

    return () => { tl.kill(); };
  }, [checked, indeterminate]);

  return (
    <label
      className={`relative inline-flex h-4 w-4 flex-shrink-0 items-center justify-center ${disabled ? 'cursor-not-allowed opacity-50' : 'cursor-pointer'}`}
      title={title}
    >
      <input
        type="checkbox"
        className="peer absolute inset-0 h-full w-full cursor-[inherit] opacity-0"
        checked={checked}
        disabled={disabled}
        aria-label={ariaLabel}
        onChange={e => onChange(e.target.checked)}
      />
      <span
        ref={boxRef}
        className="pointer-events-none absolute inset-0 rounded-[5px] transition-[outline] duration-150 peer-focus-visible:outline peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2"
        style={{ border: '1.5px solid var(--text-muted)', backgroundColor: 'transparent', outlineColor: 'var(--gold)' }}
      />
      <span
        ref={dashRef}
        className="pointer-events-none absolute h-[2px] w-2 rounded-full opacity-0"
        style={{ backgroundColor: 'var(--gold)' }}
      />
      <svg viewBox="0 0 16 16" className="pointer-events-none absolute inset-0 h-full w-full" aria-hidden="true">
        <path
          ref={tickRef}
          d={TICK_PATH}
          fill="none"
          stroke="var(--accent-fg)"
          strokeWidth={1.75}
          strokeLinecap="round"
          strokeLinejoin="round"
          opacity={0}
        />
      </svg>
    </label>
  );
}
