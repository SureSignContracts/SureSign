import Link from 'next/link';
import { Container } from '@/components/shared/Container';
import type { PricingPlan } from '@/lib/pricing';

export function HomePricingSummary({ plans }: { plans: PricingPlan[] }) {
  return (
    <section aria-labelledby="home-pricing-title" className="tone-surface border-b border-border py-20 md:py-28">
      <Container>
        <div className="flex flex-col justify-between gap-7 md:flex-row md:items-end">
          <div>
            <p className="text-sm font-medium text-text-muted">Plans</p>
            <h2 id="home-pricing-title" className="mt-3 text-3xl font-medium tracking-tight text-text-primary">
              A plan for the way your team administers contracts.
            </h2>
          </div>
          <Link href="/pricing" className="text-sm font-medium text-text-primary underline decoration-border-light underline-offset-4 hover:decoration-text-primary">
            View current pricing
          </Link>
        </div>
        <div className="mt-10 grid border-y border-border md:grid-cols-3">
          {plans.slice(0, 3).map((plan) => (
            <Link
              key={plan.slug}
              href={`/pricing/${plan.slug}`}
              className="group border-b border-border py-7 transition-colors hover:bg-bg-elevated md:border-b-0 md:border-r md:px-7 md:first:pl-0 md:last:border-r-0"
            >
              <h3 className="text-lg font-medium text-text-primary">{plan.name}</h3>
              <p className="mt-3 max-w-[34ch] text-sm leading-6 text-text-secondary">
                {plan.summary || plan.description || 'View the current plan details and configured inclusions.'}
              </p>
              <span className="mt-5 inline-flex text-sm font-medium text-text-primary">
                View plan <span className="ml-2 transition-transform group-hover:translate-x-1">→</span>
              </span>
            </Link>
          ))}
        </div>
      </Container>
    </section>
  );
}
