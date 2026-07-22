// Human-readable status wording and timezone-aware date/time formatting for
// the public appointment pages. The backend keeps raw workflow enum values
// (see AppointmentWorkflowService::TRANSITIONS) — this is the single place
// that translates them for attendees.

export type StatusTone = 'info' | 'success' | 'warning' | 'muted' | 'danger';

export const TERMINAL_STATUSES = ['cancelled', 'declined', 'completed', 'no_show'];

const STATUS_COPY: Record<string, { label: string; tone: StatusTone }> = {
  requested: { label: 'Request received', tone: 'warning' },
  pending_confirmation: { label: 'Awaiting confirmation', tone: 'warning' },
  confirmed: { label: 'Confirmed', tone: 'success' },
  declined: { label: 'Declined', tone: 'danger' },
  cancelled: { label: 'Cancelled', tone: 'muted' },
  completed: { label: 'Completed', tone: 'muted' },
  no_show: { label: 'Marked as no-show', tone: 'muted' },
};

export function statusLabel(status: string): string {
  return STATUS_COPY[status]?.label ?? status;
}

export function statusTone(status: string): StatusTone {
  return STATUS_COPY[status]?.tone ?? 'info';
}

export function isTerminalStatus(status: string): boolean {
  return TERMINAL_STATUSES.includes(status);
}

export function formatDateInZone(iso: string, timeZone: string): string {
  return new Intl.DateTimeFormat('en-GB', {
    weekday: 'long', day: 'numeric', month: 'long', year: 'numeric', timeZone,
  }).format(new Date(iso));
}

export function formatTimeInZone(iso: string, timeZone: string): string {
  return new Intl.DateTimeFormat('en-GB', {
    hour: 'numeric', minute: '2-digit', timeZone,
  }).format(new Date(iso));
}

export function formatDurationMinutes(minutes: number | null): string | null {
  if (!minutes) return null;
  if (minutes < 60) return `${minutes} minutes`;
  const hours = Math.floor(minutes / 60);
  const rest = minutes % 60;
  return rest === 0 ? `${hours} hour${hours > 1 ? 's' : ''}` : `${hours}h ${rest}m`;
}

/** Short, unambiguous timezone label, e.g. "Europe/London (GMT+1)". */
export function timezoneLabel(iso: string, timeZone: string): string {
  const offsetPart = new Intl.DateTimeFormat('en-GB', {
    timeZone, timeZoneName: 'shortOffset',
  }).formatToParts(new Date(iso)).find(p => p.type === 'timeZoneName');
  return offsetPart ? `${timeZone} (${offsetPart.value})` : timeZone;
}
