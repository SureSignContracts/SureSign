// Pure date-grid math for BookingCalendar. Every function here takes and
// returns plain numbers/ISO strings — no `new Date()`/`Date.now()` (argless)
// anywhere, since those differ between server and client render and would
// cause a hydration mismatch. `new Date(year, month, day)` with explicit
// numeric args is deterministic and fine to call during render.

export interface YearMonth {
  year: number;
  month: number; // 0-11
}

export function parseIsoDate(iso: string): { year: number; month: number; day: number } {
  const [year, month, day] = iso.split('-').map(Number);
  return { year, month: month - 1, day };
}

export function toIsoDate(year: number, month: number, day: number): string {
  const mm = String(month + 1).padStart(2, '0');
  const dd = String(day).padStart(2, '0');
  return `${year}-${mm}-${dd}`;
}

export function daysInMonth(year: number, month: number): number {
  return new Date(year, month + 1, 0).getDate();
}

/** Monday-first weekday index (0 = Monday .. 6 = Sunday) for the 1st of the month. */
export function firstWeekdayOffset(year: number, month: number): number {
  const jsDay = new Date(year, month, 1).getDay(); // 0 = Sunday .. 6 = Saturday
  return (jsDay + 6) % 7;
}

export function addMonths({ year, month }: YearMonth, delta: number): YearMonth {
  const total = year * 12 + month + delta;
  return { year: Math.floor(total / 12), month: ((total % 12) + 12) % 12 };
}

export function compareIso(a: string, b: string): number {
  return a < b ? -1 : a > b ? 1 : 0;
}

export function addDaysIso(iso: string, days: number): string {
  const { year, month, day } = parseIsoDate(iso);
  const d = new Date(year, month, day + days);
  return toIsoDate(d.getFullYear(), d.getMonth(), d.getDate());
}

const MONTH_LABELS = [
  'January', 'February', 'March', 'April', 'May', 'June',
  'July', 'August', 'September', 'October', 'November', 'December',
];

export function monthLabel({ year, month }: YearMonth): string {
  return `${MONTH_LABELS[month]} ${year}`;
}

const WEEKDAY_LABELS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

export function weekdayLabels(): string[] {
  return WEEKDAY_LABELS;
}

export function formatFullDate(iso: string): string {
  const { year, month, day } = parseIsoDate(iso);
  return new Intl.DateTimeFormat('en-GB', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })
    .format(new Date(year, month, day));
}
