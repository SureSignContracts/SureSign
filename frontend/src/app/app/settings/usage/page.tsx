'use client';

import Link from 'next/link';
import { ArrowLeft, CalendarRange, Gauge, ShieldCheck, Sparkles } from 'lucide-react';
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
    <div className="mx-auto max-w-7xl space-y-6 p-4 sm:p-6 lg:py-9">
      <div>
        <Link
          href="/app/settings"
          className="inline-flex items-center gap-1.5 text-sm mb-4 hover:opacity-70 transition-opacity"
          style={{ color: 'var(--text-muted)' }}
        >
          <ArrowLeft size={14} />
          Back to Settings
        </Link>
        <section className="overflow-hidden rounded-2xl bg-[#18211d] text-white shadow-[0_24px_70px_rgba(24,33,29,0.16)]">
          <div className="p-7 sm:p-10">
            <p className="mb-7 flex items-center gap-2 text-[11px] font-semibold uppercase tracking-[0.18em] text-[#9ee5b5]"><Gauge size={14} /> Metered workspace</p>
            <h1 className="text-3xl font-semibold tracking-[-0.04em] sm:text-4xl">Know what you have used—and what is next.</h1>
            <p className="mt-4 max-w-2xl text-sm leading-6 text-[#b9c5bf] sm:text-base">A clear view of monthly AI usage and the date your allowance renews.</p>
          </div>
          <div className="grid border-t border-white/10 sm:grid-cols-3">
            {[[Sparkles, 'AI allowance', 'Only genuine metered usage'], [CalendarRange, 'Monthly cycle', 'Renewal timing made visible'], [ShieldCheck, 'No estimates', 'Unavailable data stays hidden']].map(([Icon, label, description]) => {
              const ItemIcon = Icon as typeof Gauge;
              return <div key={label as string} className="flex items-start gap-3 px-7 py-5 sm:border-r sm:border-white/10 last:border-r-0"><ItemIcon size={16} className="mt-0.5 text-[#9ee5b5]" /><div><p className="text-sm font-semibold">{label as string}</p><p className="mt-1 text-xs text-[#8f9c96]">{description as string}</p></div></div>;
            })}
          </div>
        </section>
      </div>

      <section className="grid items-start gap-5 lg:grid-cols-[0.65fr_1.35fr]">
        <div className="px-1 py-5">
          <p className="text-[10px] font-semibold uppercase tracking-[0.16em] text-[#43835c]">Current cycle</p>
          <h2 className="mt-3 text-2xl font-semibold tracking-[-0.03em] text-[#18211d]">Your allowance at a glance.</h2>
          <p className="mt-3 max-w-sm text-sm leading-6 text-[#68736d]">Usage is shown only when a real allowance is configured for your organisation.</p>
        </div>
        <div className="space-y-4">
        <AiCreditUsageMeterCard />
        </div>
      </section>
    </div>
  );
}
