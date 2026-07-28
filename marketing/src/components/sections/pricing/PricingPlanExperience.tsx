'use client';

import { useEffect, useRef } from 'react';
import Link from 'next/link';
import { ArrowRight, Check } from 'lucide-react';
import { Container } from '@/components/shared/Container';
import { getGsap } from '@/lib/gsap';
import { useReducedMotion } from '@/lib/useReducedMotion';
import type { PricingFeatureSection, PricingPlan, PricingSettings } from '@/lib/pricing';

function formatPrice(plan: PricingPlan): string | null {
  if (!plan.monthly_price) return null;

  return new Intl.NumberFormat('en-GB', {
    style: 'currency',
    currency: plan.currency,
    maximumFractionDigits: 0,
  }).format(Number(plan.monthly_price));
}

export function PricingPlanExperience({
  plan,
  sections,
  settings,
}: {
  plan: PricingPlan;
  sections: PricingFeatureSection[];
  settings: PricingSettings;
}) {
  const rootRef = useRef<HTMLDivElement>(null);
  const reduced = useReducedMotion();
  const monthlyPrice = formatPrice(plan);
  const includedSections = sections
    .map((section) => ({
      ...section,
      features: section.features.filter((feature) => {
        const status = plan.features[feature.id]?.status;
        return status && status !== 'not_included';
      }),
    }))
    .filter((section) => section.features.length > 0);

  useEffect(() => {
    if (reduced || !rootRef.current) return;
    const { gsap } = getGsap();
    const ctx = gsap.context(() => {
      gsap.fromTo(
        '[data-plan-hero]',
        { autoAlpha: 0, y: 18 },
        { autoAlpha: 1, y: 0, duration: 0.65, stagger: 0.07, ease: 'power2.out' },
      );

      const sections = gsap.utils.toArray<HTMLElement>('[data-plan-section]');
      sections.forEach((section) => {
        const items = section.querySelectorAll('[data-plan-reveal]');
        gsap.fromTo(
          items,
          { autoAlpha: 0, y: 16 },
          {
            autoAlpha: 1,
            y: 0,
            duration: 0.6,
            stagger: 0.065,
            ease: 'power2.out',
            scrollTrigger: {
              trigger: section,
              start: 'top 78%',
              once: true,
            },
          },
        );
      });
    }, rootRef);

    return () => ctx.revert();
  }, [reduced]);

  return (
    <div ref={rootRef}>
      <section className="bg-atmosphere border-b border-border">
        <Container className="grid gap-10 py-20 md:py-24 lg:grid-cols-[1.1fr_0.9fr] lg:items-end">
          <div data-plan-hero>
            <Link href="/pricing" className="text-sm font-medium text-text-muted transition-colors hover:text-text-primary">
              Pricing overview
            </Link>
            <h1 className="mt-5 max-w-[14ch] text-5xl font-medium leading-[0.98] tracking-tighter text-text-primary md:text-7xl">
              {plan.name}
            </h1>
            {(plan.description || plan.summary) && (
              <p className="mt-6 max-w-[50ch] text-base leading-7 text-text-secondary md:text-lg">
                {plan.description || plan.summary}
              </p>
            )}
          </div>

          <aside data-plan-hero className="rounded-2xl border border-border bg-bg-base p-6 shadow-[var(--shadow-card)] md:p-8">
            <p className="text-sm text-text-muted">{plan.summary || 'SureSign plan'}</p>
            <div className="mt-5">
              {monthlyPrice ? (
                <div className="flex flex-wrap items-baseline gap-2">
                  {plan.price_prefix && <span className="text-sm text-text-muted">{plan.price_prefix}</span>}
                  <span className="text-4xl font-medium tracking-tighter text-text-primary tabular-nums">{monthlyPrice}</span>
                  {plan.price_suffix && <span className="text-sm text-text-muted">{plan.price_suffix}</span>}
                </div>
              ) : (
                <p className="text-3xl font-medium tracking-tight text-text-primary">
                  {plan.custom_label || plan.price_prefix || 'Custom pricing'}
                </p>
              )}
            </div>
            {plan.cta_text && (
              <Link
                href={plan.cta_url || '/book/demo?src=pricing-plan'}
                target={plan.cta_new_tab ? '_blank' : undefined}
                rel={plan.cta_new_tab ? 'noopener' : undefined}
                className="mt-7 inline-flex w-full items-center justify-center gap-2 whitespace-nowrap rounded-full bg-accent px-6 py-3 text-sm font-medium text-accent-fg transition-transform duration-200 active:translate-y-px"
              >
                {plan.cta_text}
                <ArrowRight size={15} strokeWidth={1.7} aria-hidden="true" />
              </Link>
            )}
          </aside>
        </Container>
      </section>

      <section data-plan-section className="border-b border-border py-20 md:py-28">
        <Container>
          <div data-plan-reveal className="max-w-[58ch]">
            <h2 className="text-3xl font-medium tracking-tight text-text-primary md:text-4xl">
              What&apos;s included
            </h2>
            <p className="mt-4 text-base leading-7 text-text-secondary">
              Features shown here come directly from the current pricing configuration.
            </p>
          </div>

          <div className="mt-12 grid gap-5 md:grid-cols-2">
            {includedSections.map((section) => (
              <article key={section.name} data-plan-reveal className="rounded-2xl border border-border bg-bg-surface p-6 md:p-8">
                <h3 className="text-lg font-medium tracking-tight text-text-primary">{section.name}</h3>
                <ul className="mt-6 grid gap-4">
                  {section.features.map((feature) => {
                    const cell = plan.features[feature.id];
                    return (
                      <li key={feature.id} className="flex items-start gap-3 text-sm leading-6 text-text-secondary">
                        <Check size={15} strokeWidth={1.8} className="mt-1 shrink-0 text-text-primary" aria-hidden="true" />
                        <span>
                          {feature.name}
                          {cell?.value_text && <span className="text-text-muted">: {cell.value_text}</span>}
                        </span>
                      </li>
                    );
                  })}
                </ul>
              </article>
            ))}
          </div>
        </Container>
      </section>

      <section data-plan-section className="tone-surface border-b border-border py-20 md:py-28">
        <Container className="grid gap-12 lg:grid-cols-[0.72fr_1.28fr] lg:items-start lg:gap-20">
          <div data-plan-reveal className="lg:sticky lg:top-28">
            <h2 className="text-3xl font-medium tracking-tight text-text-primary md:text-4xl">
              See the workflow in context
            </h2>
            <p className="mt-5 max-w-[38ch] text-base leading-7 text-text-secondary">
              Review the configured capability groups that shape this plan.
            </p>
            <Link
              href="/pricing/compare"
              className="mt-7 inline-flex items-center gap-2 text-sm font-medium text-text-primary"
            >
              Compare all plans
              <ArrowRight size={15} strokeWidth={1.7} aria-hidden="true" />
            </Link>
          </div>
          <ol data-plan-reveal className="border-t border-border">
            {includedSections.slice(0, 4).map((section, index) => (
              <li key={section.name} className="grid gap-3 border-b border-border py-6 sm:grid-cols-[2.5rem_0.75fr_1.25fr] sm:gap-5">
                <span className="font-mono text-xs text-text-muted">{String(index + 1).padStart(2, '0')}</span>
                <h3 className="font-medium text-text-primary">{section.name}</h3>
                <p className="text-sm leading-6 text-text-secondary">
                  {section.features.slice(0, 3).map((feature) => feature.name).join(', ')}
                </p>
              </li>
            ))}
          </ol>
        </Container>
      </section>

      <section data-plan-section className="border-b border-border py-20 md:py-28">
        <Container>
          <div data-plan-reveal className="mx-auto max-w-[44rem] rounded-2xl border border-border bg-bg-base p-7 text-center shadow-[var(--shadow-card)] md:p-10">
            <h2 className="text-3xl font-medium tracking-tight text-text-primary">
              Review {plan.name} with our team
            </h2>
            <p className="mx-auto mt-4 max-w-[48ch] text-sm leading-6 text-text-secondary">
              We can walk through the configured features and discuss how the plan fits your contract administration process.
            </p>
            {settings.primary_cta_text && (
              <Link
                href={settings.primary_cta_url || '/book/demo?src=pricing-plan'}
                target={settings.primary_cta_new_tab ? '_blank' : undefined}
                rel={settings.primary_cta_new_tab ? 'noopener' : undefined}
                className="mt-7 inline-flex whitespace-nowrap rounded-full bg-accent px-7 py-3.5 text-sm font-medium text-accent-fg"
              >
                {settings.primary_cta_text}
              </Link>
            )}
          </div>
        </Container>
      </section>
    </div>
  );
}
