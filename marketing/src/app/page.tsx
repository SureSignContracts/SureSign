import type { Metadata } from 'next';
import { MarketingNav } from '@/components/nav/MarketingNav';
import { Footer } from '@/components/shared/Footer';
import { Hero } from '@/components/hero/Hero';
import { MarketStatus } from '@/components/sections/MarketStatus';
import { CommercialOutcomes } from '@/components/sections/CommercialOutcomes';
import { ContractAnalysis } from '@/components/sections/ContractAnalysis';
import { ProductWalkthrough } from '@/components/demo/ProductWalkthrough';
import { OperationalValue } from '@/components/sections/OperationalValue';
import { ConnectedPlatform } from '@/components/sections/ConnectedPlatform';
import { Security } from '@/components/sections/Security';
import { BuyerQuestions } from '@/components/sections/BuyerQuestions';
import { HomePricingSummary } from '@/components/sections/HomePricingSummary';
import { BookDemoCta } from '@/components/sections/BookDemoCta';
import { getPricingData } from '@/lib/pricing';

export const metadata: Metadata = {
  title: 'Construction Contract Administration, Connected',
  description:
    'Turn the contract into a controlled commercial workflow. SureSign connects human-reviewed contract intelligence to notices, applications, programme events and the complete project record.',
  alternates: { canonical: '/' },
  openGraph: {
    title: 'Turn the contract into a controlled commercial workflow | SureSign',
    description:
      'Human-reviewed contract intelligence connected to construction commercial workflows and one project record.',
    url: '/',
    siteName: 'SureSign',
    locale: 'en_GB',
    type: 'website',
  },
  twitter: {
    card: 'summary_large_image',
    title: 'Turn the contract into a controlled commercial workflow | SureSign',
    description:
      'Human-reviewed contract intelligence connected to construction commercial workflows and one project record.',
  },
};

const JSON_LD = {
  '@context': 'https://schema.org',
  '@graph': [
    {
      '@type': 'Organization',
      name: 'SureSign',
      url: 'https://suresigncontracts.app',
    },
    {
      '@type': 'SoftwareApplication',
      name: 'SureSign',
      applicationCategory: 'BusinessApplication',
      operatingSystem: 'Web',
      description:
        'Construction contract administration platform with automated contract analysis, trade packages, payment applications, statutory notices, programme, and risk in one connected workflow.',
    },
  ],
};

export default async function HomePage() {
  const pricing = await getPricingData();

  return (
    <>
      <script
        type="application/ld+json"
        dangerouslySetInnerHTML={{ __html: JSON.stringify(JSON_LD) }}
      />
      <MarketingNav />
      <main id="main-content">
        <Hero />
        <MarketStatus />
        <CommercialOutcomes />
        <ProductWalkthrough />
        <ContractAnalysis />
        <OperationalValue />
        <ConnectedPlatform />
        <Security />
        <BuyerQuestions />
        {pricing && <HomePricingSummary plans={pricing.plans} />}
        <BookDemoCta />
      </main>
      <Footer />
    </>
  );
}
