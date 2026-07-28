import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { Sparkles } from 'lucide-react';
import UsageMeter from './UsageMeter';
import { formatDateOnly } from '@/lib/dateTime';
import { AiUsageMetric } from '@/types/subscriptionIntelligence';

/**
 * Stage 5 — resets on the UTC calendar month boundary (Entitlement
 * Specification v1 Section 12's decided definition), independent of the
 * subscription's own Stripe billing anniversary.
 */
export default function AiUsageMeterCard({ ai }: { ai: AiUsageMetric }) {
  return (
    <Card>
      <CardHeader>
        <div className="flex items-center gap-2">
          <Sparkles size={16} aria-hidden style={{ color: 'var(--text-secondary)' }} />
          <CardTitle>AI analyses</CardTitle>
        </div>
      </CardHeader>
      <CardBody>
        <UsageMeter metric={ai} />
        <p className="text-[11px] mt-3" style={{ color: 'var(--text-muted)' }}>
          Resets {formatDateOnly(ai.next_reset_at.slice(0, 10))}.
        </p>
      </CardBody>
    </Card>
  );
}
