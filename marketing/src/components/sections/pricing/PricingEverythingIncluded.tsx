import { Check } from 'lucide-react';
import { Container } from '@/components/shared/Container';
import type { PricingIncludedItem, PricingSettings } from '@/lib/pricing';

export function PricingEverythingIncluded({ items, settings }: { items: PricingIncludedItem[]; settings: PricingSettings }) {
  if (items.length === 0) return null;

  return (
    <section className="border-b border-border py-20 md:py-28">
      <Container>
        <div className="max-w-[58ch]">
          <h2 className="text-2xl font-medium tracking-tight text-text-primary md:text-3xl">
            {settings.everything_included_title || 'Everything Included'}
          </h2>
          {settings.everything_included_subtitle && (
            <p className="mt-4 text-base leading-7 text-text-secondary">{settings.everything_included_subtitle}</p>
          )}
        </div>

        <ul className="mt-12 grid overflow-hidden rounded-2xl border border-border bg-bg-surface sm:grid-cols-2 lg:grid-cols-3">
          {items.map((item, index) => (
            <li
              key={`${item.text}-${index}`}
              className="flex min-h-24 items-start gap-4 border-b border-border p-5 last:border-b-0 sm:border-r sm:[&:nth-child(2n)]:border-r-0 lg:[&:nth-child(2n)]:border-r lg:[&:nth-child(3n)]:border-r-0"
            >
              <span className="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-lg border border-border bg-bg-base shadow-[var(--shadow-card)]">
                <Check size={14} strokeWidth={1.8} className="text-text-primary" aria-hidden="true" />
              </span>
              <span className="text-sm leading-6 text-text-secondary">{item.text}</span>
            </li>
          ))}
        </ul>
      </Container>
    </section>
  );
}
