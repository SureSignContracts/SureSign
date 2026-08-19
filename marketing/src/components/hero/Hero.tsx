import Link from 'next/link';
import Image from 'next/image';
import { LayoutGrid, Clock, Network } from 'lucide-react';
import { Container } from '@/components/shared/Container';
import { MockupFrame } from '@/components/shared/MockupFrame';
import { HeroReveal } from '@/components/hero/HeroReveal';
import { HeroBlueprint } from '@/components/hero/HeroBlueprint';
import { HeroChip } from '@/components/hero/HeroChip';

export function Hero() {
  return (
    <section className="relative isolate flex flex-col items-center overflow-hidden pb-10 pt-12 md:pb-14 md:pt-16">
      <HeroBlueprint />
      <div aria-hidden className="absolute inset-0 -z-20 bg-[radial-gradient(ellipse_60%_50%_at_50%_40%,var(--spotlight),transparent_70%)]" />

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
              Construction contract administration
            </div>
            <h1
              data-reveal
              className="mt-5 text-4xl font-medium leading-[0.98] tracking-tighter text-text-primary text-balance md:text-[4.25rem]"
            >
              Turn the contract into a controlled commercial workflow.
            </h1>
            <p data-reveal className="mx-auto mt-6 max-w-[40ch] text-base leading-relaxed text-text-secondary md:text-lg">
              SureSign extracts the dates, obligations and payment rules your team
              needs, then connects them to notices, applications, programme events
              and the complete project record.
            </p>
            <div data-reveal className="mt-8 flex flex-wrap items-center justify-center gap-7">
              <Link
                href="/book/demo?src=home"
                className="inline-flex min-h-12 items-center rounded-full bg-accent px-7 text-sm font-medium text-accent-fg shadow-[var(--shadow-card)] transition-[transform,box-shadow] duration-200 hover:-translate-y-0.5 hover:shadow-[var(--shadow-pop)] active:translate-y-px"
              >
                Book a Demo
              </Link>
              <Link
                href="#how-it-works"
                className="group flex min-h-11 items-center gap-1.5 text-sm font-medium text-text-secondary transition-colors hover:text-text-primary"
              >
                See How It Works
                <span className="transition-transform duration-200 group-hover:translate-x-1">→</span>
              </Link>
            </div>
          </div>

          <div data-reveal className="relative mx-auto mt-9 max-w-5xl md:mt-11">
            <HeroChip icon={LayoutGrid} label="Everything in one place" className="-left-8 top-[38%] lg:-left-16" />
            <HeroChip icon={Clock} label="Always up to date" className="-right-8 top-[8%] lg:-right-16" />
            <HeroChip icon={Network} label="Connected by design" className="-right-8 top-[62%] lg:-right-16" />

            {/* tilt={false} — a real screenshot visibly blurs under the
                placeholder frame's static 3D rotate (browser has to resample
                the bitmap at an angle); the CSS-drawn mockups elsewhere don't
                have that problem, so only this real <Image> opts out. */}
            <MockupFrame elevated tilt={false}>
              {/*
               * unoptimized — the source is already a compressed, correctly
               * sized (1860w) webp screenshot. Next's default srcset still
               * spans every configured deviceSize up to 3840w regardless of
               * the `sizes` hint (that hint only tells the BROWSER which
               * entry to pick, it doesn't stop Next generating the larger
               * ones), so a high-DPI/large-viewport visitor was being served
               * a variant upscaled from 1860px to as much as 3840px and
               * re-encoded — genuinely blurry, not a rendering illusion.
               * `quality` doesn't help here either: this Next version's
               * default `images.qualities` allow-list is [75] only, so the
               * quality={90} this used to have was silently rejected and
               * fell back to 75 (confirmed via the served HTML's own
               * srcset, every entry `q=75`). Serving the real bytes directly
               * avoids both problems.
               */}
              <Image
                src="/dashboard.webp"
                alt="SureSign project dashboard showing overdue and due-soon items and a live project map"
                width={1860}
                height={958}
                priority
                unoptimized
                className="h-auto w-full"
              />
            </MockupFrame>
          </div>
        </HeroReveal>
      </Container>
    </section>
  );
}
