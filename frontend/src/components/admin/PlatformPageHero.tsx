'use client';

import type { ElementType, ReactNode } from 'react';
import CountUp from '@/components/ui/CountUp';

interface PlatformMetric {
  label: string;
  value: number | string;
  detail: string;
  icon: ElementType;
}

export default function PlatformPageHero({
  eyebrow,
  title,
  description,
  action,
  metrics,
  loading = false,
}: {
  eyebrow: string;
  title: string;
  description: string;
  action?: ReactNode;
  metrics: PlatformMetric[];
  loading?: boolean;
}) {
  return (
    <section className="ss-animate-in relative overflow-hidden rounded-2xl bg-[#18211d] text-white shadow-[0_24px_60px_rgba(24,33,29,0.16)]">
      <div className="pointer-events-none absolute -right-16 -top-20 h-64 w-64 rounded-full bg-[#9ee5b5]/10 blur-3xl" />
      <div className="relative flex flex-wrap items-start justify-between gap-6 px-6 pb-7 pt-6 sm:px-8 sm:pt-8">
        <div>
          <p className="mb-4 text-xs font-semibold uppercase tracking-[0.14em] text-[#9ee5b5]">{eyebrow}</p>
          <h1 className="text-3xl font-semibold tracking-[-0.04em] sm:text-4xl">{title}</h1>
          <p className="mt-3 max-w-2xl text-sm text-white/55">{description}</p>
        </div>
        {action}
      </div>
      <div className={`relative grid border-t border-white/10 ${metrics.length === 4 ? 'grid-cols-2 lg:grid-cols-4' : 'grid-cols-1 sm:grid-cols-3'}`}>
        {metrics.map((metric, index) => (
          <div key={metric.label} className="ss-animate-in min-h-[112px] border-b border-r border-white/10 px-6 py-4 last:border-r-0 sm:border-b-0" style={{ animationDelay: `${index * 70}ms` }}>
            <metric.icon size={15} className="text-white/30" />
            <p className="mt-3 text-2xl font-semibold tabular-nums tracking-[-0.04em] text-[#9ee5b5]">
              {loading ? '–' : typeof metric.value === 'number' ? <CountUp value={metric.value} delay={index * 70} /> : metric.value}
            </p>
            <p className="mt-1 text-xs font-medium text-white/70">{metric.label}</p>
            <p className="mt-0.5 text-[11px] text-white/35">{metric.detail}</p>
          </div>
        ))}
      </div>
    </section>
  );
}
