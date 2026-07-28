import { CheckCircle2, AlertTriangle, Info, XCircle, Sparkles } from 'lucide-react';
import type { AccessDecision } from '@/hooks/useBilling';
import { formatDateTime } from '@/lib/dateTime';

const MODE_ICON: Record<AccessDecision['mode'], React.ElementType> = {
  full: CheckCircle2,
  trial: Sparkles,
  grace: AlertTriangle,
  restricted: XCircle,
  none: Info,
};

const MODE_STYLE: Record<AccessDecision['mode'], React.CSSProperties> = {
  full: { backgroundColor: 'rgba(34,197,94,0.08)', borderColor: 'rgba(34,197,94,0.25)' },
  trial: { backgroundColor: 'rgba(59,130,246,0.08)', borderColor: 'rgba(59,130,246,0.25)' },
  grace: { backgroundColor: 'rgba(234,179,8,0.08)', borderColor: 'rgba(234,179,8,0.25)' },
  restricted: { backgroundColor: 'rgba(239,68,68,0.08)', borderColor: 'rgba(239,68,68,0.25)' },
  none: { backgroundColor: 'var(--bg-elevated)', borderColor: 'var(--border)' },
};

const MODE_ICON_COLOR: Record<AccessDecision['mode'], string> = {
  full: '#4ade80',
  trial: '#60a5fa',
  grace: '#facc15',
  restricted: '#f87171',
  none: 'var(--text-muted)',
};

/**
 * Renders the backend's own SubscriptionAccessPolicy reason prose directly
 * — that copy is already customer-safe and provider-independent; this
 * component never re-authors it, only supplies the icon/tone wrapper and
 * any extra dates the overview payload carries alongside it.
 */
export default function AccessStatusBanner({
  access,
  graceEndsAt,
  timeZone,
}: {
  access: AccessDecision;
  graceEndsAt?: string | null;
  timeZone?: string;
}) {
  const Icon = MODE_ICON[access.mode];

  return (
    <div
      className="rounded-2xl p-4 flex items-start gap-3 ss-animate-in"
      style={{ border: '1px solid', ...MODE_STYLE[access.mode] }}
    >
      <Icon size={18} className="flex-shrink-0 mt-0.5" style={{ color: MODE_ICON_COLOR[access.mode] }} />
      <div className="space-y-1">
        <p className="text-sm" style={{ color: 'var(--text-primary)' }}>{access.reason}</p>
        {access.mode === 'grace' && graceEndsAt && (
          <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
            Grace period ends {formatDateTime(graceEndsAt, { timeZone })}.
          </p>
        )}
      </div>
    </div>
  );
}
