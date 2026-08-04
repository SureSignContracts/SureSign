import type { Metadata } from 'next';
import { MarketingNav } from '@/components/nav/MarketingNav';
import { Footer } from '@/components/shared/Footer';
import { ServicesExperience } from '@/components/services/ServicesExperience';

export const metadata: Metadata = {
  title: 'Construction Consultancy and Adjudication Services',
  description:
    'Explore SureSign professional services for construction consultancy, contract support, and adjudication-related matters.',
  alternates: {
    canonical: '/services',
  },
  openGraph: {
    title: 'Construction Consultancy and Adjudication Services | SureSign',
    description:
      'Professional construction support for contractual, commercial, and dispute-related matters.',
    url: '/services',
    siteName: 'SureSign',
    locale: 'en_GB',
    type: 'website',
  },
  twitter: {
    card: 'summary_large_image',
    title: 'Construction Consultancy and Adjudication Services | SureSign',
    description:
      'Professional construction support for contractual, commercial, and dispute-related matters.',
  },
};

export default function ServicesPage() {
  return (
    <>
      <MarketingNav />
      <main id="main-content">
        <ServicesExperience />
      </main>
      <Footer />
    </>
  );
}
