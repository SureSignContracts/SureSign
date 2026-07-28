'use client';

import { useEffect, useRef, useState } from 'react';
import Link from 'next/link';
import {
  Zap, Shield, Star, Rocket, Building2, Users, Layers, CheckCircle2, Crown,
  Sparkles, Briefcase, Award, Check, ArrowUpRight, type LucideIcon,
} from 'lucide-react';
import { Container } from '@/components/shared/Container';
import { getGsap } from '@/lib/gsap';
import { useReducedMotion } from '@/lib/useReducedMotion';
import type { PricingFeatureSection, PricingPlan, PricingSettings } from '@/lib/pricing';

const ICONS: Record<string, LucideIcon> = {
  zap: Zap, shield: Shield, star: Star, rocket: Rocket, building: Building2,
  users: Users, layers: Layers, 'check-circle': CheckCircle2, crown: Crown,
  sparkles: Sparkles, briefcase: Briefcase, award: Award,
};

const BADGE_COLOR_STYLE: Record<string, string> = {
  gold: 'var(--accent)', accent: 'var(--accent)', success: '#16a34a',
  neutral: 'var(--text-muted)', danger: '#dc2626',
};

type BillingCycle = 'monthly' | 'annual';

function formatPrice(plan: PricingPlan, cycle: BillingCycle): { amount: string | null; suffix: string | null } {
  const raw = cycle === 'monthly' ? plan.monthly_price : plan.annual_price;
  if (!raw) return { amount: null, suffix: plan.price_suffix };

  const value = Number(raw);
  const formatted = new Intl.NumberFormat('en-GB', { style: 'currency', currency: plan.currency, maximumFractionDigits: 0 }).format(value);
  return { amount: formatted, suffix: plan.price_suffix };
}

