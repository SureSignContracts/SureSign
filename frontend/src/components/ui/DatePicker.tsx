'use client';

import { useEffect, useId, useMemo, useRef, useState } from 'react';
import * as Popover from '@radix-ui/react-popover';
import {
  startOfMonth, endOfMonth, startOfWeek, endOfWeek, eachDayOfInterval,
  addMonths, subMonths, isSameMonth, isSameDay, isBefore, isAfter, isValid, format,
} from 'date-fns';
import { CalendarDays, ChevronLeft, ChevronRight, X } from 'lucide-react';
import { cn } from '@/lib/utils';
import { parseDateOnly, formatDateOnly, toDateOnlyString, effectiveTodayYmd } from '@/lib/dateTime';

/**
 * The one authoritative SureSign date-only picker — a design-system-level
 * component, deliberately unaware of any calling module (Friday Packs,
 * Site Reports, Contracts, Commercial, Programme, H&S, ...). Callers
 * express their own rules (Friday-only, min/max, contract-period bounds)
 * via `minDate`/`maxDate`/`isDateDisabled` props — this component owns
 * only presentation and date-only value handling.
 *
 * DATE-ONLY, not date-time. The value contract is exactly "YYYY-MM-DD" —
 * a local calendar day, never a UTC instant — matching this codebase's
 * existing `parseDateOnly`/`formatDateOnly` convention
 * (`frontend/src/lib/dateTime.ts`). Do not use this for a `datetime-local`
 * field (an exact instant, e.g. an incident's `occurred_at`) — that needs
 * date-time-aware UI this component does not provide.
 *
 * Built on `@radix-ui/react-popover` (already a dependency, already the
 * pattern `Combobox`/`Select` use) for positioning/portal/collision
 * avoidance — the panel is never clipped by a modal, drawer, or
 * `overflow` ancestor. The calendar grid itself is hand-built on top of
 * `date-fns` (already a dependency) rather than pulling in a calendar
 * library — see the phase report for why that was judged unnecessary
 * for a date-only, no-range picker.
 *
 * Serialization never touches `toISOString()`/UTC — every Date used to
 * render the grid is a local calendar Date (`parseDateOnly()`), and every
 * selection is serialized back via `toDateOnlyString()`, so
 * `2026-09-04 -> calendar Date -> 2026-09-04` regardless of the viewer's
 * timezone.
 *
 * Display is UK-oriented ("4 Sep 2026", via the existing
 * `formatDateOnly()` — `en-GB` locale, day before month) — never
 * MM/DD/YYYY.
 */
export interface DatePickerProps {
  /** "YYYY-MM-DD", or "" / undefined for no value. */
  value: string | null | undefined;
  onChange: (value: string) => void;
  placeholder?: string;
  disabled?: boolean;
  required?: boolean;
  /** Validation message — renders below the trigger and reddens its border, matching `Input`/`Combobox`. */
  error?: string;
  /** Shows a clear ("x") affordance once a value is set. */
  clearable?: boolean;
  /** Inclusive lower bound, "YYYY-MM-DD". */
  minDate?: string;
  /** Inclusive upper bound, "YYYY-MM-DD". */
  maxDate?: string;
  /** Extra per-caller disabling rule (e.g. Friday-only). Combined with minDate/maxDate, never replaces them. */
  isDateDisabled?: (date: Date) => boolean;
  className?: string;
  style?: React.CSSProperties;
  id?: string;
  'aria-label'?: string;
  /** Optional helper copy below the trigger, shown only when `error` is not set — mirrors `Input`'s convention. */
  helperText?: string;
}

const WEEKDAY_LABELS = ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'];

