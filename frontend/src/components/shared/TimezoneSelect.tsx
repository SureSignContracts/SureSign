'use client';

import { getIanaTimezones } from '@/lib/timezones';

/**
 * Single shared IANA timezone dropdown — extracted in Phase 2 of
 * Appointments & Scheduling after the same selector had been independently
 * duplicated across Company Settings, project Meetings, and (twice more)
 * the Phase 1 Appointments pages. Always offers the current `value` even if
 * it isn't in the IANA list (never silently drops an already-stored,
 * possibly-legacy timezone value from the dropdown).
 */
export default function TimezoneSelect({
  value,
  onChange,
  id,
  background = 'var(--bg-elevated)',
  className,
}: {
  value: string;
  onChange: (v: string) => void;
  id?: string;
  background?: string;
  className?: string;
}) {
  const timezones = getIanaTimezones();
  return (
    <select
      id={id}
      value={value}
      onChange={e => onChange(e.target.value)}
      className={className ?? 'w-full px-3 py-2.5 rounded-lg text-sm outline-none'}
      style={{ backgroundColor: background, border: '1px solid var(--border)', color: 'var(--text-primary)' }}
    >
      {!timezones.includes(value) && value && <option value={value}>{value}</option>}
      {timezones.map(tz => <option key={tz} value={tz}>{tz}</option>)}
    </select>
  );
}
