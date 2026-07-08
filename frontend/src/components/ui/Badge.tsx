import { cn } from '@/lib/utils';

type Tone = 'neutral' | 'success' | 'warning' | 'info' | 'danger' | 'accent';

const TONE_STYLE: Record<Tone, React.CSSProperties> = {
  neutral: { backgroundColor: 'rgba(148,163,184,0.12)', color: '#94a3b8' },
  success: { backgroundColor: 'rgba(34,197,94,0.12)', color: '#4ade80' },
  warning: { backgroundColor: 'rgba(234,179,8,0.12)', color: '#facc15' },
  info: { backgroundColor: 'rgba(59,130,246,0.12)', color: '#60a5fa' },
  danger: { backgroundColor: 'rgba(239,68,68,0.12)', color: '#f87171' },
  accent: { backgroundColor: 'var(--gold-15)', color: 'var(--gold)' },
};

// Maps common backend status strings to a tone. Extend as new statuses appear.
const STATUS_TONE: Record<string, Tone> = {
  active: 'success', completed: 'success', approved: 'success', paid: 'success', certified: 'success',
  open: 'warning', pending: 'warning', draft: 'neutral',
  submitted: 'info', processing: 'info',
  rejected: 'danger', failed: 'danger', overdue: 'danger',
};

export function Badge({
  children,
  tone,
  status,
  className,
}: {
  children?: React.ReactNode;
  tone?: Tone;
  /** Alternative to `tone` — derives tone from a backend status string. */
  status?: string;
  className?: string;
}) {
  const resolved = tone ?? (status ? STATUS_TONE[status.toLowerCase()] ?? 'neutral' : 'neutral');
  return (
    <span
      className={cn('inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium capitalize', className)}
      style={TONE_STYLE[resolved]}
    >
      {children ?? status?.replace(/_/g, ' ')}
    </span>
  );
}
