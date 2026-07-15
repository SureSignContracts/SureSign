import type { Metadata } from 'next';
import { MarketingNav } from '@/components/nav/MarketingNav';
import { Footer } from '@/components/shared/Footer';
import { Security } from '@/components/sections/Security';
import { BookDemoCta } from '@/components/sections/BookDemoCta';

export const metadata: Metadata = {
  title: 'Security',
  description:
    'How SureSign secures organisation data: organisation-based scoping, role-based access, complete audit history, and secure document storage.',
};

export default function SecurityPage() {
  return (
    <>
      <MarketingNav />
      <main className="pt-8">
        <Security />
        <BookDemoCta />
      </main>
      <Footer />
    </>
  );
}
