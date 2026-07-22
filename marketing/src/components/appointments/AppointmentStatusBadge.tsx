import { statusLabel, statusTone } from '@/lib/appointmentFormat';

const TONE_CLASSES: Record<string, string> = {
  info: 'border-border text-text-secondary',
  success: 'border-accent/40 text-text-primary',
  warning: 'border-border-light text-text-primary',
  muted: 'border-border text-text-muted',
  danger: 'border-border-light text-text-primary',
};

export function AppointmentStatusBadge({ status }: { status: string }) {
  return (
    <span
      role="status"
      className={`inline-flex items-center rounded-full border px-3.5 py-1.5 text-xs font-medium ${TONE_CLASSES[statusTone(status)]}`}
    >
      {statusLabel(status)}
    </span>
  );
}
