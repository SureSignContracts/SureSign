import { Suspense } from 'react';
import { MarketingNav } from '@/components/nav/MarketingNav';
import { Footer } from '@/components/shared/Footer';
import { Container } from '@/components/shared/Container';
import { ConsultancyBookingFormReveal } from '@/components/consultancy/ConsultancyBookingForm';

export const metadata = {
  title: 'Book a Consultation',
  description: 'Book a private consultation with an experienced construction professional.',
};

export default async function ConsultancyBookCodePage({ params }: { params: Promise<{ code: string }> }) {
  const { code } = await params;

  return (
    <>
      <MarketingNav />
      <main className="py-16 md:py-24">
        <Container className="max-w-[960px]">
          <Suspense fallback={null}>
            <ConsultancyBookingFormReveal code={code} />
          </Suspense>
        </Container>
      </main>
      <Footer />
    </>
  );
}
