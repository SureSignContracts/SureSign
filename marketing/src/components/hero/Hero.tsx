import Link from 'next/link';
import { LayoutGrid, Clock, Network } from 'lucide-react';
import { Container } from '@/components/shared/Container';
import { MockupFrame } from '@/components/shared/MockupFrame';
import { DashboardPreview } from '@/components/shared/placeholders';
import { HeroReveal } from '@/components/hero/HeroReveal';
import { HeroBlueprint } from '@/components/hero/HeroBlueprint';
import { HeroChip } from '@/components/hero/HeroChip';

export function Hero() {
  return (
    <section className="relative flex flex-col items-center overflow-hidden pt-14 pb-8 md:pt-16">
      <HeroBlueprint />
      <div aria-hidden className="absolute inset-0 -z-10 bg-[radial-gradient(ellipse_60%_50%_at_50%_10%,var(--spotlight),transparent_70%)]" />

      {/* Floating background labels — sit on the blueprint canvas itself, not on the mockup. */}
      <div aria-hidden className="absolute left-[6%] top-[22%] hidden font-mono text-xs text-text-muted lg:block">
        <div className="uppercase tracking-wide">Contract Sum</div>
        <div className="mt-1 text-text-secondary">£3,500,000</div>
      </div>
      <div aria-hidden className="absolute right-[6%] top-[22%] hidden text-right font-mono text-xs text-text-muted lg:block">
        <div className="uppercase tracking-wide">Completion Date</div>
        <div className="mt-1 text-text-secondary">26 Jan 2027</div>
      </div>

      <Container>
        <HeroReveal>
          <div className="mx-auto max-w-3xl text-center">
            <div
              data-reveal
              className="mx-auto inline-flex items-center rounded-full border border-border px-4 py-1.5 text-xs font-medium uppercase tracking-wide text-text-muted"
            >
              The connected platform for construction
            </div>
            <h1
              data-reveal
              className="mt-5 text-4xl font-medium leading-[0.98] tracking-tighter text-text-primary md:text-[4.25rem]"
            >
              Construction contract
              <br />
              administration, connected.
            </h1>
            <p data-reveal className="mx-auto mt-6 max-w-[40ch] text-lg leading-relaxed text-text-secondary">
              Automated contract analysis, trade packages, payment applications, and
              statutory notices — one connected platform, not eight disconnected tools.
            </p>
            <div data-reveal className="mt-8 flex flex-wrap items-center justify-center gap-7">
              <Link
                href="/book-a-demo"
                className="rounded-full bg-accent px-7 py-3.5 text-sm font-medium text-accent-fg shadow-[var(--shadow-card)] transition-all duration-200 hover:shadow-[var(--shadow-pop)] active:translate-y-px"
              >
                Book a Demo
              </Link>
              <Link
                href="#how-it-works"
                className="group flex items-center gap-1.5 text-sm font-medium text-text-secondary transition-colors hover:text-text-primary"
              >
                Explore the Platform
                <span className="transition-transform duration-200 group-hover:translate-x-1">→</span>
              </Link>
            </div>
          </div>

          <div data-reveal className="relative mx-auto mt-6 max-w-5xl md:mt-8">
            <HeroChip icon={LayoutGrid} label="Everything in one place" className="-left-8 top-[38%] lg:-left-16" />
            <HeroChip icon={Clock} label="Always up to date" className="-right-8 top-[8%] lg:-right-16" />
            <HeroChip icon={Network} label="Connected by design" className="-right-8 top-[62%] lg:-right-16" />

            <MockupFrame elevated>
              <DashboardPreview />
            </MockupFrame>
          </div>
        </HeroReveal>
      </Container>
    </section>
  );
}
