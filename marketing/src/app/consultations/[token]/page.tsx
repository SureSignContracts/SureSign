import { Suspense } from 'react';
import { MarketingNav } from '@/components/nav/MarketingNav';
import { Footer } from '@/components/shared/Footer';
import { Container } from '@/components/shared/Container';
import { PublicConsultationExperience } from '@/components/consultations/PublicConsultationExperience';
import { LoadingSkeleton } from '@/components/appointments/StateScreens';
import { buildBrandedMetadata } from '@/lib/brandedMetadata';

export async function generateMetadata() {
  return buildBrandedMetadata('Your Consultation');
}

export default async function ConsultationTokenPage({ params }: { params: Promise<{ token: string }> }) {
  const { token } = await params;

  return (
    <>
      <MarketingNav />
      <main className="py-20 md:py-28">
        <Container className="max-w-[640px]">
          <Suspense fallback={<LoadingSkeleton />}>
            <PublicConsultationExperience token={token} />
          </Suspense>
        </Container>
      </main>
      <Footer />
    </>
  );
}
