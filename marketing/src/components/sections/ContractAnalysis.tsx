import { Container } from '@/components/shared/Container';
import { AiEngineSequence } from '@/components/sections/AiEngineSequence';

export function ContractAnalysis() {
  return (
    <section className="border-b border-border py-24 md:py-32" aria-labelledby="human-review-title">
      <Container>
        <div className="grid items-center gap-14 md:grid-cols-[1.1fr_0.9fr] md:gap-20">
          <div className="order-2 md:order-1">
            <AiEngineSequence />
          </div>
          <div className="order-1 md:order-2">
            <div className="text-sm font-medium uppercase tracking-wide text-text-muted">
              Human-reviewed contract intelligence
            </div>
            <h2 id="human-review-title" className="mt-3 text-3xl font-medium tracking-tight text-text-primary">
              Nothing is used until a person reviews and confirms it.
            </h2>
            <p className="mt-4 max-w-[48ch] text-text-secondary">
              SureSign helps extract parties, key dates, payment rules and programme
              milestones from an uploaded contract. A user inspects and corrects the
              output before confirming it. Only confirmed information is used to populate
              downstream workflows.
            </p>
            <p className="mt-4 max-w-[48ch] text-sm leading-6 text-text-muted">
              SureSign does not silently make contractual decisions. The project team
              remains responsible for professional review and administration.
            </p>
          </div>
        </div>
      </Container>
    </section>
  );
}
