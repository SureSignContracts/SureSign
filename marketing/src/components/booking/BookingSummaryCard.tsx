import { Calendar, Clock, Globe, Users } from 'lucide-react';
import { formatFullDate } from '@/lib/calendarDate';
import { LiveField } from './LiveField';

export interface BookingSummaryData {
  title: string;
  description: string | null;
  durationMinutes: number;
  dateIso: string | null;
  time: string | null;
  timezone: string;
}

function to12Hour(time: string): string {
  const [h, m] = time.split(':').map(Number);
  const period = h >= 12 ? 'PM' : 'AM';
  const hour12 = h % 12 === 0 ? 12 : h % 12;
  return `${hour12}:${String(m).padStart(2, '0')} ${period}`;
}

export function BookingSummaryCard({ summary }: { summary: BookingSummaryData }) {
  return (
    <div className="rounded-2xl border border-border bg-bg-surface p-6 shadow-[var(--shadow-card)] sm:p-7">
      <p className="text-xs font-medium uppercase tracking-wide text-text-muted">Appointment</p>
      <h2 className="mt-2 text-lg font-medium tracking-tight text-text-primary">{summary.title}</h2>
      {summary.description && <p className="mt-1 text-sm text-text-secondary">{summary.description}</p>}

      <div className="mt-6 space-y-4 text-sm">
        <div className="flex items-start gap-3 text-text-secondary">
          <Clock className="mt-0.5 h-4 w-4 shrink-0 text-text-muted" strokeWidth={1.5} aria-hidden="true" />
          <span>{summary.durationMinutes} minute walkthrough</span>
        </div>

        <div className="flex items-start gap-3 text-text-secondary">
          <Calendar className="mt-0.5 h-4 w-4 shrink-0 text-text-muted" strokeWidth={1.5} aria-hidden="true" />
          <LiveField value={summary.dateIso ?? 'none'}>
            {summary.dateIso
              ? <span className="text-text-primary">{formatFullDate(summary.dateIso)}</span>
              : <span className="text-text-muted">Choose a date to continue</span>}
          </LiveField>
        </div>

        {summary.time && (
          <div className="flex items-start gap-3 text-text-secondary">
            <Clock className="mt-0.5 h-4 w-4 shrink-0 text-text-muted opacity-0" aria-hidden="true" />
            <LiveField value={summary.time}>
              <span className="text-text-primary">{to12Hour(summary.time)}</span>
            </LiveField>
          </div>
        )}

        <div className="flex items-start gap-3 text-text-secondary">
          <Globe className="mt-0.5 h-4 w-4 shrink-0 text-text-muted" strokeWidth={1.5} aria-hidden="true" />
          <LiveField value={summary.timezone}>
            <span>{summary.timezone}</span>
          </LiveField>
        </div>

        <div className="flex items-start gap-3 text-text-secondary">
          <Users className="mt-0.5 h-4 w-4 shrink-0 text-text-muted" strokeWidth={1.5} aria-hidden="true" />
          <div>
            <p className="text-xs text-text-muted">Hosted by</p>
            <p className="text-text-primary">SureSign Team</p>
          </div>
        </div>
      </div>
    </div>
  );
}
