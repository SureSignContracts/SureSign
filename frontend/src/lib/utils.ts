import { clsx, type ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';
import { parseDateOnly } from './dateTime';
import { useAuthStore } from '@/store/authStore';

export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs));
}

export function formatCurrency(amount: number | string, currency = 'GBP'): string {
  const num = typeof amount === 'string' ? parseFloat(amount) : amount;
  if (isNaN(num)) return '—';
  return new Intl.NumberFormat('en-GB', { style: 'currency', currency }).format(num);
}

const DATE_ONLY_RE = /^\d{4}-\d{2}-\d{2}$/;
const DATE_FORMAT_OPTS: Intl.DateTimeFormatOptions = { day: '2-digit', month: 'short', year: 'numeric' };

/**
 * The app's single short-date formatter ("16 Jul 2026") — used across ~40
 * pages for both DATE-only business fields (due_date, completion_date, ...)
 * and DATETIME instants (created_at, decided_at, ...) alike. Visual output
 * format is unchanged from before; only the underlying correctness fixed:
 *
 * - A bare "YYYY-MM-DD" string is a DATE-only value (no time-of-day, no
 *   timezone) — parsed via parseDateOnly() (local calendar components, not
 *   `new Date("YYYY-MM-DD")`, which the spec parses as UTC midnight and can
 *   roll the date back a day for a negative-UTC-offset viewer) so it never
 *   shifts, matching lib/dateTime.ts's DATE-only rule.
 * - Anything else is a genuine DATETIME instant — its calendar day is
 *   resolved in the viewer's effective timezone (organisation/user
 *   preference from /auth/me), not whatever timezone the browser's OS
 *   happens to be set to, before formatting.
 * - A `Date` object passed directly (e.g. a client-built calendar grid
 *   cell, not a backend value) is formatted using its own local time, same
 *   as before — it isn't a UTC instant from the API to begin with.
 */
export function formatDate(date: string | Date | null | undefined): string {
  if (!date) return '—';

  if (date instanceof Date) {
    if (isNaN(date.getTime())) return '—';
    return new Intl.DateTimeFormat('en-AU', DATE_FORMAT_OPTS).format(date);
  }

  if (DATE_ONLY_RE.test(date)) {
    return new Intl.DateTimeFormat('en-AU', DATE_FORMAT_OPTS).format(parseDateOnly(date));
  }

  const parsed = new Date(date);
  if (isNaN(parsed.getTime())) return '—';
  const effectiveTimezone = useAuthStore.getState().user?.effective_timezone;
  return new Intl.DateTimeFormat('en-AU', { ...DATE_FORMAT_OPTS, timeZone: effectiveTimezone }).format(parsed);
}

export function getInitials(name: string): string {
  return name.split(' ').map(n => n[0]).slice(0, 2).join('').toUpperCase();
}
