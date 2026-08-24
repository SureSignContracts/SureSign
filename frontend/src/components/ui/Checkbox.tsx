'use client';

import { useEffect, useRef, ReactNode, CSSProperties } from 'react';
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
  /** Applied to the outer label — layout/spacing classes (e.g. `flex items-center gap-2 text-sm`) when `label` is used, or a plain margin (e.g. `mt-0.5`) for a bare checkbox in a table cell. */
  className?: string;
  /** Applied to the outer label — e.g. `{ color: 'var(--text-secondary)' }` for trailing label text color. */
  style?: CSSProperties;
  /**
   * Trailing text/content, rendered inside the SAME <label> as the checkbox
   * (not a separate wrapping <label> — a <label> can't nest inside another
   * <label>) so clicking the text also toggles it, matching every native
   * `<label><input type="checkbox"/> Some text</label>` call site this
   * replaces.
   */
  label?: ReactNode;
  /** 'center' (default) for a short single-line label, 'start' to top-align the checkbox against multi-line body text (e.g. a "confirm" paragraph). Ignored when `label` isn't set. */
  align?: 'center' | 'start';
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
 * Replaces the native checkbox appearance everywhere in the app with a
 * small themed control — the native input stays mounted (sr-only, still
 * the real form/a11y target: keyboard, screen readers, click target) so
 * this is a skin over real semantics, not a from-scratch widget. This is
 * the platform-wide checkbox standard (see CLAUDE.md's "Checkbox Standard")
 * — do not add a second checkbox implementation or leave a bare
 * `<input type="checkbox">` in new code.
 */
export default function Checkbox({
  checked, indeterminate = false, onChange, disabled, title, 'aria-label': ariaLabel,
  className, style, label, align = 'center',
}: CheckboxProps) {
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

  const visual = (
    <span className={`relative inline-flex h-4 w-4 flex-shrink-0 items-center justify-center ${disabled ? 'cursor-not-allowed opacity-50' : 'cursor-pointer'}${label ? '' : (className ? ` ${className}` : '')}`}>
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
    </span>
  );

  if (!label) {
    return (
      <label className={disabled ? 'cursor-not-allowed' : 'cursor-pointer'} title={title} style={style}>
        {visual}
      </label>
    );
  }

  return (
    <label
      className={`flex ${align === 'start' ? 'items-start' : 'items-center'} gap-2 ${disabled ? 'cursor-not-allowed opacity-50' : 'cursor-pointer'}${className ? ` ${className}` : ''}`}
      title={title}
      style={style}
    >
      <span className={align === 'start' ? 'mt-0.5' : ''}>{visual}</span>
      {label}
    </label>
  );
}
