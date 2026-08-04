import { MarketingNav } from '@/components/nav/MarketingNav';
import { Footer } from '@/components/shared/Footer';
import { Container } from '@/components/shared/Container';
import { LoginGateway } from '@/components/login/LoginGateway';

export const metadata = {
  title: 'Sign In',
  robots: { index: false, follow: false },
};

/**
 * Organisation URL Branding, Phase 4 — the branded login gateway. See
 * LoginGateway's own docblock for why this collects no credentials at
 * all. Client-side resolution (there's no dynamic segment here to hang a
 * server-side generateMetadata()/host-header lookup off), matching
 * PublicAppointmentExperience/PublicConsultationExperience's own existing
 * pattern.
 */
export default function LoginPage() {
  return (
    <>
      <MarketingNav />
      <main className="py-20 md:py-28">
        <Container className="max-w-[640px]">
          <LoginGateway />
        </Container>
      </main>
      <Footer />
    </>
  );
}
