import { Container } from '@/components/shared/Container';
import { AiEngineSequence } from '@/components/sections/AiEngineSequence';

export function ContractAnalysis() {
  return (
    <section className="border-b border-border py-24 md:py-32">
      <Container>
        <div className="grid items-center gap-14 md:grid-cols-[1.1fr_0.9fr] md:gap-20">
          <div className="order-2 md:order-1">
            <AiEngineSequence />
          </div>
          <div className="order-1 md:order-2">
            <div className="text-sm font-medium uppercase tracking-wide text-text-muted">
              Automated Contract Analysis
            </div>
            <h2 className="mt-3 text-2xl font-medium tracking-tight text-text-primary md:text-3xl">
              The engine that reads the contract, so the rest of the platform doesn&apos;t have to guess.
            </h2>
            <p className="mt-4 max-w-[48ch] text-text-secondary">
              SureSign automatically extracts parties, key dates, payment rules, and
              programme milestones from an uploaded contract. Nothing is used until a
              person reviews and confirms it — this is automated commercial intelligence,
              not a chatbot you interrogate. Once confirmed, that data powers statutory
              date calculations and programme seeding across the rest of the project.
            </p>
          </div>
        </div>
      </Container>
    </section>
  );
}
