'use client';

import { useEffect, useMemo, useRef, useState } from 'react';
import { CalendarX2, ChevronLeft, ChevronRight } from 'lucide-react';
import { getGsap } from '@/lib/gsap';
import { useReducedMotion } from '@/lib/useReducedMotion';
import {
  addDaysIso, addMonths, compareIso, daysInMonth, firstWeekdayOffset,
  monthLabel, parseIsoDate, toIsoDate, weekdayLabels, type YearMonth,
} from '@/lib/calendarDate';

type DateStatus = 'available' | 'unavailable' | 'pending' | 'out_of_window' | 'past';

export function BookingCalendar({
  todayIso,
  maxDateIso,
  selectedDateIso,
  onSelectDate,
  onMonthChange,
  restrictToBookable = false,
  bookableDates,
  loadingAvailability = false,
}: {
  todayIso: string;
  maxDateIso?: string;
  selectedDateIso: string | null;
  onSelectDate: (iso: string) => void;
  /** Called whenever the visible month changes (including on mount) so the parent can fetch that month's availability. Month is 1-12. */
  onMonthChange?: (year: number, month: number) => void;
  /** True for fixed-staff scheduling — only dates in `bookableDates` are selectable. False (manual mode) allows any in-window date, matching the backend's own manual-mode UX. */
  restrictToBookable?: boolean;
  bookableDates?: Set<string>;
  loadingAvailability?: boolean;
}) {
  const today = parseIsoDate(todayIso);
  const [view, setView] = useState<YearMonth>({ year: today.year, month: today.month });
  const [focusedIso, setFocusedIso] = useState(selectedDateIso ?? todayIso);
  const gridRef = useRef<HTMLDivElement>(null);
  const reduced = useReducedMotion();

  useEffect(() => {
    onMonthChange?.(view.year, view.month + 1);
  }, [view.year, view.month]); // eslint-disable-line react-hooks/exhaustive-deps

  // A subtle month-transition reveal — fires whenever the visible month
  // changes, or once its availability finishes loading, so the grid never
  // pops in instantly with stale-looking colouring.
  useEffect(() => {
    if (reduced || !gridRef.current || loadingAvailability) return;
    const { gsap } = getGsap();
    gsap.fromTo(gridRef.current, { opacity: 0 }, { opacity: 1, duration: 0.3, ease: 'power2.out' });
  }, [view.year, view.month, loadingAvailability, reduced]);

  const cells = useMemo(() => {
    const offset = firstWeekdayOffset(view.year, view.month);
    const total = daysInMonth(view.year, view.month);
    const out: { iso: string; day: number; inMonth: boolean }[] = [];

    const prev = addMonths(view, -1);
    const prevTotal = daysInMonth(prev.year, prev.month);
    for (let i = offset - 1; i >= 0; i--) {
      out.push({ iso: toIsoDate(prev.year, prev.month, prevTotal - i), day: prevTotal - i, inMonth: false });
    }
    for (let d = 1; d <= total; d++) {
      out.push({ iso: toIsoDate(view.year, view.month, d), day: d, inMonth: true });
    }
    const next = addMonths(view, 1);
    let nextDay = 1;
    while (out.length % 7 !== 0) {
      out.push({ iso: toIsoDate(next.year, next.month, nextDay), day: nextDay, inMonth: false });
      nextDay++;
    }
    return out;
  }, [view]);

  function dateStatus(iso: string): DateStatus {
    if (compareIso(iso, todayIso) < 0) return 'past';
    if (maxDateIso && compareIso(iso, maxDateIso) > 0) return 'out_of_window';
    if (restrictToBookable) {
      if (loadingAvailability) return 'pending';
      if (!bookableDates?.has(iso)) return 'unavailable';
    }
    return 'available';
  }

  function isSelectable(iso: string) {
    return dateStatus(iso) === 'available';
  }

  function moveFocus(fromIso: string, deltaDays: number) {
    let next = addDaysIso(fromIso, deltaDays);
    // Skip past non-selectable days in the direction of travel so arrow keys never get stuck.
    let guard = 0;
    while (!isSelectable(next) && guard < 400) {
      next = addDaysIso(next, deltaDays > 0 ? 1 : -1);
      guard++;
    }
    const parsed = parseIsoDate(next);
    setView({ year: parsed.year, month: parsed.month });
    setFocusedIso(next);
    requestAnimationFrame(() => {
      gridRef.current?.querySelector<HTMLButtonElement>(`[data-iso="${next}"]`)?.focus();
    });
  }

  function mondayFirstWeekday(iso: string): number {
    const { year, month, day } = parseIsoDate(iso);
    return (new Date(year, month, day).getDay() + 6) % 7; // 0 = Monday .. 6 = Sunday
  }

  function handleKeyDown(e: React.KeyboardEvent, iso: string) {
    const moves: Record<string, number> = {
      ArrowRight: 1, ArrowLeft: -1, ArrowDown: 7, ArrowUp: -7,
    };
    if (e.key in moves) {
      e.preventDefault();
      moveFocus(iso, moves[e.key]);
    } else if (e.key === 'Home') {
      e.preventDefault();
      moveFocus(iso, -mondayFirstWeekday(iso));
    } else if (e.key === 'End') {
      e.preventDefault();
      moveFocus(iso, 6 - mondayFirstWeekday(iso));
    }
  }

  const canGoPrev = view.year * 12 + view.month > today.year * 12 + today.month;
  const canGoNext = !maxDateIso || compareIso(toIsoDate(view.year, view.month, 1), maxDateIso) <= 0;
  const isMonthEmpty = restrictToBookable && !loadingAvailability && (bookableDates?.size ?? 0) === 0;

  return (
    <div className="rounded-2xl border border-border bg-bg-surface p-5">
      <div className="flex items-center justify-between">
        <p className="text-sm font-medium text-text-primary">{monthLabel(view)}</p>
        <div className="flex items-center gap-1">
          <button
            type="button"
            aria-label="Previous month"
            disabled={!canGoPrev}
            onClick={() => setView(v => addMonths(v, -1))}
            className="flex h-8 w-8 items-center justify-center rounded-full border border-border text-text-secondary transition-colors hover:border-border-light hover:text-text-primary disabled:pointer-events-none disabled:opacity-30"
          >
            <ChevronLeft className="h-4 w-4" strokeWidth={1.5} />
          </button>
          <button
            type="button"
            aria-label="Next month"
            disabled={!canGoNext}
            onClick={() => setView(v => addMonths(v, 1))}
            className="flex h-8 w-8 items-center justify-center rounded-full border border-border text-text-secondary transition-colors hover:border-border-light hover:text-text-primary disabled:pointer-events-none disabled:opacity-30"
          >
            <ChevronRight className="h-4 w-4" strokeWidth={1.5} />
          </button>
        </div>
      </div>

      {isMonthEmpty ? (
        <div className="flex flex-col items-center px-4 py-10 text-center">
          <CalendarX2 className="h-6 w-6 text-text-muted" strokeWidth={1.5} aria-hidden="true" />
          <p className="mt-3 text-sm font-medium text-text-primary">No appointments are currently available during this period.</p>
          <p className="mt-1 text-sm text-text-secondary">Please try another month.</p>
        </div>
      ) : (
      <>
      <div className="mt-4 grid grid-cols-7 gap-y-1 text-center text-xs text-text-muted">
        {weekdayLabels().map(d => <div key={d} className="py-1">{d}</div>)}
      </div>

      <div ref={gridRef} role="grid" aria-label="Choose a date" aria-busy={loadingAvailability} className="grid grid-cols-7 gap-y-1">
        {cells.map(({ iso, day, inMonth }) => {
          const status = dateStatus(iso);
          const disabled = status !== 'available' || !inMonth;
          const selected = selectedDateIso === iso;
          const isToday = iso === todayIso;
          const isRovingTarget = iso === focusedIso;

          const statusClass = selected
            ? 'bg-accent font-medium text-accent-fg'
            : status === 'pending'
              ? 'cursor-not-allowed text-text-muted opacity-50 animate-pulse'
              : status === 'unavailable'
                ? 'cursor-not-allowed text-text-muted opacity-60'
                : status === 'past' || status === 'out_of_window'
                  ? 'cursor-not-allowed text-text-muted opacity-30'
                  : 'text-text-primary hover:bg-bg-elevated';

          return (
            <div key={iso} role="gridcell" className="flex items-center justify-center py-0.5">
              <button
                type="button"
                data-iso={iso}
                disabled={disabled}
                tabIndex={isRovingTarget ? 0 : -1}
                aria-pressed={selected}
                aria-current={isToday ? 'date' : undefined}
                aria-label={`${iso}${status === 'unavailable' ? ' — no availability' : ''}`}
                onFocus={() => setFocusedIso(iso)}
                onKeyDown={e => handleKeyDown(e, iso)}
                onClick={() => { setFocusedIso(iso); onSelectDate(iso); }}
                className={`relative flex h-9 w-9 items-center justify-center rounded-full text-sm transition-all duration-150 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-text-primary ${
                  !inMonth ? 'invisible' : ''
                } ${statusClass}`}
              >
                {day}
                {isToday && !selected && (
                  <span aria-hidden className="absolute bottom-1 h-1 w-1 rounded-full bg-text-muted" />
                )}
              </button>
            </div>
          );
        })}
      </div>

      {restrictToBookable && (
        <div className="mt-4 flex flex-wrap items-center gap-x-4 gap-y-1.5 text-xs text-text-muted">
          <span className="flex items-center gap-1.5"><span className="h-2 w-2 rounded-full bg-text-primary" aria-hidden="true" /> Available</span>
          <span className="flex items-center gap-1.5"><span className="h-2 w-2 rounded-full bg-text-muted opacity-60" aria-hidden="true" /> No availability</span>
        </div>
      )}
      </>
      )}
    </div>
  );
}
