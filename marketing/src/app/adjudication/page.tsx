import type { Metadata } from 'next';
import { MarketingNav } from '@/components/nav/MarketingNav';
import { Footer } from '@/components/shared/Footer';
import { AdjudicationExperience } from '@/components/adjudication/AdjudicationExperience';

export const metadata: Metadata = {
  title: 'Construction Adjudication Support',
  description:
    'Learn how SureSign and its specialist sibling company, Adjudication Services, support construction businesses from proactive contract administration through to formal dispute resolution.',
  alternates: {
    canonical: '/adjudication',
  },
  openGraph: {
    title: 'Construction Adjudication Support | SureSign',
    description:
      'A clear route from proactive construction contract administration to specialist support when a dispute escalates.',
    url: 'https://suresigncontracts.app/adjudication',
    siteName: 'SureSign',
    locale: 'en_GB',
    type: 'website',
  },
  twitter: {
    card: 'summary_large_image',
    title: 'Construction Adjudication Support | SureSign',
    description: 'A clear route from proactive contract administration to specialist support when a dispute escalates.',
  },
};

export default function AdjudicationPage() {
  return (
    <>
      <MarketingNav />
      <main id="main-content">
        <AdjudicationExperience />
      </main>
      <Footer />
    </>
  );
}
