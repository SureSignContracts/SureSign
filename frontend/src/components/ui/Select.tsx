'use client';

import { Children, forwardRef, isValidElement } from 'react';
import * as RadixSelect from '@radix-ui/react-select';
import { Check, ChevronDown, Loader2 } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * Fully custom, theme-matched dropdown built on @radix-ui/react-select —
 * replaces the earlier thin native-`<select>` wrapper. Radix owns keyboard
 * navigation, focus management, typeahead, and portal/collision-avoidance
 * positioning (so the open panel is never clipped by a modal, sidebar, or
 * `overflow` ancestor — see the Custom URL modal fix this same session for
 * why that matters); this file only supplies the visual layer.
 *
 * For short, fixed option lists only. For a long or dynamically-loaded list
 * (projects, organisations, users, contracts, trade packages, documents...)
 * use `Combobox` instead, which adds search-to-filter.
 *
 * BACKWARD-COMPATIBLE BY DESIGN: every existing call site (9 files) passes
 * plain `<option value="...">Label</option>` children and a native-shaped
 * `onChange={e => ...(e.target.value)}` handler — that contract is
 * preserved exactly (see the `<option>`-flattening below), so this rewrite
 * requires zero call-site changes. New/incremental migrations off raw
 * `<select>` can therefore be a near-mechanical tag swap
 * (`<select ...><option>...</option></select>` → `<Select ...><option>...
 * </option></Select>`) rather than a rewrite to a different children shape.
 *
 * Radix forbids an item `value=""` (it reserves the empty string
 * internally for "nothing selected"), but a great many existing option
 * lists rely on `value=""` for "All statuses"/"All projects"/etc. — an
 * internal sentinel transparently stands in for `""` on the Radix side and
 * is translated back to `""` before it ever reaches `onChange`/
 * `onValueChange`, so callers never need to know this happened.
 */

const EMPTY_VALUE_SENTINEL = '__ss_select_empty__';
const toRadixValue = (v: string) => (v === '' ? EMPTY_VALUE_SENTINEL : v);
const fromRadixValue = (v: string) => (v === EMPTY_VALUE_SENTINEL ? '' : v);

const SIZE_CLASSES = {
  sm: 'h-9 px-2.5 text-xs',
  md: 'h-10 px-3 text-sm',
} as const;

// Includes `name` — several existing call sites use a single generic
// `handleChange` keyed off `e.target.name` (e.g.
// `setForm(prev => ({ ...prev, [e.target.name]: e.target.value }))`), a
// common pattern across this codebase's larger forms. Omitting it here
// would silently break every one of those handlers the moment they moved
// off native `<select>` — found while auditing commercial/page.tsx.
type NativeChangeLike = { target: { value: string; name?: string } };

interface SelectProps {
  /** `string` for almost every call site; `number` accepted too since a
   *  couple of existing native-`<select>` call sites bind an index/number
   *  state directly (React's native `<select value>` typing is loose the
   *  same way) — coerced to `String(value)` internally either way. */
  value?: string | number;
  /** New-style handler. */
  onValueChange?: (value: string) => void;
  /** Legacy native-`<select>`-shaped handler — every existing call site uses this. */
  onChange?: (e: NativeChangeLike) => void;
  placeholder?: string;
  disabled?: boolean;
  loading?: boolean;
  error?: boolean;
  size?: keyof typeof SIZE_CLASSES;
  className?: string;
  style?: React.CSSProperties;
  /** `<option>` elements (legacy) and/or `SelectItem`/`SelectGroupLabel` (new) — may be mixed. */
  children: React.ReactNode;
  name?: string;
  id?: string;
  'aria-label'?: string;
  /** Accepted for JSX/prop-type compatibility with native `<select required>` call sites.
   *  No-op: a Radix trigger is a <button>, not a real form control, so the
   *  browser's native "please select an item" validation popup does not
   *  carry over — callers already gate submission with their own React
   *  state/validation in every call site that passes this. */
  required?: boolean;
}

/** Recursively flattens `<option>` children into `SelectItem`s so existing
 *  `.map(o => <option key={o} value={o}>{o}</option>)` call sites need no
 *  changes at all. Non-`<option>` children (SelectItem/SelectGroupLabel/
 *  fragments) pass through untouched. */
function renderChildren(children: React.ReactNode): React.ReactNode {
  return Children.map(children, (child) => {
    if (!isValidElement(child)) return child;
    if (child.type === 'option') {
      const { value, disabled, className, children: label } = child.props as {
        value?: string | number; disabled?: boolean; className?: string; children?: React.ReactNode;
      };
      // Native `<option>` with no `value` attribute at all defaults its
      // value to its own text content — e.g. `<option key={r}>{r}</option>`
      // (used by several existing call sites). Only fall back like this
      // when `value` is genuinely absent, not when it's an explicit `''`.
      const resolvedValue = value !== undefined
        ? String(value)
        : (typeof label === 'string' || typeof label === 'number') ? String(label) : '';
      return (
        <SelectItem key={child.key ?? resolvedValue} value={toRadixValue(resolvedValue)} disabled={disabled} className={className}>
          {label}
        </SelectItem>
      );
    }
    // SelectItem / SelectGroupLabel / any other already-compatible element.
    return child;
  });
}

