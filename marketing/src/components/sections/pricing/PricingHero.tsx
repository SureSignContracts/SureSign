import { Container } from '@/components/shared/Container';
import { HeroReveal } from '@/components/hero/HeroReveal';
import type { PricingSettings } from '@/lib/pricing';

export function PricingHero({ settings }: { settings: PricingSettings }) {
  return (
    <section className="relative overflow-hidden pb-12 pt-14 md:pb-16 md:pt-16">
      <div aria-hidden className="absolute inset-0 -z-10 bg-[radial-gradient(ellipse_60%_50%_at_50%_40%,var(--spotlight),transparent_70%)]" />

      <Container>
        <HeroReveal>
          <div className="mx-auto max-w-[52rem] text-center">
            <h1 data-reveal className="text-4xl font-medium leading-[0.98] tracking-tighter text-text-primary md:text-[3.75rem]">
              {settings.hero_title || 'Simple, transparent pricing'}
            </h1>
            {settings.hero_subtitle && (
              <p data-reveal className="mx-auto mt-5 max-w-[48ch] text-base leading-7 text-text-secondary md:text-lg">
                {settings.hero_subtitle}
              </p>
            )}
          </div>
        </HeroReveal>
      </Container>
    </section>
  );
}
