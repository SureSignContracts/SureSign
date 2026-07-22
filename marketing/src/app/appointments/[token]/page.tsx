import { Suspense } from 'react';
import { MarketingNav } from '@/components/nav/MarketingNav';
import { Footer } from '@/components/shared/Footer';
import { Container } from '@/components/shared/Container';
import { PublicAppointmentExperience } from '@/components/appointments/PublicAppointmentExperience';
import { LoadingSkeleton } from '@/components/appointments/StateScreens';

export const metadata = {
  title: 'Manage Your Appointment',
  robots: { index: false, follow: false },
};

export default async function AppointmentTokenPage({ params }: { params: Promise<{ token: string }> }) {
  const { token } = await params;

  return (
    <>
      <MarketingNav />
      <main className="py-20 md:py-28">
        <Container className="max-w-[640px]">
          <Suspense fallback={<LoadingSkeleton />}>
            <PublicAppointmentExperience token={token} />
          </Suspense>
        </Container>
      </main>
      <Footer />
    </>
  );
}
