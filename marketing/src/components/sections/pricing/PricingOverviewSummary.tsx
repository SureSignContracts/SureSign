import Link from 'next/link';
import { ArrowUpRight } from 'lucide-react';
import { Container } from '@/components/shared/Container';
import type { PricingFeatureSection, PricingPlan } from '@/lib/pricing';

export function PricingOverviewSummary({
  plans,
  sections,
}: {
  plans: PricingPlan[];
  sections: PricingFeatureSection[];
}) {
  return (
    <section className="tone-surface border-b border-border py-20 md:py-28">
      <Container>
        <div className="max-w-[56ch]">
          <h2 className="text-2xl font-medium tracking-tight text-text-primary md:text-3xl">
            Choose how you want to compare
          </h2>
          <p className="mt-4 text-base leading-7 text-text-secondary">
            Review each plan in context, or open the complete feature comparison.
          </p>
        </div>

        <div className="mt-12 grid gap-5 lg:grid-cols-[1.25fr_0.75fr]">
          <div className="rounded-2xl border border-border bg-bg-base p-6 shadow-[var(--shadow-card)] md:p-8">
            <h3 className="text-lg font-medium tracking-tight text-text-primary">Plan guides</h3>
            <div className="mt-6 grid gap-3 sm:grid-cols-2">
              {plans.map((plan) => (
                <Link
                  key={plan.slug}
                  href={`/pricing/${plan.slug}`}
                  className="group flex min-h-28 flex-col justify-between rounded-xl border border-border bg-bg-surface p-4 transition-[border-color,transform] duration-200 hover:-translate-y-0.5 hover:border-border-light"
                >
                  <span className="font-medium text-text-primary">{plan.name}</span>
                  <span className="mt-3 flex items-center justify-between gap-3 text-sm text-text-secondary">
                    {plan.summary || 'View plan details'}
                    <ArrowUpRight
                      size={15}
                      strokeWidth={1.7}
                      aria-hidden="true"
                      className="shrink-0 transition-transform duration-200 group-hover:-translate-y-0.5 group-hover:translate-x-0.5"
                    />
                  </span>
                </Link>
              ))}
            </div>
          </div>

          <Link
            href="/pricing/compare"
            className="group flex min-h-72 flex-col justify-between rounded-2xl border border-text-primary bg-accent p-7 text-accent-fg shadow-[var(--shadow-pop)] transition-transform duration-200 hover:-translate-y-0.5 md:p-8"
          >
            <div>
              <h3 className="text-2xl font-medium tracking-tight">Compare Plans</h3>
              <p className="mt-4 max-w-[34ch] text-sm leading-6 opacity-75">
                Check every configured feature, limit, and plan distinction in one place.
              </p>
            </div>
            <div className="mt-8">
              <p className="text-xs opacity-65">
                {sections.length > 0
                  ? `${sections.length} configured feature ${sections.length === 1 ? 'category' : 'categories'}`
                  : 'Full feature comparison'}
              </p>
              <span className="mt-3 inline-flex items-center gap-2 text-sm font-medium">
                Open comparison
                <ArrowUpRight
                  size={16}
                  strokeWidth={1.7}
                  aria-hidden="true"
                  className="transition-transform duration-200 group-hover:-translate-y-0.5 group-hover:translate-x-0.5"
                />
              </span>
            </div>
          </Link>
        </div>
      </Container>
    </section>
  );
}
