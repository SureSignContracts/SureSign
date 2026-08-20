'use client';

import { useEffect, useId, useMemo, useRef, useState } from 'react';
import * as Popover from '@radix-ui/react-popover';
import { Check, ChevronDown, Loader2, Search, X } from 'lucide-react';
import { cn } from '@/lib/utils';

export interface ComboboxOption {
  value: string;
  label: string;
  description?: string;
  disabled?: boolean;
}

/**
 * Searchable single-select for long or dynamically-loaded lists (projects,
 * organisations, users, contracts, trade packages, documents, ...) — the
 * `Select` component next to this one is for short, fixed lists instead.
 *
 * Built on @radix-ui/react-popover for positioning/portal/collision
 * avoidance (so the panel is never clipped by a modal or scroll container)
 * plus a hand-rolled ARIA combobox-listbox pattern for the search input and
 * keyboard navigation — Radix has no ready-made combobox primitive, but the
 * interaction pattern here (Escape/ArrowUp/ArrowDown/Enter,
 * `aria-activedescendant`, `role="listbox"`) is the standard WAI-ARIA
 * combobox pattern, not a novel one.
 *
 * Client-side filtering by default (`options` is the full list, filtered
 * here by label). Pass `onSearch` for a server-side/async list instead —
 * `options` is then trusted as already-filtered, and `loading` shows a
 * spinner while a new page/query is in flight.
 */
