// Single source of truth for the IANA timezone list used by every timezone
// selector in the app (Company Settings, User Preferences). Only real IANA
// identifiers are ever offered — never raw offsets like "UTC+8"/"GMT+1".
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
