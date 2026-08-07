'use client';

import { getIanaTimezones } from '@/lib/timezones';
import Combobox from '@/components/ui/Combobox';

/**
 * Single shared IANA timezone dropdown — extracted in Phase 2 of
 * Appointments & Scheduling after the same selector had been independently
 * duplicated across Company Settings, project Meetings, and (twice more)
 * the Phase 1 Appointments pages. Always offers the current `value` even if
 * it isn't in the IANA list (never silently drops an already-stored,
 * possibly-legacy timezone value from the dropdown).
 *
 * Built on the shared `Combobox` (not `Select`) — `getIanaTimezones()`
 * returns 400+ entries in modern browsers (`Intl.supportedValuesOf`), the
 * exact "long list, hard to pick without search" case `Combobox` exists
 * for.
 */
export default function TimezoneSelect({
  value,
  onChange,
  id,
  background,
  className,
}: {
  value: string;
  onChange: (v: string) => void;
  id?: string;
  background?: string;
  className?: string;
}) {
  const timezones = getIanaTimezones();
  const options = timezones.includes(value) || !value
    ? timezones.map(tz => ({ value: tz, label: tz }))
    : [{ value, label: value }, ...timezones.map(tz => ({ value: tz, label: tz }))];

  return (
    <Combobox
      id={id}
      value={value}
      onValueChange={onChange}
      options={options}
      placeholder="Select timezone…"
      searchPlaceholder="Search timezones…"
      emptyMessage="No timezone found."
      className={className}
      style={background ? { backgroundColor: background } : undefined}
      aria-label="Timezone"
    />
  );
}
