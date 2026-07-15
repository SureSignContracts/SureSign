import type { Metadata } from 'next';
import { MarketingNav } from '@/components/nav/MarketingNav';
import { Footer } from '@/components/shared/Footer';
import { Container } from '@/components/shared/Container';
import { BookDemoForm } from '@/components/demo/BookDemoForm';

export const metadata: Metadata = {
  title: 'Book a Demo',
  description: 'See SureSign on your own contract — book a demo with the team.',
};

export default function BookADemoPage() {
  return (
    <>
      <MarketingNav />
      <main className="py-20 md:py-28">
        <Container className="max-w-[640px]">
          <h1 className="text-3xl font-medium tracking-tight text-text-primary md:text-4xl">Book a demo.</h1>
          <p className="mt-4 text-text-secondary">
            Bring a real contract to the call. We&apos;ll show you exactly how SureSign
            handles it, end to end — contract analysis, trade packages, payment
            applications, and everything else on the same platform.
          </p>
          <div className="mt-10">
            <BookDemoForm />
          </div>
        </Container>
      </main>
      <Footer />
    </>
  );
}
