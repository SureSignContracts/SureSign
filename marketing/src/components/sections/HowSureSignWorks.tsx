import { Container } from '@/components/shared/Container';
import { HowItWorksTimeline } from '@/components/sections/HowItWorksTimeline';

export function HowSureSignWorks() {
  return (
    <section id="how-it-works" className="bg-draft border-b border-border py-32 md:py-44">
      <Container>
        <div className="grid gap-16 md:grid-cols-[0.7fr_1.3fr] md:gap-24">
          <div className="md:sticky md:top-32 md:self-start">
            <h2 className="text-3xl font-medium tracking-tight text-text-primary md:text-4xl">
              How SureSign works, end to end.
            </h2>
            <p className="mt-5 max-w-[38ch] text-text-secondary">
              One journey, from the contract landing on your desk to the final account
              being agreed. Everything below happens inside the same platform, on the
              same audit trail.
            </p>
          </div>
          <HowItWorksTimeline />
        </div>
      </Container>
    </section>
  );
}
