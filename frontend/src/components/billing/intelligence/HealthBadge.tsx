import { Badge, Tone } from '@/components/ui/Badge';
import { EntitlementHealthStatusKey } from '@/types/subscriptionIntelligence';

const STATUS_TONE: Record<EntitlementHealthStatusKey, Tone> = {
  unknown: 'neutral',
  healthy: 'success',
  warning: 'warning',
  critical: 'danger',
  exceeded: 'danger',
};

const STATUS_LABEL: Record<EntitlementHealthStatusKey, string> = {
  unknown: 'Unknown',
  healthy: 'Healthy',
  warning: 'Approaching limit',
  critical: 'Near limit',
  exceeded: 'Exceeded',
};

export default function HealthBadge({ status }: { status: EntitlementHealthStatusKey }) {
  return <Badge tone={STATUS_TONE[status]}>{STATUS_LABEL[status]}</Badge>;
}
