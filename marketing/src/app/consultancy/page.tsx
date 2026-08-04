import type { Metadata } from 'next';
import { MarketingNav } from '@/components/nav/MarketingNav';
import { Footer } from '@/components/shared/Footer';
import { ConsultancyExperience } from '@/components/consultancy/ConsultancyExperience';

export const metadata: Metadata = {
  title: 'Consultancy',
  description:
    'Book a private consultation with an experienced construction professional to discuss payment applications, notices, variations, extensions of time, and general contract administration.',
  alternates: {
    canonical: '/consultancy',
  },
  openGraph: {
    title: 'SureSign Consultancy',
    description: 'Professional guidance on your construction project, from a real person — not AI.',
    url: 'https://suresigncontracts.app/consultancy',
    siteName: 'SureSign',
    locale: 'en_GB',
    type: 'website',
  },
  twitter: {
    card: 'summary_large_image',
    title: 'SureSign Consultancy',
    description: 'Professional guidance on your construction project, from a real person — not AI.',
  },
};

export default function ConsultancyPage() {
  return (
    <>
      <MarketingNav />
      <main id="main-content">
        <ConsultancyExperience />
      </main>
      <Footer />
    </>
  );
}
