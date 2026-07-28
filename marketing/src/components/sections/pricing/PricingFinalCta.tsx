import Link from 'next/link';
import { Container } from '@/components/shared/Container';
import type { PricingSettings } from '@/lib/pricing';

export function PricingFinalCta({ settings }: { settings: PricingSettings }) {
  return (
    <section className="bg-atmosphere relative overflow-hidden py-36 md:py-48">
      <Container>
        <div className="mx-auto max-w-[40ch] text-center">
          <h2 className="text-4xl font-medium tracking-tighter text-text-primary md:text-6xl">
            {settings.final_cta_title || 'Ready to see SureSign in action?'}
          </h2>
          {settings.final_cta_subtitle && (
            <p className="mt-6 text-text-secondary">{settings.final_cta_subtitle}</p>
          )}
          <div className="mt-10 flex flex-wrap items-center justify-center gap-4">
            {settings.primary_cta_text && (
              <Link
                href={settings.primary_cta_url || '/book/demo?src=pricing'}
                target={settings.primary_cta_new_tab ? '_blank' : undefined}
                rel={settings.primary_cta_new_tab ? 'noopener' : undefined}
                className="inline-block rounded-full bg-accent px-8 py-4 text-sm font-medium text-accent-fg shadow-[var(--shadow-card)] transition-all duration-200 hover:shadow-[var(--shadow-pop)] active:translate-y-px"
              >
                {settings.primary_cta_text}
              </Link>
            )}
            {settings.secondary_cta_text && (
              <Link
                href={settings.secondary_cta_url || '/book-a-demo?src=pricing'}
                target={settings.secondary_cta_new_tab ? '_blank' : undefined}
                rel={settings.secondary_cta_new_tab ? 'noopener' : undefined}
                className="group flex items-center gap-1.5 text-sm font-medium text-text-secondary transition-colors hover:text-text-primary"
              >
                {settings.secondary_cta_text}
                <span className="transition-transform duration-200 group-hover:translate-x-1">→</span>
              </Link>
            )}
          </div>
        </div>
      </Container>
    </section>
  );
}