export default function Select({
  value,
  onValueChange,
  onChange,
  placeholder = 'Select…',
  disabled,
  loading,
  error,
  size = 'md',
  className,
  style,
  children,
  name,
  id,
  'aria-label': ariaLabel,
}: SelectProps) {
  const handleValueChange = (raw: string) => {
    const v = fromRadixValue(raw);
    onValueChange?.(v);
    onChange?.({ target: { value: v, name } });
  };

  return (
    <RadixSelect.Root
      value={value !== undefined ? toRadixValue(String(value)) : undefined}
      onValueChange={handleValueChange}
      disabled={disabled || loading}
      name={name}
    >
      <RadixSelect.Trigger
        id={id}
        aria-label={ariaLabel}
        className={cn(
          // No `w-full` here by default — matches the original native-select
          // wrapper's `inline-flex` (auto, content-sized) behaviour. Several
          // existing filter bars (e.g. Projects) rely on each Select sitting
          // inline, side-by-side, in a `flex flex-wrap` row; forcing full
          // width here would push every one of them onto its own line. Pass
          // `className="w-full"` explicitly (as the create/edit forms in
          // this codebase already do) when full width is actually wanted.
          'inline-flex min-w-0 items-center justify-between gap-2 rounded-xl border outline-none transition-colors duration-200',
          'focus:border-[var(--gold)] disabled:opacity-50 disabled:cursor-not-allowed',
          'data-[placeholder]:text-[var(--text-muted)]',
          SIZE_CLASSES[size],
          className
        )}
        style={{
          backgroundColor: 'var(--bg-surface)',
          color: 'var(--text-primary)',
          borderColor: error ? '#f87171' : 'var(--border)',
          ...style,
        }}
      >
        <span className="truncate min-w-0 flex-1 text-left">
          <RadixSelect.Value placeholder={placeholder} />
        </span>
        {loading ? (
          <Loader2 size={14} className="animate-spin flex-shrink-0" style={{ color: 'var(--text-muted)' }} />
        ) : (
          <RadixSelect.Icon asChild>
            <ChevronDown size={14} className="flex-shrink-0" style={{ color: 'var(--text-muted)' }} />
          </RadixSelect.Icon>
        )}
      </RadixSelect.Trigger>

      {/* Portalled to document.body by default (Radix's own behaviour) —
          this is what keeps the open panel from ever being clipped by a
          modal's own overflow, an ancestor card, or a scroll container. */}
      <RadixSelect.Portal>
        <RadixSelect.Content
          position="popper"
          sideOffset={6}
          className="ss-menu-pop-in z-50 overflow-hidden rounded-xl border shadow-[var(--shadow-pop)]"
          style={{
            backgroundColor: 'var(--bg-surface)',
            borderColor: 'var(--border)',
            // Matches the trigger width, but never narrower than 200px —
            // same reasoning as Combobox's own min-width (mobile hardening
            // pass): a Select squeezed into a tight grid column shouldn't
            // force its option list down to an unreadable width.
            width: 'max(var(--radix-select-trigger-width), 200px)',
          }}
        >
          <RadixSelect.Viewport className="max-h-64 p-1">
            {renderChildren(children)}
          </RadixSelect.Viewport>
        </RadixSelect.Content>
      </RadixSelect.Portal>
    </RadixSelect.Root>
  );
}

export const SelectItem = forwardRef<HTMLDivElement, { value: string; disabled?: boolean; children: React.ReactNode; className?: string }>(
  function SelectItem({ value, disabled, children, className }, ref) {
    return (
      <RadixSelect.Item
        ref={ref}
        value={value}
        disabled={disabled}
        className={cn(
          'relative flex cursor-pointer select-none items-center gap-2 rounded-lg px-2.5 py-2 text-sm outline-none',
          'data-[disabled]:pointer-events-none data-[disabled]:opacity-40',
          'data-[highlighted]:bg-[var(--bg-elevated)]',
          className
        )}
        style={{ color: 'var(--text-primary)' }}
      >
        <span className="w-3.5 flex-shrink-0">
          <RadixSelect.ItemIndicator>
            <Check size={13} style={{ color: 'var(--gold)' }} />
          </RadixSelect.ItemIndicator>
        </span>
        <RadixSelect.ItemText>{children}</RadixSelect.ItemText>
      </RadixSelect.Item>
    );
  }
);

export function SelectGroupLabel({ children }: { children: React.ReactNode }) {
  return (
    <RadixSelect.Label className="px-2.5 pt-2 pb-1 text-[11px] font-semibold uppercase tracking-wide" style={{ color: 'var(--text-muted)' }}>
      {children}
    </RadixSelect.Label>
  );
}
