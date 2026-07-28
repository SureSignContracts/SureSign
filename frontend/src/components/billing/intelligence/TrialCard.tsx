import Link from 'next/link';
import { Hourglass } from 'lucide-react';
import { Card, CardBody } from '@/components/ui/Card';
import Button from '@/components/ui/Button';
import { formatDateTime } from '@/lib/dateTime';
import { TrialCard as TrialCardData } from '@/types/subscriptionIntelligence';

/**
 * Stage 6 — exists if and only if the backend's `trial` field is non-null,
 * which itself is only ever true while SubscriptionAccessPolicy currently
 * resolves the TRIAL mode. Nothing here decides "is this a trial" — that
 * disappears-on-conversion behaviour is entirely the backend's, this
 * component just renders whatever it's given (or nothing, if given null).
 */
export default function TrialCard({ trial, timeZone }: { trial: TrialCardData; timeZone?: string }) {
  const percent = Math.min(100, Math.max(0, trial.percent_elapsed));

  return (
    <Card>
      <CardBody className="space-y-3">
        <div className="flex items-center justify-between gap-3 flex-wrap">
          <div className="flex items-center gap-2">
            <Hourglass size={16} style={{ color: 'var(--gold)' }} aria-hidden />
            <h3 className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>Trial in progress</h3>
          </div>
          <span className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>
            {trial.days_remaining === 0 ? 'Ends today' : `${trial.days_remaining} day${trial.days_remaining === 1 ? '' : 's'} left`}
          </span>
        </div>

        <div className="h-2 rounded-full overflow-hidden" style={{ backgroundColor: 'var(--bg-elevated)' }} role="progressbar" aria-valuenow={percent} aria-valuemin={0} aria-valuemax={100}>
          <div className="h-full rounded-full transition-[width] duration-500 motion-reduce:transition-none" style={{ width: `${percent}%`, backgroundColor: 'var(--gold)' }} />
        </div>

        <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
          Started {formatDateTime(trial.starts_at, { timeZone })} · Ends {formatDateTime(trial.ends_at, { timeZone })}
        </p>

        <Link href="/app/settings/billing#plans">
          <Button size="sm" variant="primary">Choose a plan</Button>
        </Link>
      </CardBody>
    </Card>
  );
}
