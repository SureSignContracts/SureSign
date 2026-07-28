'use client';

import { useQuery } from '@tanstack/react-query';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { Sparkles } from 'lucide-react';
import api from '@/lib/api';
import CountUp from '@/components/ui/CountUp';
import HealthBadge from './HealthBadge';
import { formatDateOnly } from '@/lib/dateTime';
import { EntitlementHealthStatusKey } from '@/types/subscriptionIntelligence';

interface AiCreditUsageResponse {
  available: boolean;
  usage_percent: number | null;
  resets_at: string | null;
  status: EntitlementHealthStatusKey;
  enforcement_enabled: boolean;
}

const BAR_COLOR: Record<EntitlementHealthStatusKey, string> = {
  unknown: 'var(--text-muted)',
  healthy: '#4ade80',
  warning: '#facc15',
  critical: '#fb923c',
  exceeded: '#f87171',
};

/**
 * Phase G4C.3E — the customer-facing "Monthly AI Usage" meter. Deliberately
 * a SEPARATE component from AiUsageMeterCard (which shows analysis count,
 * a different, real, already-approved metric) — never relabelled or
 * merged, per explicit product direction. Renders nothing at all whenever
 * the backend reports `available: false` (no allowance configured, no
 * subscription, or the feature flag is off) — never a placeholder
 * percentage. Only ever renders a 0-100 percentage; the raw allowance and
 * used-credit figures don't exist anywhere in this component or its API
 * response.
 */
export default function AiCreditUsageMeterCard() {
  const { data, isLoading } = useQuery({
    queryKey: ['ai-credit-usage'],
    queryFn: () => api.get<AiCreditUsageResponse>('/billing/ai-credit-usage').then(r => r.data),
  });

  if (isLoading) {
    return (
      <Card>
        <CardBody>
          <div className="h-24 rounded-xl animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)' }} />
        </CardBody>
      </Card>
    );
  }

  if (!data || !data.available || data.usage_percent === null) {
    return null;
  }

  const { usage_percent, resets_at, status } = data;

  return (
    <Card>
      <CardHeader>
        <div className="flex items-center gap-2">
          <Sparkles size={16} aria-hidden style={{ color: 'var(--text-secondary)' }} />
          <CardTitle>Monthly AI Usage</CardTitle>
        </div>
        <HealthBadge status={status} />
      </CardHeader>
      <CardBody>
        <div
          role="progressbar"
          aria-label="Monthly AI usage"
          aria-valuenow={usage_percent}
          aria-valuemin={0}
          aria-valuemax={100}
          className="h-2.5 rounded-full overflow-hidden"
          style={{ backgroundColor: 'var(--bg-elevated)' }}
        >
          <div
            className="h-full rounded-full transition-[width] duration-700 motion-reduce:transition-none"
            style={{ width: `${usage_percent}%`, backgroundColor: BAR_COLOR[status] }}
          />
        </div>

        <div className="flex items-baseline gap-1.5 mt-3">
          <CountUp value={usage_percent} className="text-2xl font-bold" style={{ color: 'var(--text-primary)' }} />
          <span className="text-lg font-bold" style={{ color: 'var(--text-primary)' }}>%</span>
        </div>

        {usage_percent >= 100 ? (
          <p className="text-xs mt-2" style={{ color: 'var(--text-muted)' }}>
            You have used your monthly AI allowance.
            {resets_at && <> Renews {formatDateOnly(resets_at.slice(0, 10))}.</>}
          </p>
        ) : (
          <p className="text-xs mt-2" style={{ color: 'var(--text-muted)' }}>
            Your AI usage automatically renews each billing cycle
            {resets_at && <> — renews {formatDateOnly(resets_at.slice(0, 10))}</>}.
          </p>
        )}
      </CardBody>
    </Card>
  );
}