export default function DatePicker({
  value,
  onChange,
  placeholder = 'Select date…',
  disabled,
  required,
  error,
  clearable = false,
  minDate,
  maxDate,
  isDateDisabled,
  className,
  style,
  id,
  'aria-label': ariaLabel,
  helperText,
}: DatePickerProps) {
  const [open, setOpen] = useState(false);
  const gridId = `ss-datepicker-grid-${useId()}`;
  const gridRef = useRef<HTMLDivElement>(null);

  // A malformed non-empty `value` (never expected from a real caller, but
  // never assumed either) must not crash render — `date-fns`'s `format()`
  // throws on an Invalid Date, which `parseDateOnly()` can produce from
  // un-parseable input. Treated the same as "no value" rather than guessed at.
  const parsedValue = value ? parseDateOnly(value) : null;
  const selectedDate = parsedValue && isValid(parsedValue) ? parsedValue : null;
  const todayDate = useMemo(() => parseDateOnly(effectiveTodayYmd()), []);

  // The visible month is independent of the stored value — only used to
  // decide which month's grid to render initially / after navigation.
  const [visibleMonth, setVisibleMonth] = useState<Date>(() => selectedDate ?? todayDate);
  // Roving-tabindex focus target for arrow-key navigation, reset whenever
  // the popover opens.
  const [focusedDate, setFocusedDate] = useState<Date>(() => selectedDate ?? todayDate);

  // Reset the visible month / roving focus target on the open transition
  // itself, not in an effect — this is genuine "compute initial state for
  // what's about to render" derivation, not synchronizing with an
  // external system (mirrors Combobox's identical `onOpenChange` pattern).
  const handleOpenChange = (next: boolean) => {
    setOpen(next);
    if (next) {
      const anchor = selectedDate ?? todayDate;
      setVisibleMonth(anchor);
      setFocusedDate(anchor);
    }
  };

  const minBound = minDate ? parseDateOnly(minDate) : null;
  const maxBound = maxDate ? parseDateOnly(maxDate) : null;

  const isDisabledDay = (day: Date): boolean => {
    if (minBound && isBefore(day, minBound)) return true;
    if (maxBound && isAfter(day, maxBound)) return true;
    if (isDateDisabled?.(day)) return true;
    return false;
  };

  const days = useMemo(() => {
    const gridStart = startOfWeek(startOfMonth(visibleMonth), { weekStartsOn: 1 });
    const gridEnd = endOfWeek(endOfMonth(visibleMonth), { weekStartsOn: 1 });
    return eachDayOfInterval({ start: gridStart, end: gridEnd });
  }, [visibleMonth]);

  const commit = (day: Date) => {
    if (isDisabledDay(day)) return;
    onChange(toDateOnlyString(day));
    setOpen(false);
  };

  const moveFocus = (next: Date) => {
    setFocusedDate(next);
    if (!isSameMonth(next, visibleMonth)) setVisibleMonth(next);
  };

  const onGridKeyDown = (e: React.KeyboardEvent) => {
    const stepDays = (n: number) => {
      const next = new Date(focusedDate);
      next.setDate(next.getDate() + n);
      moveFocus(next);
    };
    switch (e.key) {
      case 'ArrowRight': e.preventDefault(); stepDays(1); break;
      case 'ArrowLeft': e.preventDefault(); stepDays(-1); break;
      case 'ArrowDown': e.preventDefault(); stepDays(7); break;
      case 'ArrowUp': e.preventDefault(); stepDays(-7); break;
      case 'Enter':
      case ' ': e.preventDefault(); commit(focusedDate); break;
      case 'Escape': e.preventDefault(); setOpen(false); break;
      default: break;
    }
  };

  // Keep DOM focus on the roving-tabindex cell as the user navigates —
  // a genuine imperative focus side effect, not derivable render state.
  useEffect(() => {
    if (!open) return;
    const key = toDateOnlyString(focusedDate);
    const el = gridRef.current?.querySelector<HTMLElement>(`[data-day="${key}"]`);
    el?.focus();
  }, [focusedDate, open]);

  const displayValue = value ? formatDateOnly(value) : '';

  return (
    <div className="space-y-1">
      <Popover.Root open={open} onOpenChange={handleOpenChange}>
        <Popover.Trigger asChild>
          <button
            type="button"
            id={id}
            disabled={disabled}
            data-required={required || undefined}
            aria-label={ariaLabel ?? placeholder}
            aria-haspopup="dialog"
            aria-expanded={open}
            className={cn(
              'inline-flex w-full items-center justify-between gap-2 rounded-lg border px-3.5 py-2.5 text-sm outline-none transition-colors duration-200',
              'focus:ring-2 focus:ring-[var(--gold)]/30 disabled:opacity-50 disabled:cursor-not-allowed',
              className,
            )}
            style={{
              backgroundColor: 'var(--bg-surface)',
              borderColor: error ? '#ef4444' : 'var(--border)',
              color: value ? 'var(--text-primary)' : 'var(--text-muted)',
              ...style,
            }}
          >
            <span className="truncate">{displayValue || placeholder}</span>
            <span className="flex flex-shrink-0 items-center gap-1.5">
              {clearable && value && !disabled && (
                <X
                  size={13}
                  style={{ color: 'var(--text-muted)' }}
                  onClick={(e) => { e.stopPropagation(); onChange(''); }}
                  aria-label="Clear date"
                />
              )}
              <CalendarDays size={14} style={{ color: 'var(--text-muted)' }} />
            </span>
          </button>
        </Popover.Trigger>

        <Popover.Portal>
          <Popover.Content
            align="start"
            sideOffset={6}
            onOpenAutoFocus={(e) => e.preventDefault()}
            className="ss-menu-pop-in z-50 overflow-hidden rounded-xl border p-3 shadow-[var(--shadow-pop)]"
            style={{
              width: 288,
              maxWidth: 'calc(100vw - 2rem)',
              backgroundColor: 'var(--bg-surface)',
              borderColor: 'var(--border)',
            }}
          >
            <div className="mb-2 flex items-center justify-between">
              <button
                type="button"
                aria-label="Previous month"
                onClick={() => setVisibleMonth(m => subMonths(m, 1))}
                className="rounded-md p-1.5 transition-colors hover:opacity-80"
                style={{ color: 'var(--text-secondary)' }}
              >
                <ChevronLeft size={16} />
              </button>
              <span className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>
                {format(visibleMonth, 'MMMM yyyy')}
              </span>
              <button
                type="button"
                aria-label="Next month"
                onClick={() => setVisibleMonth(m => addMonths(m, 1))}
                className="rounded-md p-1.5 transition-colors hover:opacity-80"
                style={{ color: 'var(--text-secondary)' }}
              >
                <ChevronRight size={16} />
              </button>
            </div>

            <div className="mb-1 grid grid-cols-7 gap-0.5">
              {WEEKDAY_LABELS.map(d => (
                <div key={d} className="flex h-7 items-center justify-center text-[11px] font-medium" style={{ color: 'var(--text-muted)' }}>
                  {d}
                </div>
              ))}
            </div>

            <div
              ref={gridRef}
              role="grid"
              id={gridId}
              aria-label={format(visibleMonth, 'MMMM yyyy')}
              onKeyDown={onGridKeyDown}
              className="grid grid-cols-7 gap-0.5"
            >
              {days.map(day => {
                const inMonth = isSameMonth(day, visibleMonth);
                const isSelected = selectedDate ? isSameDay(day, selectedDate) : false;
                const isToday = isSameDay(day, todayDate);
                const isFocusTarget = isSameDay(day, focusedDate);
                const dayDisabled = isDisabledDay(day);
                const key = toDateOnlyString(day);

                return (
                  <button
                    key={key}
                    type="button"
                    role="gridcell"
                    data-day={key}
                    tabIndex={isFocusTarget ? 0 : -1}
                    disabled={dayDisabled}
                    aria-selected={isSelected}
                    aria-current={isToday ? 'date' : undefined}
                    onClick={() => commit(day)}
                    onFocus={() => setFocusedDate(day)}
                    className={cn(
                      'flex h-8 w-8 items-center justify-center rounded-md text-xs outline-none transition-all duration-150',
                      'focus-visible:ring-2 focus-visible:ring-[var(--gold)]/50',
                      // Color/background are driven by classes, not inline `style`, specifically so the
                      // `hover:` variants below can actually take effect — an inline style always wins
                      // over a Tailwind pseudo-class rule regardless of hover state, which is why this
                      // cell previously had no visible hover effect at all despite one being coded.
                      !inMonth ? 'text-[var(--text-muted)]' : isSelected ? 'text-[var(--accent-fg)]' : 'text-[var(--text-primary)]',
                      isSelected ? 'bg-[var(--gold)]' : 'bg-transparent',
                      dayDisabled && 'cursor-not-allowed opacity-30',
                      !dayDisabled && !isSelected && 'cursor-pointer hover:scale-110 hover:bg-[var(--bg-elevated)] hover:text-[var(--gold)]',
                      !dayDisabled && isSelected && 'cursor-pointer hover:scale-110 hover:opacity-90',
                    )}
                    style={{
                      fontWeight: isToday && !isSelected ? 700 : 400,
                      boxShadow: isToday && !isSelected ? 'inset 0 0 0 1px var(--gold)' : undefined,
                    }}
                  >
                    {day.getDate()}
                  </button>
                );
              })}
            </div>
          </Popover.Content>
        </Popover.Portal>
      </Popover.Root>

      {error ? (
        <p className="text-xs" style={{ color: '#ef4444' }}>{error}</p>
      ) : helperText ? (
        <p className="text-xs" style={{ color: 'var(--text-muted)' }}>{helperText}</p>
      ) : null}
    </div>
  );
}
