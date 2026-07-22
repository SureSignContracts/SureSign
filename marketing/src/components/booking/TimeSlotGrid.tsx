'use client';

import { useEffect, useRef } from 'react';
import { CalendarX2 } from 'lucide-react';
import { getGsap } from '@/lib/gsap';
import { useReducedMotion } from '@/lib/useReducedMotion';

export interface Slot {
  /** The slot's own calendar date, already labelled in the display timezone — may differ from the requested date near a timezone's midnight boundary. */
  date: string;
  time: string;
}

function slotKey(slot: Slot): string {
  return `${slot.date}T${slot.time}`;
}

function slotHour(slot: Slot): number {
  return parseInt(slot.time.split(':')[0], 10);
}

function groupSlots(slots: Slot[]) {
  const morning = slots.filter(s => slotHour(s) < 12);
  const afternoon = slots.filter(s => slotHour(s) >= 12 && slotHour(s) < 17);
  const evening = slots.filter(s => slotHour(s) >= 17);
  return [
    { label: 'Morning', slots: morning },
    { label: 'Afternoon', slots: afternoon },
    { label: 'Evening', slots: evening },
  ].filter(g => g.slots.length > 0);
}

export function TimeSlotSkeleton() {
  return (
    <div aria-hidden className="space-y-4">
      {[4, 4].map((count, groupIndex) => (
        <div key={groupIndex}>
          <div className="h-3 w-16 animate-pulse rounded bg-bg-elevated" />
          <div className="mt-2 grid grid-cols-3 gap-2 sm:grid-cols-4">
            {Array.from({ length: count }).map((_, i) => (
              <div key={i} className="h-10 animate-pulse rounded-full bg-bg-elevated" />
            ))}
          </div>
        </div>
      ))}
    </div>
  );
}

export function TimeSlotEmptyState() {
  return (
    <div className="flex flex-col items-center rounded-xl border border-dashed border-border px-6 py-10 text-center">
      <CalendarX2 className="h-6 w-6 text-text-muted" strokeWidth={1.5} aria-hidden="true" />
      <p className="mt-3 text-sm font-medium text-text-primary">No appointments are available for this day.</p>
      <p className="mt-1 text-sm text-text-secondary">Please choose another date.</p>
    </div>
  );
}

export function TimeSlotGrid({
  slots,
  selected,
  onSelect,
  /** The requested calendar date — a slot whose own `date` differs from this (a timezone-crossing edge case) gets an explicit "(Mon 28)"-style annotation so it's never ambiguous which day it actually falls on. */
  referenceDate,
}: {
  slots: Slot[];
  selected: Slot | null;
  onSelect: (slot: Slot) => void;
  referenceDate?: string;
}) {
  const groups = groupSlots(slots);
  const gridRef = useRef<HTMLDivElement>(null);
  const reduced = useReducedMotion();

  useEffect(() => {
    if (reduced || !gridRef.current) return;
    const { gsap } = getGsap();
    const buttons = gridRef.current.querySelectorAll('button');
    gsap.set(buttons, { opacity: 0, y: 6 });
    gsap.to(buttons, { opacity: 1, y: 0, duration: 0.3, ease: 'power2.out', stagger: 0.02 });
  }, [slots.map(slotKey).join(','), reduced]); // eslint-disable-line react-hooks/exhaustive-deps

  return (
    <div ref={gridRef} className="space-y-4">
      {groups.map(group => (
        <div key={group.label}>
          <p className="text-xs font-medium uppercase tracking-wide text-text-muted">{group.label}</p>
          <div className="mt-2 grid grid-cols-3 gap-2 sm:grid-cols-4" role="group" aria-label={`${group.label} times`}>
            {group.slots.map(slot => {
              const isSelected = selected !== null && slotKey(selected) === slotKey(slot);
              const crossesDay = referenceDate !== undefined && slot.date !== referenceDate;
              return (
                <button
                  key={slotKey(slot)}
                  type="button"
                  aria-pressed={isSelected}
                  onClick={() => onSelect(slot)}
                  className={`flex flex-col items-center rounded-full border px-3 py-2.5 text-sm font-medium transition-all duration-150 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-text-primary ${
                    isSelected
                      ? 'border-accent bg-accent text-accent-fg'
                      : 'border-border text-text-primary hover:border-border-light hover:bg-bg-elevated'
                  }`}
                >
                  {slot.time}
                  {crossesDay && (
                    <span className={`text-[10px] font-normal ${isSelected ? 'text-accent-fg/80' : 'text-text-muted'}`}>
                      {new Intl.DateTimeFormat('en-GB', { day: 'numeric', month: 'short' }).format(new Date(`${slot.date}T00:00:00`))}
                    </span>
                  )}
                </button>
              );
            })}
          </div>
        </div>
      ))}
    </div>
  );
}