export default function Combobox({
  value,
  onValueChange,
  options,
  onSearch,
  loading,
  disabled,
  error,
  placeholder = 'Select…',
  searchPlaceholder = 'Search…',
  emptyMessage = 'No results found.',
  clearable = false,
  size = 'md',
  className,
  style,
  id,
  'aria-label': ariaLabel,
}: {
  value?: string;
  onValueChange: (value: string) => void;
  options: ComboboxOption[];
  onSearch?: (query: string) => void;
  loading?: boolean;
  disabled?: boolean;
  error?: boolean;
  placeholder?: string;
  searchPlaceholder?: string;
  emptyMessage?: string;
  clearable?: boolean;
  size?: 'sm' | 'md';
  className?: string;
  style?: React.CSSProperties;
  id?: string;
  'aria-label'?: string;
}) {
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState('');
  const [activeIndex, setActiveIndex] = useState(0);
  const inputRef = useRef<HTMLInputElement>(null);
  const listRef = useRef<HTMLDivElement>(null);
  // Unique per instance — a hardcoded id broke `aria-controls`/
  // `aria-activedescendant` correctness the moment two Comboboxes were
  // ever open at once on the same page (found during the modal/nested-
  // dialog hardening pass).
  const listboxId = `combobox-listbox-${useId()}`;

  const filtered = useMemo(() => {
    if (onSearch) return options; // caller already filtered
    if (!query.trim()) return options;
    const q = query.trim().toLowerCase();
    return options.filter(o => o.label.toLowerCase().includes(q));
  }, [options, query, onSearch]);

  const selected = options.find(o => o.value === value) ?? null;

  // Focusing the search input is a genuine external-system side effect
  // (imperative DOM focus), so it stays in an effect keyed on `open`.
  // Resetting `activeIndex` is plain React state, not a DOM effect — it's
  // set directly in `handleOpenChange` below instead of here, avoiding a
  // synchronous setState-in-effect cascade for what's really just derived
  // initial state for the newly-opened popover.
  useEffect(() => {
    if (!open) return;
    const t = setTimeout(() => inputRef.current?.focus(), 0);
    return () => clearTimeout(t);
  }, [open]);

  useEffect(() => {
    if (onSearch) onSearch(query);
  }, [query, onSearch]);

  useEffect(() => {
    // Keep the highlighted option scrolled into view as the user navigates.
    const el = listRef.current?.querySelector<HTMLElement>(`[data-index="${activeIndex}"]`);
    el?.scrollIntoView({ block: 'nearest' });
  }, [activeIndex]);

  const commit = (opt: ComboboxOption) => {
    if (opt.disabled) return;
    onValueChange(opt.value);
    setOpen(false);
    setQuery('');
  };

  const onKeyDown = (e: React.KeyboardEvent) => {
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      setActiveIndex(i => Math.min(i + 1, filtered.length - 1));
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      setActiveIndex(i => Math.max(i - 1, 0));
    } else if (e.key === 'Enter') {
      e.preventDefault();
      const opt = filtered[activeIndex];
      if (opt) commit(opt);
    } else if (e.key === 'Escape') {
      setOpen(false);
    }
  };

  const sizeClass = size === 'sm' ? 'h-9 px-2.5 text-xs' : 'h-10 px-3 text-sm';

  return (
    <Popover.Root open={open} onOpenChange={(o) => { setOpen(o); if (o) { setActiveIndex(0); } else { setQuery(''); } }}>
      <Popover.Trigger asChild>
        <button
          type="button"
          id={id}
          disabled={disabled}
          aria-label={ariaLabel}
          aria-haspopup="listbox"
          aria-expanded={open}
          aria-controls={listboxId}
          className={cn(
            // No `w-full` by default — see Select.tsx's identical note; pass
            // `className="w-full"` explicitly where full width is wanted.
            'inline-flex min-w-0 items-center justify-between gap-2 rounded-xl border outline-none transition-colors duration-200',
            'focus:border-[var(--gold)] disabled:opacity-50 disabled:cursor-not-allowed',
            sizeClass,
            className
          )}
          style={{
            backgroundColor: 'var(--bg-surface)',
            color: selected ? 'var(--text-primary)' : 'var(--text-muted)',
            borderColor: error ? '#f87171' : 'var(--border)',
            ...style,
          }}
        >
          <span className="truncate text-left">{selected ? selected.label : placeholder}</span>
          <span className="flex items-center gap-1 flex-shrink-0">
            {clearable && selected && (
              <X
                size={13}
                style={{ color: 'var(--text-muted)' }}
                onClick={(e) => { e.stopPropagation(); onValueChange(''); }}
              />
            )}
            <ChevronDown size={14} style={{ color: 'var(--text-muted)' }} />
          </span>
        </button>
      </Popover.Trigger>

      <Popover.Portal>
        <Popover.Content
          align="start"
          sideOffset={6}
          onOpenAutoFocus={(e) => e.preventDefault()}
          className="ss-menu-pop-in z-50 overflow-hidden rounded-xl border shadow-[var(--shadow-pop)]"
          style={{
            // Matches the trigger width, but never narrower than 240px —
            // found during mobile hardening: a Combobox squeezed into a
            // tight grid column (e.g. a 3-up mobile layout) would otherwise
            // force its search input + results list down to the trigger's
            // own cramped width, making the panel nearly unusable.
            width: 'max(var(--radix-popover-trigger-width), 240px)',
            backgroundColor: 'var(--bg-surface)',
            borderColor: 'var(--border)',
          }}
        >
          <div className="flex items-center gap-2 border-b px-2.5" style={{ borderColor: 'var(--border)' }}>
            <Search size={13} style={{ color: 'var(--text-muted)' }} className="flex-shrink-0" />
            <input
              ref={inputRef}
              value={query}
              onChange={e => setQuery(e.target.value)}
              onKeyDown={onKeyDown}
              placeholder={searchPlaceholder}
              role="combobox"
              aria-expanded={open}
              aria-controls={listboxId}
              aria-activedescendant={filtered[activeIndex] ? `${listboxId}-option-${activeIndex}` : undefined}
              // This is a search box, never a real form field the browser
              // should remember/autofill — without this, Chrome's address/
              // profile-autofill heuristics can match a placeholder like
              // "Search countries..." and pop its own suggestion UI
              // (a saved country + "Manage addresses...") directly on top
              // of this dropdown's own results, which reads as a jarring,
              // unrelated highlight layered over the real list.
              autoComplete="off"
              autoCorrect="off"
              autoCapitalize="off"
              spellCheck={false}
              className="w-full bg-transparent py-2.5 text-sm outline-none"
              style={{ color: 'var(--text-primary)' }}
            />
            {loading && <Loader2 size={13} className="animate-spin flex-shrink-0" style={{ color: 'var(--text-muted)' }} />}
          </div>

          <div ref={listRef} id={listboxId} role="listbox" className="max-h-64 overflow-y-auto p-1">
            {filtered.length === 0 ? (
              <div className="px-2.5 py-4 text-center text-xs" style={{ color: 'var(--text-muted)' }}>
                {loading ? 'Loading…' : emptyMessage}
              </div>
            ) : (
              filtered.map((opt, i) => (
                <div
                  key={opt.value}
                  id={`${listboxId}-option-${i}`}
                  data-index={i}
                  role="option"
                  aria-selected={opt.value === value}
                  onMouseEnter={() => setActiveIndex(i)}
                  onClick={() => commit(opt)}
                  className={cn(
                    'flex cursor-pointer select-none items-center gap-2 rounded-lg px-2.5 py-2 text-sm',
                    opt.disabled && 'pointer-events-none opacity-40'
                  )}
                  style={{
                    backgroundColor: i === activeIndex ? 'var(--bg-elevated)' : 'transparent',
                    color: 'var(--text-primary)',
                  }}
                >
                  <span className="w-3.5 flex-shrink-0">
                    {opt.value === value && <Check size={13} style={{ color: 'var(--gold)' }} />}
                  </span>
                  <span className="min-w-0 flex-1">
                    <span className="block truncate">{opt.label}</span>
                    {opt.description && (
                      <span className="block truncate text-xs" style={{ color: 'var(--text-muted)' }}>{opt.description}</span>
                    )}
                  </span>
                </div>
              ))
            )}
          </div>
        </Popover.Content>
      </Popover.Portal>
    </Popover.Root>
  );
}
