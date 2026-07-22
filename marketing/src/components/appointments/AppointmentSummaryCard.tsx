import { Calendar, Clock, Globe } from 'lucide-react';
import { AppointmentStatusBadge } from './AppointmentStatusBadge';
import { formatDateInZone, formatDurationMinutes, formatTimeInZone, timezoneLabel } from '@/lib/appointmentFormat';
import type { AppointmentPublicView } from '@/lib/publicAppointments';

export function AppointmentSummaryCard({ appointment }: { appointment: AppointmentPublicView }) {
  const tz = appointment.booking_timezone;
  const duration = formatDurationMinutes(appointment.appointment_type.duration_minutes);

  return (
    <div className="rounded-2xl border border-border bg-bg-surface p-8 sm:p-10">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <span className="text-xs font-medium uppercase tracking-wide text-text-muted">{appointment.reference}</span>
        <AppointmentStatusBadge status={appointment.status} />
      </div>

      <h1 className="mt-5 text-2xl font-medium tracking-tight text-text-primary">
        {appointment.appointment_type.name ?? 'Appointment'}
      </h1>

      <dl className="mt-6 space-y-3 text-sm text-text-secondary">
        <div className="flex items-start gap-3">
          <Calendar className="mt-0.5 h-4 w-4 shrink-0 text-text-muted" strokeWidth={1.5} aria-hidden="true" />
          <div>
            <dt className="sr-only">Date</dt>
            <dd>{formatDateInZone(appointment.starts_at, tz)}</dd>
          </div>
        </div>
        <div className="flex items-start gap-3">
          <Clock className="mt-0.5 h-4 w-4 shrink-0 text-text-muted" strokeWidth={1.5} aria-hidden="true" />
          <div>
            <dt className="sr-only">Time</dt>
            <dd>
              {formatTimeInZone(appointment.starts_at, tz)} to {formatTimeInZone(appointment.ends_at, tz)}
              {duration && <span className="text-text-muted"> ({duration})</span>}
            </dd>
          </div>
        </div>
        <div className="flex items-start gap-3">
          <Globe className="mt-0.5 h-4 w-4 shrink-0 text-text-muted" strokeWidth={1.5} aria-hidden="true" />
          <div>
            <dt className="sr-only">Timezone</dt>
            <dd>{timezoneLabel(appointment.starts_at, tz)}</dd>
          </div>
        </div>
      </dl>
    </div>
  );
}
