import { Suspense } from 'react';
import { MarketingNav } from '@/components/nav/MarketingNav';
import { Footer } from '@/components/shared/Footer';
import { Container } from '@/components/shared/Container';
import { PublicBookingFormReveal } from '@/components/booking/PublicBookingForm';

export const metadata = {
  title: 'Book a Demo',
  description: 'Choose a convenient time to see how SureSign connects contract intelligence, commercial workflows, project administration, and documentation in one platform.',
};

export default async function BookSlugPage({ params }: { params: Promise<{ slug: string }> }) {
  const { slug } = await params;

  return (
    <>
      <MarketingNav />
      <main className="py-16 md:py-24">
        <Container className="max-w-[960px]">
          <Suspense fallback={null}>
            <PublicBookingFormReveal slug={slug} />
          </Suspense>
        </Container>
      </main>
      <Footer />
    </>
  );
}
