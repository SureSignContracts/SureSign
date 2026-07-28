'use client';

import Link from 'next/link';
import { ArrowLeft, Gauge } from 'lucide-react';
import AiCreditUsageMeterCard from '@/components/billing/intelligence/AiCreditUsageMeterCard';

/**
 * Phase G4C.3F — the dedicated customer Usage page, separate from Billing.
 * Billing stays scoped to plan/subscription/renewal/invoices/payment;
 * Usage is scoped to Monthly AI Usage and any future metered product
 * usage. Today this page has exactly one section — AiCreditUsageMeterCard
 * itself renders nothing when the API returns `available: false` (no
 * subscription, no configured allowance, or `customer_meter_enabled` is
 * off) — this page never falls back to a placeholder or to the internal
 * analysis-count meter in that case; it just shows nothing below the
 * header, matching AiCreditUsageMeterCard's own hidden-by-default
 * contract exactly.
 */
export default function UsagePage() {
  return (
    <div className="max-w-3xl mx-auto px-6 py-8">
      <div className="mb-6">
        <Link
          href="/app/settings"
          className="inline-flex items-center gap-1.5 text-sm mb-4 hover:opacity-70 transition-opacity"
          style={{ color: 'var(--text-muted)' }}
        >
          <ArrowLeft size={14} />
          Back to Settings
        </Link>
        <h1 className="text-2xl font-semibold flex items-center gap-2" style={{ color: 'var(--text-primary)' }}>
          <Gauge size={20} style={{ color: 'var(--text-secondary)' }} />
          Usage
        </h1>
        <p className="mt-1 text-sm" style={{ color: 'var(--text-muted)' }}>
          Your monthly AI usage and renewal date.
        </p>
      </div>

      <div className="space-y-4">
        <AiCreditUsageMeterCard />
      </div>
    </div>
  );
}
