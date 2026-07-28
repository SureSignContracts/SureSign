import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { History } from 'lucide-react';
import { formatDateTime } from '@/lib/dateTime';
import { TimelineEntry } from '@/types/subscriptionIntelligence';

/**
 * Stage 9 — reads `App\Models\ActivityLog` rows the existing
 * `SubscriptionLifecycleService` already writes on every real transition;
 * no new event tracking was introduced to produce this list.
 */
export default function SubscriptionTimeline({ timeline, timeZone }: { timeline: TimelineEntry[]; timeZone?: string }) {
  if (timeline.length === 0) return null;

  return (
    <Card>
      <CardHeader>
        <div className="flex items-center gap-2">
          <History size={16} aria-hidden style={{ color: 'var(--text-secondary)' }} />
          <CardTitle>Subscription activity</CardTitle>
        </div>
      </CardHeader>
      <CardBody>
        <ol className="space-y-3">
          {timeline.map((entry, i) => (
            <li key={i} className="flex items-start gap-3">
              <div className="w-1.5 h-1.5 rounded-full mt-1.5 flex-shrink-0" style={{ backgroundColor: 'var(--gold)' }} aria-hidden />
              <div>
                <p className="text-sm" style={{ color: 'var(--text-primary)' }}>{entry.description}</p>
                <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>{formatDateTime(entry.occurred_at, { timeZone })}</p>
              </div>
            </li>
          ))}
        </ol>
      </CardBody>
    </Card>
  );
}
