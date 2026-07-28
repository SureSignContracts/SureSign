import type { Metadata } from 'next';
import Link from 'next/link';
import { MarketingNav } from '@/components/nav/MarketingNav';
import { Footer } from '@/components/shared/Footer';
import { Container } from '@/components/shared/Container';
import { PricingComparison } from '@/components/sections/pricing/PricingComparison';
import { PricingFallback } from '@/components/sections/pricing/PricingFallback';
import { PricingFinalCta } from '@/components/sections/pricing/PricingFinalCta';
import { getPricingData } from '@/lib/pricing';

export const metadata: Metadata = {
  title: 'Compare Plans',
  description: 'Compare every configured SureSign plan feature, limit, and capability for construction contract administration.',
  alternates: { canonical: '/pricing/compare' },
  openGraph: {
    title: 'Compare SureSign Plans',
    description: 'Compare every configured SureSign plan feature, limit, and capability.',
    url: '/pricing/compare',
    siteName: 'SureSign',
    locale: 'en_GB',
    type: 'website',
  },
  twitter: {
    card: 'summary_large_image',
    title: 'Compare SureSign Plans',
    description: 'Compare every configured SureSign plan feature, limit, and capability.',
  },
};

export default async function ComparePricingPlansPage() {
  const data = await getPricingData();

  return (
    <>
      <MarketingNav />
      <main id="main-content">
        {data ? (
          <>
            <section className="bg-atmosphere border-b border-border">
              <Container className="py-20 md:py-24">
                <Link href="/pricing" className="text-sm font-medium text-text-muted transition-colors hover:text-text-primary">
                  Pricing overview
                </Link>
                <h1 className="mt-5 max-w-[14ch] text-5xl font-medium leading-[0.98] tracking-tighter text-text-primary md:text-7xl">
                  Compare Plans
                </h1>
                <p className="mt-6 max-w-[48ch] text-base leading-7 text-text-secondary md:text-lg">
                  Review every configured feature and plan distinction in one place.
                </p>
              </Container>
            </section>
            <PricingComparison plans={data.plans} sections={data.feature_sections} />
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
