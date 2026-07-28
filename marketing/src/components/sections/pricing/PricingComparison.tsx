'use client';

import { Fragment, useEffect, useRef } from 'react';
import { Check, ChevronDown, Minus } from 'lucide-react';
import { Container } from '@/components/shared/Container';
import { getGsap } from '@/lib/gsap';
import { useReducedMotion } from '@/lib/useReducedMotion';
import type { PricingFeatureSection, PricingPlan } from '@/lib/pricing';

function Cell({ plan, featureId }: { plan: PricingPlan; featureId: number }) {
  const cell = plan.features[featureId];
  if (!cell || cell.status === 'not_included') {
    return (
      <span className="inline-flex items-center justify-center text-text-muted" title="Not included">
        <Minus size={15} aria-hidden="true" />
        <span className="sr-only">Not included</span>
      </span>
    );
  }
  if (cell.status === 'included') {
    return (
      <span className="inline-flex items-center justify-center text-text-primary" title="Included">
        <Check size={17} strokeWidth={1.8} aria-hidden="true" />
        <span className="sr-only">Included</span>
      </span>
    );
  }
  if (cell.status === 'limited') {
    return <span className="text-xs font-medium text-text-secondary">Limited</span>;
  }

  return (
    <span className="text-xs font-medium text-text-secondary">
      <span className="sr-only">Custom: </span>
      {cell.value_text || 'Custom'}
    </span>
  );
}

export function PricingComparison({ plans, sections }: { plans: PricingPlan[]; sections: PricingFeatureSection[] }) {
  const ref = useRef<HTMLDivElement>(null);
  const reduced = useReducedMotion();

  useEffect(() => {
    if (reduced || !ref.current) return;
    const { gsap, ScrollTrigger } = getGsap();
    const ctx = gsap.context(() => {
      const rows = gsap.utils.toArray<HTMLElement>('[data-compare-row]');
      rows.forEach((row) => {
        gsap.fromTo(row, { opacity: 0.3 }, {
          opacity: 1, duration: 0.3,
          scrollTrigger: { trigger: row, start: 'top 80%', end: 'top 60%', scrub: 0.3 },
        });
      });
      ScrollTrigger.refresh();
    }, ref);
    return () => ctx.revert();
  }, [reduced]);

  if (sections.length === 0) return null;

  return (
    <section className="tone-surface border-b border-border py-20 md:py-28">
      <Container>
        <div className="max-w-[56ch]">
          <h2 className="text-2xl font-medium tracking-tight text-text-primary md:text-3xl">
            Compare plans
          </h2>
          <p className="mt-3 text-base leading-7 text-text-secondary">
            A clear view of what is included at every level.
          </p>
        </div>

        {/* Desktop and tablet comparison. */}
        <div
          ref={ref}
          className="mt-10 hidden overflow-hidden rounded-2xl border border-border bg-bg-base md:block"
        >
          <table className="w-full table-fixed border-collapse text-sm">
            <thead className="bg-bg-base shadow-[0_1px_0_var(--border)]">
              <tr>
                <th className="w-[40%] border-b border-border px-5 py-5 text-left align-bottom font-normal">
                  <span className="text-xs font-medium text-text-muted">Feature</span>
                </th>
                {plans.map((plan) => (
                  <th
                    key={plan.slug}
                    className={`border-b px-4 py-5 text-center align-bottom ${
                      plan.is_popular ? 'border-b-text-primary bg-bg-base' : 'border-b-border'
                    }`}
                  >
                    {plan.is_popular && plan.badge_text && (
                      <span className="mb-1.5 block text-[10px] font-medium tracking-[0.12em] text-text-muted">
                        {plan.badge_text}
                      </span>
                    )}
                    <span className="block text-sm font-semibold tracking-tight text-text-primary">
                      {plan.name}
                    </span>
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {sections.map((section) => (
                <Fragment key={section.name}>
                  <tr>
                    <th
                      scope="rowgroup"
                      colSpan={plans.length + 1}
                      className="border-b border-border bg-bg-surface px-4 py-3 text-left text-[11px] font-medium tracking-[0.08em] text-text-muted"
                    >
                      {section.name}
                    </th>
                  </tr>
                  {section.features.map((feature) => (
                    <tr
                      key={feature.id}
                      data-compare-row
                      className="group transition-colors duration-200 hover:bg-bg-base"
                    >
                      <th
                        scope="row"
                        className="border-b border-border px-5 py-4 text-left font-normal text-text-secondary transition-colors group-hover:text-text-primary"
                      >
                        {feature.name}
                      </th>
                      {plans.map((plan) => (
                        <td
                          key={plan.slug}
                          className={`border-b border-border px-5 py-4 text-center ${
                            plan.is_popular ? 'bg-bg-base/60' : ''
                          }`}
                        >
                          <Cell plan={plan} featureId={feature.id} />
                        </td>
                      ))}
                    </tr>
                  ))}
                </Fragment>
              ))}
            </tbody>
          </table>
        </div>

        {/* Mobile comparison uses one readable card per plan. */}
        <div className="mt-12 space-y-8 md:hidden">
          {plans.map((plan) => (
            <article
              key={plan.slug}
              className="overflow-hidden rounded-2xl border border-border bg-bg-base"
            >
              <header className="flex items-end justify-between gap-4 border-b border-border p-5">
                <div>
                  <h3 className="text-lg font-semibold tracking-tight text-text-primary">{plan.name}</h3>
                  {plan.summary && <p className="mt-1 max-w-[32ch] text-sm leading-6 text-text-secondary">{plan.summary}</p>}
                </div>
                {plan.is_popular && plan.badge_text && (
                  <span className="shrink-0 pb-1 text-[10px] font-medium tracking-[0.12em] text-text-muted">
                    {plan.badge_text}
                  </span>
                )}
              </header>
              <div>
                {sections.map((section, sectionIndex) => (
                  <details key={section.name} className="group border-b border-border last:border-b-0" open={sectionIndex === 0}>
                    <summary className="flex cursor-pointer list-none items-center justify-between gap-4 bg-bg-surface px-5 py-4 text-sm font-medium text-text-primary marker:content-none">
                      {section.name}
                      <ChevronDown
                        size={16}
                        aria-hidden="true"
                        className="text-text-muted transition-transform duration-200 group-open:rotate-180"
                      />
                    </summary>
                    <ul className="px-5">
                      {section.features.map((feature) => (
                        <li
                          key={feature.id}
                          className="flex min-h-12 items-center justify-between gap-4 border-t border-border py-2.5 text-sm text-text-secondary first:border-t-0"
                        >
                          <span className="leading-5">{feature.name}</span>
                          <Cell plan={plan} featureId={feature.id} />
                        </li>
                      ))}
                    </ul>
                  </details>
                ))}
              </div>
            </article>
          ))}
        </div>
      </Container>
    </section>
  );
}
