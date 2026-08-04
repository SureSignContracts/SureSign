import { Calendar, Clock, Globe, Download, FileText } from 'lucide-react';
import { AppointmentStatusBadge } from '@/components/appointments/AppointmentStatusBadge';
import { formatDateInZone, formatTimeInZone, timezoneLabel } from '@/lib/appointmentFormat';
import type { ConsultationPublicView } from '@/lib/publicConsultations';
import { MeetJoinBlock } from './MeetJoinBlock';

/**
 * Batch 3, Scope A/B — the public, no-account "view your consultation"
 * card. Mirrors AppointmentSummaryCard's own restrained layout (one card,
 * generous spacing, icon + label pairs) rather than inventing a second
 * visual language for a page that's conceptually the same kind of thing.
 */
export function ConsultationDetailCard({ consultation }: { consultation: ConsultationPublicView }) {
  const tz = consultation.booking_timezone;

  return (
    <div className="rounded-2xl border border-border bg-bg-surface p-8 sm:p-10">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <span className="text-xs font-medium uppercase tracking-wide text-text-muted">{consultation.reference}</span>
        <AppointmentStatusBadge status={consultation.status} />
      </div>

      <h1 className="mt-5 text-2xl font-medium tracking-tight text-text-primary">
        {consultation.consultancy_service?.display_name ?? 'Consultation'}
      </h1>
      {consultation.assigned_consultant?.name && (
        <p className="mt-1 text-sm text-text-secondary">with {consultation.assigned_consultant.name}</p>
      )}

      <dl className="mt-6 space-y-3 text-sm text-text-secondary">
        <div className="flex items-start gap-3">
          <Calendar className="mt-0.5 h-4 w-4 shrink-0 text-text-muted" strokeWidth={1.5} aria-hidden="true" />
          <div>
            <dt className="sr-only">Date</dt>
            <dd>{formatDateInZone(consultation.starts_at, tz)}</dd>
          </div>
        </div>
        <div className="flex items-start gap-3">
          <Clock className="mt-0.5 h-4 w-4 shrink-0 text-text-muted" strokeWidth={1.5} aria-hidden="true" />
          <div>
            <dt className="sr-only">Time</dt>
            <dd>{formatTimeInZone(consultation.starts_at, tz)} to {formatTimeInZone(consultation.ends_at, tz)}</dd>
          </div>
        </div>
        <div className="flex items-start gap-3">
          <Globe className="mt-0.5 h-4 w-4 shrink-0 text-text-muted" strokeWidth={1.5} aria-hidden="true" />
          <div>
            <dt className="sr-only">Timezone</dt>
            <dd>{timezoneLabel(consultation.starts_at, tz)}</dd>
          </div>
        </div>
      </dl>

      <div className="mt-8 border-t border-border pt-6">
        <MeetJoinBlock meeting={consultation.meeting} />
      </div>

      <div className="mt-6 flex flex-wrap items-center gap-x-6 gap-y-3 text-sm">
        {consultation.ics_url && (
          <a href={consultation.ics_url} className="inline-flex items-center gap-2 text-text-secondary underline decoration-border underline-offset-4 hover:text-text-primary">
            <Download className="h-4 w-4" strokeWidth={1.5} aria-hidden="true" />
            Add to calendar
          </a>
        )}
        {consultation.summary_url && (
          <a href={consultation.summary_url} className="inline-flex items-center gap-2 text-text-secondary underline decoration-border underline-offset-4 hover:text-text-primary">
            <FileText className="h-4 w-4" strokeWidth={1.5} aria-hidden="true" />
            View consultation summary
          </a>
        )}
      </div>

      {!consultation.summary_url && (
        <p className="mt-6 text-sm text-text-muted">
          We&apos;ll email you a written summary of this consultation once it&apos;s ready.
        </p>
      )}
    </div>
  );
}