export function PricingCards({
  plans,
  settings,
  featureSections,
}: {
  plans: PricingPlan[];
  settings: PricingSettings;
  featureSections: PricingFeatureSection[];
}) {
  const ref = useRef<HTMLDivElement>(null);
  const reduced = useReducedMotion();
  const showToggle = settings.monthly_billing_enabled && settings.annual_billing_enabled;
  const initialCycle: BillingCycle = settings.monthly_billing_enabled ? 'monthly' : 'annual';
  const [cycle, setCycle] = useState<BillingCycle>(initialCycle);

  const featureNames: Record<string, string> = {};
  featureSections.forEach(section => section.features.forEach(f => { featureNames[f.id] = f.name; }));

  useEffect(() => {
    if (reduced || !ref.current) return;
    const { gsap } = getGsap();
    const ctx = gsap.context(() => {
      const cards = gsap.utils.toArray<HTMLElement>('[data-plan-card]');
      gsap.set(cards, { opacity: 0, y: 20 });
      gsap.to(cards, { opacity: 1, y: 0, duration: 0.7, ease: 'power2.out', stagger: 0.08, delay: 0.1 });
    }, ref);

    return () => ctx.revert();
  }, [reduced]);

  return (
    <section id="plans" className="border-b border-border pb-20 pt-8 md:pb-28 md:pt-10">
      <Container>
        {settings.section_title && (
          <h2 className="text-center text-2xl font-medium tracking-tight text-text-primary md:text-3xl">
            {settings.section_title}
          </h2>
        )}

        {showToggle && (
          <div
            className="mx-auto mt-7 grid w-full max-w-md grid-cols-2 rounded-full border border-border bg-bg-surface p-1 sm:w-fit"
            role="radiogroup"
            aria-label="Billing interval"
          >
            <button
              type="button"
              role="radio"
              aria-checked={cycle === 'monthly'}
              onClick={() => setCycle('monthly')}
              className="min-w-0 rounded-full px-4 py-2.5 text-sm font-medium transition-[background-color,color,box-shadow] duration-200 sm:min-w-24 sm:px-5"
              style={{
                backgroundColor: cycle === 'monthly' ? 'var(--accent)' : 'transparent',
                color: cycle === 'monthly' ? 'var(--accent-fg)' : 'var(--text-secondary)',
                boxShadow: cycle === 'monthly' ? 'var(--shadow-card)' : 'none',
              }}
            >
              Monthly
            </button>
            <button
              type="button"
              role="radio"
              aria-checked={cycle === 'annual'}
              onClick={() => setCycle('annual')}
              className="flex min-w-0 items-center justify-center gap-2 rounded-full px-4 py-2.5 text-sm font-medium transition-[background-color,color,box-shadow] duration-200 sm:min-w-24 sm:px-5"
              style={{
                backgroundColor: cycle === 'annual' ? 'var(--accent)' : 'transparent',
                color: cycle === 'annual' ? 'var(--accent-fg)' : 'var(--text-secondary)',
                boxShadow: cycle === 'annual' ? 'var(--shadow-card)' : 'none',
              }}
            >
              Annual
              {settings.discount_label && (
                <span
                  className="hidden whitespace-nowrap rounded-full px-2 py-0.5 text-[11px] sm:inline"
                  style={{
                    backgroundColor: cycle === 'annual' ? 'var(--accent-fg)' : 'var(--bg-elevated)',
                    color: cycle === 'annual' ? 'var(--accent)' : 'var(--text-muted)',
                  }}
                >
                  {settings.discount_label}
                </span>
              )}
            </button>
          </div>
        )}

        <div ref={ref} className="mt-10 grid grid-cols-1 items-stretch gap-5 lg:grid-cols-[repeat(auto-fit,minmax(16rem,1fr))]">
          {plans.map((plan) => {
            const Icon = plan.icon ? ICONS[plan.icon] : null;
            const { amount, suffix } = formatPrice(plan, cycle);
            const isElevated = plan.is_popular || plan.background_style === 'elevated';

            return (
              <div
                key={plan.slug}
                data-plan-card
                className="group relative flex min-h-full flex-col overflow-hidden rounded-2xl border p-7 transition-[transform,border-color,box-shadow] duration-300 motion-safe:hover:-translate-y-1 md:p-8"
                style={{
                  borderColor: plan.is_popular ? 'var(--text-primary)' : 'var(--border)',
                  backgroundColor: plan.is_popular || plan.background_style === 'surface' ? 'var(--bg-surface)' : 'var(--bg-base)',
                  boxShadow: isElevated ? 'var(--shadow-pop)' : 'var(--shadow-card)',
                }}
              >
                {plan.badge_text && (
                  <div
                    className="absolute right-0 top-0 rounded-bl-xl border-b border-l border-border px-4 py-2 text-[11px] font-medium"
                    style={{
                      backgroundColor: BADGE_COLOR_STYLE[plan.badge_color || 'accent'] || 'var(--accent)',
                      color: 'var(--accent-fg)',
                    }}
                  >
                    {plan.badge_text}
                  </div>
                )}

                <div className={`flex items-center gap-2 ${plan.badge_text ? 'pr-24' : ''}`}>
                  {Icon && <Icon size={18} className="text-text-secondary" />}
                  <h3 className="text-lg font-medium tracking-tight text-text-primary">{plan.name}</h3>
                </div>

                <div className="min-h-[4.5rem]">
                  {plan.summary && <p className="mt-3 max-w-[34ch] text-sm leading-6 text-text-secondary">{plan.summary}</p>}
                </div>

                <div className="mt-5 min-h-[4.75rem]" aria-live="polite" aria-atomic="true">
                  {amount ? (
                    <div>
                      <div className="flex flex-wrap items-baseline gap-x-1.5 gap-y-1">
                      {plan.price_prefix && <span className="text-sm text-text-muted">{plan.price_prefix}</span>}
                        <span className="text-4xl font-medium tracking-tighter text-text-primary tabular-nums md:text-5xl">{amount}</span>
                      {suffix && <span className="text-sm text-text-muted">{suffix}</span>}
                      </div>
                      <span className="mt-1 block text-xs text-text-muted">
                        {cycle === 'monthly' ? 'Monthly billing' : 'Annual billing'}
                      </span>
                    </div>
                  ) : (
                    <div className="pt-1 text-3xl font-medium tracking-tight text-text-primary">
                      {plan.custom_label || plan.price_prefix || 'Custom'}
                    </div>
                  )}
                </div>

                <div className="min-h-[5.25rem]">
                  {plan.description && <p className="mt-4 max-w-[36ch] text-sm leading-6 text-text-secondary">{plan.description}</p>}
                </div>

                {plan.cta_text && (
                  <Link
                    href={plan.cta_url || '/book/demo?src=pricing'}
                    target={plan.cta_new_tab ? '_blank' : undefined}
                    rel={plan.cta_new_tab ? 'noopener' : undefined}
                    className="mt-7 inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-full px-6 py-3 text-sm font-medium transition-[transform,background-color,color,box-shadow] duration-200 active:translate-y-px"
                    style={plan.is_popular
                      ? { backgroundColor: 'var(--accent)', color: 'var(--accent-fg)', boxShadow: 'var(--shadow-card)' }
                      : { border: '1px solid var(--border)', color: 'var(--text-primary)' }}
                  >
                    {plan.cta_text}
                    <ArrowUpRight
                      size={15}
                      strokeWidth={1.7}
                      aria-hidden="true"
                      className="transition-transform duration-200 group-hover:-translate-y-0.5 group-hover:translate-x-0.5"
                    />
                  </Link>
                )}

                {Object.keys(plan.features).length > 0 && (
                  <div className="mt-8 border-t border-border pt-6">
                    <p className="text-xs font-medium text-text-muted">Key inclusions</p>
                    <ul className="mt-4 space-y-3">
                    {Object.entries(plan.features)
                      .filter(([featureId, f]) => (f.status === 'included' || f.status === 'custom' || f.status === 'limited') && featureNames[featureId])
                      .slice(0, 5)
                      .map(([featureId, f]) => (
                        <li key={featureId} className="flex items-start gap-2 text-sm text-text-secondary">
                          <Check size={15} strokeWidth={1.8} className="mt-0.5 shrink-0 text-text-primary" aria-hidden="true" />
                          <span>
                            {featureNames[featureId]}
                            {f.value_text && <span className="text-text-muted">: {f.value_text}</span>}
                          </span>
                        </li>
                      ))}
                    </ul>
                  </div>
                )}
              </div>
            );
          })}
        </div>
      </Container>
    </section>
  );
}
