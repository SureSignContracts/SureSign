import type { Metadata } from 'next';
import { MarketingNav } from '@/components/nav/MarketingNav';
import { Footer } from '@/components/shared/Footer';
import { PricingHero } from '@/components/sections/pricing/PricingHero';
import { PricingCards } from '@/components/sections/pricing/PricingCards';
import { PricingOverviewSummary } from '@/components/sections/pricing/PricingOverviewSummary';
import { PricingFaq } from '@/components/sections/pricing/PricingFaq';
import { PricingFinalCta } from '@/components/sections/pricing/PricingFinalCta';
import { PricingFallback } from '@/components/sections/pricing/PricingFallback';
import { getPricingData } from '@/lib/pricing';

export const metadata: Metadata = {
  title: 'Pricing Overview',
  description: 'Explore SureSign plans for construction contract administration, compare current pricing, and choose the right level for your team.',
  alternates: { canonical: '/pricing' },
  openGraph: {
    title: 'SureSign Pricing Overview',
    description: 'Explore SureSign plans for construction contract administration and choose the right level for your team.',
    url: '/pricing',
    siteName: 'SureSign',
    locale: 'en_GB',
    type: 'website',
  },
  twitter: {
    card: 'summary_large_image',
    title: 'SureSign Pricing Overview',
    description: 'Explore SureSign plans for construction contract administration.',
  },
};

export default async function PricingPage() {
  const data = await getPricingData();

  return (
    <>
      <MarketingNav />
      <main id="main-content" className="pt-8">
        {data ? (
          <>
            <PricingHero settings={data.settings} />
            <PricingCards plans={data.plans} settings={data.settings} featureSections={data.feature_sections} />
            <PricingOverviewSummary plans={data.plans} sections={data.feature_sections} />
            <PricingFaq faqs={data.faqs} />
            <PricingFinalCta settings={data.settings} />
          </>
        ) : (
          <PricingFallback />
        )}
      </main>
      <Footer />
    </>
  );
}
