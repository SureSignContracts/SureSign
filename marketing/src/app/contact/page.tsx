import type { Metadata } from 'next';
import { MarketingNav } from '@/components/nav/MarketingNav';
import { ContactExperience } from '@/components/contact/ContactExperience';
import { BookDemoCta } from '@/components/sections/BookDemoCta';
import { Footer } from '@/components/shared/Footer';

export const metadata: Metadata = {
  title: 'Contact SureSign',
  description:
    'Contact SureSign about implementation, pricing, product questions, or construction contract administration.',
  alternates: { canonical: '/contact' },
  openGraph: {
    title: 'Contact SureSign',
    description:
      'Speak with the SureSign team about implementation, pricing, product questions, or construction contract administration.',
    url: 'https://suresigncontracts.app/contact',
    type: 'website',
  },
  twitter: {
    card: 'summary_large_image',
    title: 'Contact SureSign',
    description: 'Speak with the SureSign team about implementation, pricing or product questions.',
  },
};

export default function ContactPage() {
  return (
    <>
      <MarketingNav />
      <main id="main-content">
        <ContactExperience />
        <BookDemoCta />
      </main>
      <Footer />
    </>
  );
}
