import Link from 'next/link';
import { Container } from '@/components/shared/Container';
import { PaymentAppTable } from '@/components/shared/placeholders';

export function BookDemoCta() {
  return (
    <section className="bg-atmosphere relative overflow-hidden py-36 pb-0 md:py-48 md:pb-0">
      <Container>
        <div className="mx-auto max-w-[40ch] text-center">
          <h2 className="text-4xl font-medium tracking-tighter text-text-primary md:text-6xl">
            See it on your own contract.
          </h2>
          <p className="mt-6 text-text-secondary">
            Bring a real contract to the call. We&apos;ll show you exactly how SureSign
            handles it, end to end.
          </p>
          <Link
            href="/book/demo?src=home"
            className="mt-10 inline-block rounded-full bg-accent px-8 py-4 text-sm font-medium text-accent-fg shadow-[var(--shadow-card)] transition-all duration-200 hover:shadow-[var(--shadow-pop)] active:translate-y-px"
          >
            Book a Demo
          </Link>
        </div>

        {/* A glimpse of the platform itself, fading below the fold — the page
            doesn't end so much as continue into the product. */}
        <div
          aria-hidden
          className="pointer-events-none mx-auto mt-24 max-w-2xl [mask-image:linear-gradient(to_bottom,black,transparent)]"
        >
          <div className="translate-y-8 overflow-hidden rounded-2xl border border-border opacity-70 shadow-[var(--shadow-pop)] [transform:rotateX(4deg)]">
            <PaymentAppTable />
          </div>
        </div>
      </Container>
    </section>
  );
}
