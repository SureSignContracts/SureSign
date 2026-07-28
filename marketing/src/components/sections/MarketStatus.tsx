import Link from 'next/link';
import { Container } from '@/components/shared/Container';

export function MarketStatus() {
  return (
    <section aria-labelledby="market-status-title" className="border-y border-border bg-bg-surface">
      <Container className="grid gap-8 py-9 md:grid-cols-[1fr_auto] md:items-center md:py-11">
        <div>
          <p className="text-xs font-medium uppercase tracking-[0.14em] text-text-muted">
            Current market status
          </p>
          <h2 id="market-status-title" className="mt-2 text-xl font-medium tracking-tight text-text-primary md:text-2xl">
            Built around real construction administration workflows and now onboarding founding customers.
          </h2>
          <p className="mt-3 max-w-[64ch] text-sm leading-6 text-text-secondary">
            SureSign is a working platform for contractors administering live projects.
            We will publish customer evidence here only when it is approved and independently attributable.
          </p>
        </div>
        <Link
          href="/contact?subject=founding-customer"
          className="inline-flex min-h-11 w-full items-center justify-center rounded-full border border-border px-5 text-sm font-medium text-text-primary transition-colors hover:border-border-light sm:w-auto"
        >
          Discuss early access
        </Link>
      </Container>
    </section>
  );
}
