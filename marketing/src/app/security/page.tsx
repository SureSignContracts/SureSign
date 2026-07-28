import type { Metadata } from 'next';
import { MarketingNav } from '@/components/nav/MarketingNav';
import { Footer } from '@/components/shared/Footer';
import { Security } from '@/components/sections/Security';
import { BookDemoCta } from '@/components/sections/BookDemoCta';

export const metadata: Metadata = {
  title: 'Security',
  description:
    'Review the implemented security and operational controls SureSign can evidence today, together with the procurement details that require confirmation.',
  alternates: { canonical: '/security' },
  openGraph: {
    title: 'Security and Procurement | SureSign',
    description: 'Implemented controls for project access, roles, document storage, human review, activity records and recovery operations.',
    url: '/security',
    siteName: 'SureSign',
    locale: 'en_GB',
    type: 'website',
  },
  twitter: {
    card: 'summary_large_image',
    title: 'Security and Procurement | SureSign',
    description: 'Implemented controls SureSign can evidence today.',
  },
};

export default function SecurityPage() {
  return (
    <>
      <MarketingNav />
      <main id="main-content" className="pt-8">
        <Security />
        <BookDemoCta />
      </main>
      <Footer />
    </>
  );
}
