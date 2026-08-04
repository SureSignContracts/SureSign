import { Calendar, Clock, Globe, HeartHandshake } from 'lucide-react';
import { formatFullDate } from '@/lib/calendarDate';
import { LiveField } from '../booking/LiveField';

function to12Hour(time: string): string {
  const [h, m] = time.split(':').map(Number);
  const period = h >= 12 ? 'PM' : 'AM';
  const hour12 = h % 12 === 0 ? 12 : h % 12;
  return `${hour12}:${String(m).padStart(2, '0')} ${period}`;
}

export function ConsultancySummaryCard({
  serviceName,
  durationMinutes,
  priceLabel,
  dateIso,
  time,
  timezone,
}: {
  serviceName: string;
  durationMinutes: number;
  priceLabel: string | null;
  dateIso: string | null;
  time: string | null;
  timezone: string;
}) {
  return (
    <div className="rounded-2xl border border-border bg-bg-surface p-6 shadow-[var(--shadow-card)] sm:p-7">
      <p className="text-xs font-medium uppercase tracking-wide text-text-muted">Consultation</p>
      <h2 className="mt-2 text-lg font-medium tracking-tight text-text-primary">{serviceName}</h2>

      <div className="mt-6 space-y-4 text-sm">
        <div className="flex items-start gap-3 text-text-secondary">
          <Clock className="mt-0.5 h-4 w-4 shrink-0 text-text-muted" strokeWidth={1.5} aria-hidden="true" />
          <span>{durationMinutes} minutes{priceLabel ? ` · ${priceLabel}` : ''}</span>
        </div>

        <div className="flex items-start gap-3 text-text-secondary">
          <Calendar className="mt-0.5 h-4 w-4 shrink-0 text-text-muted" strokeWidth={1.5} aria-hidden="true" />
          <LiveField value={dateIso ?? 'none'}>
            {dateIso
              ? <span className="text-text-primary">{formatFullDate(dateIso)}</span>
              : <span className="text-text-muted">Choose a date to continue</span>}
          </LiveField>
        </div>

        {time && (
          <div className="flex items-start gap-3 text-text-secondary">
            <Clock className="mt-0.5 h-4 w-4 shrink-0 text-text-muted opacity-0" aria-hidden="true" />
            <LiveField value={time}>
              <span className="text-text-primary">{to12Hour(time)}</span>
            </LiveField>
          </div>
        )}

        <div className="flex items-start gap-3 text-text-secondary">
          <Globe className="mt-0.5 h-4 w-4 shrink-0 text-text-muted" strokeWidth={1.5} aria-hidden="true" />
          <LiveField value={timezone}>
            <span>{timezone}</span>
          </LiveField>
        </div>

        <div className="flex items-start gap-3 text-text-secondary">
          <HeartHandshake className="mt-0.5 h-4 w-4 shrink-0 text-text-muted" strokeWidth={1.5} aria-hidden="true" />
          <div>
            <p className="text-xs text-text-muted">Hosted by</p>
            <p className="text-text-primary">SureSign Consultancy</p>
          </div>
        </div>
      </div>
    </div>
  );
}
