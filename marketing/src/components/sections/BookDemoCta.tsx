import Link from 'next/link';
import { Container } from '@/components/shared/Container';
import { PaymentAppTable } from '@/components/shared/placeholders';
import { RevealGroup } from '@/components/shared/RevealGroup';

export function BookDemoCta() {
  return (
    <section className="bg-atmosphere relative overflow-hidden pb-0 pt-28 md:pt-40">
      <Container>
        <RevealGroup>
          <div className="grid gap-8 md:grid-cols-[1.15fr_0.85fr] md:items-end md:gap-20">
            <h2 data-reveal-item className="max-w-[14ch] text-4xl font-medium tracking-tighter text-text-primary text-balance md:text-6xl">
              See it on your own contract.
            </h2>
            <div data-reveal-item className="md:pb-2">
              <p className="max-w-[38ch] text-text-secondary">
                Bring a real contract to the call. We&apos;ll show you exactly how SureSign
                handles it, end to end.
              </p>
              <Link
                href="/book/demo?src=home"
                className="mt-8 inline-flex min-h-12 items-center justify-center whitespace-nowrap rounded-full bg-accent px-8 text-sm font-medium text-accent-fg shadow-[var(--shadow-card)] transition-[transform,box-shadow] duration-200 hover:-translate-y-0.5 hover:shadow-[var(--shadow-pop)] active:translate-y-px"
              >
                Book a Demo
              </Link>
            </div>
          </div>

          <div
            data-reveal-item
            aria-hidden
            className="pointer-events-none mx-auto mt-20 max-w-3xl [mask-image:linear-gradient(to_bottom,black,transparent)] md:mt-24"
          >
            <div className="translate-y-8 overflow-hidden rounded-2xl border border-border opacity-75 shadow-[var(--shadow-pop)] [transform:rotateX(3deg)]">
              <PaymentAppTable />
            </div>
          </div>
        </RevealGroup>
      </Container>
    </section>
  );
}
