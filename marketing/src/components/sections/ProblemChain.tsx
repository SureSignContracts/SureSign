import { Container } from '@/components/shared/Container';
import { ProblemChainAnimation } from '@/components/sections/ProblemChainAnimation';

export function ProblemChain() {
  return (
    <section className="tone-surface border-b border-border py-28 md:py-36">
      <Container>
        <div className="max-w-[52ch]">
          <h2 className="text-3xl font-medium tracking-tight text-text-primary md:text-4xl">
            A contract still passes through six tools before anyone gets paid.
          </h2>
          <p className="mt-5 max-w-[46ch] text-text-secondary">
            The terms live in a PDF. The commercial position lives in a spreadsheet. One
            missed statutory deadline turns a routine payment into a dispute.
          </p>
        </div>

        <div className="mt-20">
          <ProblemChainAnimation />
        </div>
      </Container>
    </section>
  );
}
