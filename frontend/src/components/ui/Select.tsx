'use client';

import { forwardRef } from 'react';
import { ChevronDown } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * Thin wrapper around a native `<select>` — still a real select underneath
 * (keyboard nav, focus states, screen-reader semantics all come from the
 * browser for free), just with `appearance-none` plus our own chevron so the
 * arrow's spacing is something we control directly instead of hoping each
 * browser's native arrow leaves enough room next to the app's enlarged base
 * font-size (globals.css scales the whole site to ~125% zoom). Centralised
 * here because every Global module was independently reimplementing
 * (inconsistently) the same `<select>` + padding, which is exactly the kind
 * of drift a shared component should absorb instead of five separate patches.
 *
 * `pr-8` reserves room for the chevron so selected text never collides with
 * it, even for long option labels — the chevron itself is `pointer-events-none`
 * and purely decorative (the real, focusable, keyboard-operable control is
 * the `<select>` itself, so nothing here changes accessibility semantics).
 */
const Select = forwardRef<HTMLSelectElement, React.SelectHTMLAttributes<HTMLSelectElement>>(
  function Select({ className, style, children, ...props }, ref) {
    return (
      <div className="relative inline-flex">
        <select
          ref={ref}
          {...props}
          className={cn(
            'appearance-none pl-3 pr-8 py-2 rounded-xl text-sm outline-none border border-[var(--border)] focus:border-[var(--gold)] transition-colors duration-200',
            className
          )}
          style={{ backgroundColor: 'var(--bg-surface)', color: 'var(--text-primary)', ...style }}
        >
          {children}
        </select>
        <ChevronDown
          size={14}
          className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2"
          style={{ color: 'var(--text-muted)' }}
        />
      </div>
    );
  }
);

export default Select;
