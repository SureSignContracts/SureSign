import Link from 'next/link';
import { Container } from '@/components/shared/Container';

/**
 * Rendered whenever the public pricing payload is unavailable, unpublished,
 * or has no active plans. The page must never appear broken.
 */
export function PricingFallback() {
  return (
    <section className="bg-atmosphere relative overflow-hidden py-36 md:py-48">
      <Container>
        <div className="mx-auto max-w-[44ch] text-center">
          <div className="mx-auto inline-flex items-center rounded-full border border-border px-4 py-1.5 text-xs font-medium uppercase tracking-wide text-text-muted">
            Pricing
          </div>
          <h1 className="mt-5 text-3xl font-medium tracking-tighter text-text-primary md:text-5xl">
            Pricing is currently available through our sales team.
          </h1>
          <p className="mt-6 text-text-secondary">
            Tell us about your team and we&apos;ll put together a plan that fits.
          </p>
          <div className="mt-10 flex items-center justify-center">
            <Link
              href="/book/demo?src=pricing"
              className="rounded-full bg-accent px-7 py-3.5 text-sm font-medium text-accent-fg shadow-[var(--shadow-card)] transition-all duration-200 hover:shadow-[var(--shadow-pop)] active:translate-y-px"
            >
              Book a Demo
            </Link>
          </div>
        </div>
      </Container>
    </section>
  );
}
