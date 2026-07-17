// Single shared date/time formatting module (SureSign timezone architecture,
// Batch 3 — foundation only, not yet consumed by any page; that's Batch 5).
//
// The rule this module exists to enforce:
//   - DATE-only values (e.g. "2026-07-16") never shift with timezone. They
//     represent a calendar day, not an instant, and must render as the same
//     day everywhere regardless of the viewer's timezone.
//   - DATETIME values (UTC ISO strings, e.g. "2026-07-16T13:00:00Z") are one
//     absolute instant. They always convert for display — to an explicitly
//     passed timezone (the effective org/user timezone from `/auth/me`), or
//     to the browser's own local timezone if none is passed.
//
// This module has exactly one caller-visible knob for that distinction: pass
// a `timeZone` in FormatOptions for DATETIME formatting, or don't — never
// pass one for DATE-only formatting, since a date has no timezone to convert.

import { useAuthStore } from '@/store/authStore';

export interface FormatOptions {
  /** IANA identifier (e.g. "Europe/London"). Omit to use the browser's own local timezone. */
  timeZone?: string;
  locale?: string;
}

const DEFAULT_LOCALE = 'en-GB';

/**
 * Parse a DATE-only string ("YYYY-MM-DD") into a Date representing local
 * midnight on that calendar day — NOT `new Date("YYYY-MM-DD")`, which the
 * spec parses as UTC midnight and can therefore roll back a day when
 * displayed in a negative-UTC-offset timezone. Constructing from explicit
 * y/m/d components sidesteps that entirely: this Date's calendar day is
 * fixed and never shifts, in any timezone.
 */
export function parseDateOnly(dateOnly: string): Date {
  const [year, month, day] = dateOnly.split('-').map(Number);
  return new Date(year, month - 1, day);
}

/**
 * Format a DATE-only value. Never applies a timezone conversion — a
 * calendar date has no time-of-day to convert, by design (see module
 * doc comment).
 */
export function formatDateOnly(
  dateOnly: string | null | undefined,
  opts: Pick<FormatOptions, 'locale'> = {}
): string {
  if (!dateOnly) return '';
  return parseDateOnly(dateOnly).toLocaleDateString(opts.locale ?? DEFAULT_LOCALE, {
    day: 'numeric', month: 'short', year: 'numeric',
  });
}

/**
 * Format a DATETIME value (a UTC ISO string) for display, converting to
 * `opts.timeZone` if given, otherwise the browser's own local timezone.
 */
export function formatDateTime(utcIso: string | null | undefined, opts: FormatOptions = {}): string {
  if (!utcIso) return '';
  return new Date(utcIso).toLocaleString(opts.locale ?? DEFAULT_LOCALE, {
    day: 'numeric', month: 'short', year: 'numeric',
    hour: '2-digit', minute: '2-digit',
    timeZone: opts.timeZone,
  });
}

/**
 * Format just the time-of-day portion of a DATETIME value, converting to
 * `opts.timeZone` if given, otherwise the browser's own local timezone.
 */
export function formatTime(utcIso: string | null | undefined, opts: FormatOptions = {}): string {
  if (!utcIso) return '';
  return new Date(utcIso).toLocaleTimeString(opts.locale ?? DEFAULT_LOCALE, {
    hour: '2-digit', minute: '2-digit',
    timeZone: opts.timeZone,
  });
}

/**
 * Human-relative rendering of a DATETIME value ("3 hours ago", "in 2 days")
 * relative to now. Relative phrasing is timezone-independent (it's a
 * duration, not a wall-clock rendering), so there's no `timeZone` option
 * here — that's intentional, not an oversight.
 */
export function formatRelativeTime(utcIso: string | null | undefined, opts: Pick<FormatOptions, 'locale'> = {}): string {
  if (!utcIso) return '';

  const diffMs = new Date(utcIso).getTime() - Date.now();
  const diffSec = Math.round(diffMs / 1000);

  const units: [Intl.RelativeTimeFormatUnit, number][] = [
    ['year', 60 * 60 * 24 * 365],
    ['month', 60 * 60 * 24 * 30],
    ['week', 60 * 60 * 24 * 7],
    ['day', 60 * 60 * 24],
    ['hour', 60 * 60],
    ['minute', 60],
    ['second', 1],
  ];

  const rtf = new Intl.RelativeTimeFormat(opts.locale ?? DEFAULT_LOCALE, { numeric: 'auto' });

  for (const [unit, secondsInUnit] of units) {
    if (Math.abs(diffSec) >= secondsInUnit || unit === 'second') {
      return rtf.format(Math.round(diffSec / secondsInUnit), unit);
    }
  }
  return rtf.format(0, 'second');
}

/**
 * True when a DATETIME instant falls on "today" in the given timezone
 * (defaults to the browser's own local timezone). Used for day-grouping
 * (e.g. a notification list's "Today" vs "Earlier" split) — pass the
 * viewer's effective timezone explicitly rather than relying on the
 * browser's system clock, which may not match their SureSign preference.
 */
export function isToday(utcIso: string | null | undefined, timeZone?: string): boolean {
  if (!utcIso) return false;
  const asYmd = (d: Date) => new Intl.DateTimeFormat('en-CA', { timeZone }).format(d);
  return asYmd(new Date(utcIso)) === asYmd(new Date());
}

/**
 * "Today" as a "YYYY-MM-DD" string in the viewer's effective timezone —
 * the single implementation for every client-side overdue/due-soon/health
 * re-derivation against a DATE-only field (payment_notice_deadline,
 * planned_date, etc.). Several pages independently computed this via
 * `new Date().toISOString().split('T')[0]` (the current UTC instant's
 * *UTC* calendar day) before Batch 5 — silently disagreeing with the
 * backend's own organisation-timezone-aware "today" (TimezoneResolver)
 * near a UTC/local midnight boundary. Use this instead of reinventing it.
 */
export function effectiveTodayYmd(): string {
  const timezone = useAuthStore.getState().user?.effective_timezone;
  return new Intl.DateTimeFormat('en-CA', { timeZone: timezone }).format(new Date());
}

/**
 * Convert a timezone-less wall-clock string (e.g. from an
 * `<input type="datetime-local">`, "2026-07-16T23:47") into a UTC ISO
 * string for the API. `new Date(...)` parses a timezone-less string as
 * local time per spec, so `.toISOString()` correctly produces the real UTC
 * instant.
 */
export function toUtcIso(localDateTimeString: string): string {
  return new Date(localDateTimeString).toISOString();
}

/**
 * The inverse of toUtcIso() — format a UTC ISO string back into the
 * timezone-less wall-clock format `<input type="datetime-local">` expects,
 * in the browser's local timezone (matching what the input itself displays).
 */
export function fromUtcIso(utcIso: string | null | undefined): string {
  if (!utcIso) return '';
  const d = new Date(utcIso);
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}
