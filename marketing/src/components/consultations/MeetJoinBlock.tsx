import { Video, Clock3, AlertCircle } from 'lucide-react';
import type { ConsultationMeeting } from '@/lib/publicConsultations';

/**
 * Batch 3, Scope B — renders exactly the four customer-safe Meet states
 * ConsultationMeetingPresenter::customerFacing() can return. Never shows a
 * raw URL: the join action is always a button, and every other state is a
 * short, calm status line — no Google/provider detail of any kind.
 */
export function MeetJoinBlock({ meeting }: { meeting: ConsultationMeeting }) {
  if (meeting.status === 'available' && meeting.join_url) {
    return (
      <a
        href={meeting.join_url}
        target="_blank"
        rel="noopener noreferrer"
        className="inline-flex items-center justify-center gap-2 rounded-full bg-accent px-6 py-3 text-sm font-medium text-accent-fg shadow-[var(--shadow-card)] transition-all duration-200 hover:shadow-[var(--shadow-pop)] active:translate-y-px"
      >
        <Video className="h-4 w-4" strokeWidth={1.5} aria-hidden="true" />
        Join Google Meet
      </a>
    );
  }

  if (meeting.status === 'pending' || meeting.status === 'temporarily_unavailable') {
    return (
      <p className="inline-flex items-center gap-2 text-sm text-text-secondary">
        <Clock3 className="h-4 w-4 text-text-muted" strokeWidth={1.5} aria-hidden="true" />
        Your meeting link is being prepared. We&apos;ll email it as soon as it&apos;s ready.
      </p>
    );
  }

  return (
    <p className="inline-flex items-center gap-2 text-sm text-text-secondary">
      <AlertCircle className="h-4 w-4 text-text-muted" strokeWidth={1.5} aria-hidden="true" />
      A meeting link isn&apos;t available for this consultation. Please get in touch if you need one.
    </p>
  );
}
