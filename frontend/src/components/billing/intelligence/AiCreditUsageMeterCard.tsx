'use client';

import { useQuery } from '@tanstack/react-query';
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
      <div className="rounded-2xl bg-[var(--bg-surface)] p-6 shadow-[0_12px_32px_rgba(24,33,29,0.07)]">
          <div className="h-24 rounded-xl animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)' }} />
      </div>
    );
  }

  if (!data || !data.available || data.usage_percent === null) {
    return null;
  }

  const { usage_percent, resets_at, status } = data;

  return (
    <section className="overflow-hidden rounded-2xl bg-[var(--bg-surface)] shadow-[0_12px_32px_rgba(24,33,29,0.07)]">
      <div className="flex items-center justify-between border-b px-6 py-5" style={{ borderColor: 'var(--border)' }}>
        <div className="flex items-center gap-3">
          <span className="flex h-10 w-10 items-center justify-center rounded-xl bg-[#e6f6eb] text-[#287244]"><Sparkles size={17} aria-hidden /></span>
          <div><p className="text-[10px] font-semibold uppercase tracking-[0.15em]" style={{ color: 'var(--text-secondary)' }}>Meter</p><h2 className="mt-0.5 text-base font-semibold" style={{ color: 'var(--text-primary)' }}>Monthly AI usage</h2></div>
        </div>
        <HealthBadge status={status} />
      </div>
      <div className="p-6 sm:p-8">
        <div className="mb-7 flex items-end gap-2"><CountUp value={usage_percent} className="text-5xl font-semibold tracking-[-0.05em]" style={{ color: 'var(--text-primary)' }} /><span className="pb-1 text-lg font-semibold" style={{ color: 'var(--text-muted)' }}>% used</span></div>
        <div
          role="progressbar"
          aria-label="Monthly AI usage"
          aria-valuenow={usage_percent}
          aria-valuemin={0}
          aria-valuemax={100}
          className="h-3 rounded-full overflow-hidden"
          style={{ backgroundColor: 'var(--bg-elevated)' }}
        >
          <div
            className="h-full rounded-full transition-[width] duration-700 motion-reduce:transition-none"
            style={{ width: `${usage_percent}%`, backgroundColor: BAR_COLOR[status] }}
          />
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
      </div>
    </section>
  );
}
