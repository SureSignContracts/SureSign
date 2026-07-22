// Single source of truth for the IANA timezone list on the marketing site's
// public booking page. Mirrors frontend/src/lib/timezones.ts — kept as a
// separate copy deliberately, since this is a genuinely separate Next.js
// app/deployment, not a second implementation within the same app.
const FALLBACK_TIMEZONES = [
  'UTC',
  'Europe/London',
  'Europe/Dublin',
  'Europe/Paris',
  'Europe/Berlin',
  'Asia/Manila',
  'Asia/Singapore',
  'Asia/Hong_Kong',
  'Asia/Tokyo',
  'Asia/Dubai',
  'Asia/Kolkata',
  'America/New_York',
  'America/Chicago',
  'America/Denver',
  'America/Los_Angeles',
  'America/Toronto',
  'Australia/Sydney',
  'Australia/Melbourne',
  'Australia/Perth',
  'Pacific/Auckland',
];

export function getIanaTimezones(): string[] {
  if (typeof Intl.supportedValuesOf === 'function') {
    try {
      return Intl.supportedValuesOf('timeZone');
    } catch {
      // Fall through to the static list below.
    }
  }
  return FALLBACK_TIMEZONES;
}

export function detectBrowserTimezone(): string {
  try {
    return Intl.DateTimeFormat().resolvedOptions().timeZone || 'Europe/London';
  } catch {
    return 'Europe/London';
  }
}
