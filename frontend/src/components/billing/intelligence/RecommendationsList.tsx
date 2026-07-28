import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { Badge, Tone } from '@/components/ui/Badge';
import { Lightbulb } from 'lucide-react';
import { SubscriptionRecommendation } from '@/types/subscriptionIntelligence';

const SEVERITY_TONE: Record<SubscriptionRecommendation['severity'], Tone> = {
  high: 'danger',
  medium: 'warning',
  low: 'neutral',
};

/**
 * Stage 12 — every recommendation is generated server-side from real usage
 * (`SubscriptionRecommendationService`), capped at a handful and never
 * generic upsell copy. Renders nothing at all when there are none —
 * deliberately no "everything looks great!" filler card, to avoid the
 * "marketing spam" the brief warns against.
 */
export default function RecommendationsList({ recommendations }: { recommendations: SubscriptionRecommendation[] }) {
  if (recommendations.length === 0) return null;

  return (
    <Card>
      <CardHeader>
        <div className="flex items-center gap-2">
          <Lightbulb size={16} aria-hidden style={{ color: 'var(--gold)' }} />
          <CardTitle>Recommendations</CardTitle>
        </div>
      </CardHeader>
      <CardBody>
        <ul className="space-y-3">
          {recommendations.map(rec => (
            <li key={rec.key} className="flex items-start justify-between gap-3">
              <div>
                <p className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>{rec.title}</p>
                <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>{rec.detail}</p>
              </div>
              <Badge tone={SEVERITY_TONE[rec.severity]}>{rec.severity}</Badge>
            </li>
          ))}
        </ul>
      </CardBody>
    </Card>
  );
}
