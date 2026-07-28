'use client';

import { Gauge } from 'lucide-react';
import { useSubscriptionIntelligence } from '@/hooks/useBilling';
import UsageMeter from './UsageMeter';
import StorageMeterCard from './StorageMeterCard';
import TrialCardComponent from './TrialCard';
import HealthOverview from './HealthOverview';
import RecommendationsList from './RecommendationsList';
import SubscriptionTimeline from './SubscriptionTimeline';
import StripeInfoCard from './StripeInfoCard';

function Skeleton() {
  return (
    <div className="space-y-4" aria-hidden>
      <div className="h-40 rounded-2xl animate-pulse motion-reduce:animate-none" style={{ backgroundColor: 'var(--bg-elevated)' }} />
      <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
        {[...Array(3)].map((_, i) => (
          <div key={i} className="h-32 rounded-2xl animate-pulse motion-reduce:animate-none" style={{ backgroundColor: 'var(--bg-elevated)' }} />
        ))}
      </div>
    </div>
  );
}

/**
 * Phase G3 — the Subscription Intelligence Centre. Composes every section
 * (Stages 2-10) from a single `GET /billing/intelligence` fetch. Every
 * usage card is generated dynamically from whatever the backend's `usage`
 * array contains — this component never hardcodes a feature key or card
 * list; adding a future `EntitlementCategory::USAGE` Feature key needs no
 * change here.
 */
export default function SubscriptionIntelligenceSection({ timeZone }: { timeZone?: string }) {
  const { data, isLoading, isError } = useSubscriptionIntelligence();

  if (isLoading) return <Skeleton />;
  if (isError || !data) return null; // Billing page's own EmptyState already covers the hard-error case for the page as a whole.

  const intelligence = data.data;
  const otherUsage = intelligence.usage.filter(
    m => m.feature_key !== 'storage_gb' && m.feature_key !== 'ai_analyses_per_month',
  );

  return (
    <section aria-labelledby="subscription-intelligence-heading" className="space-y-4">
      <div className="flex items-center gap-2">
        <Gauge size={16} aria-hidden style={{ color: 'var(--text-secondary)' }} />
        <h2 id="subscription-intelligence-heading" className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>
          Usage &amp; health
        </h2>
      </div>

      {intelligence.trial && <TrialCardComponent trial={intelligence.trial} timeZone={timeZone} />}

      {/* Phase G4C.3F — the AI analysis-count meter (AiUsageMeterCard) is
          deliberately NOT rendered here. It remains an internal/admin
          diagnostic surface (see OrganizationSubscriptionSection, which
          renders it independently with its own fetched data) — customers
          never see raw analysis counts on their own Billing page. The
          customer-facing AI consumption meter (AiCreditUsageMeterCard)
          now lives on its own dedicated Usage page
          (/app/settings/usage), not here — Billing stays scoped to
          plan/subscription/renewal/invoices/payment only. */}
      {(intelligence.storage || otherUsage.length > 0) && (
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
          {intelligence.storage && <StorageMeterCard storage={intelligence.storage} />}
          {otherUsage.map(metric => <UsageMeter key={metric.feature_key} metric={metric} />)}
        </div>
      )}

      <RecommendationsList recommendations={intelligence.recommendations} />

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <HealthOverview health={intelligence.health} />
        <StripeInfoCard stripe={intelligence.stripe} timeZone={timeZone} />
      </div>

      <SubscriptionTimeline timeline={intelligence.timeline} timeZone={timeZone} />
    </section>
  );
}
