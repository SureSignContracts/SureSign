import { Container } from '@/components/shared/Container';
import { RevealGroup } from '@/components/shared/RevealGroup';

const OUTCOMES = [
  {
    number: '01',
    title: 'Understand the contract',
    detail:
      'Identify and organise the dates, payment rules, notice requirements, obligations, particulars, risks and programme information that govern the project.',
  },
  {
    number: '02',
    title: 'Control commercial obligations',
    detail:
      'Use confirmed contract information across payment, notice, variation, delay, trade package and programme workflows without repeatedly re-entering the same rules.',
  },
  {
    number: '03',
    title: 'Maintain one defensible record',
    detail:
      'Keep documents, decisions, notices, generated records, commercial events, programme events and activity history connected to the same project record.',
  },
];

export function CommercialOutcomes() {
  return (
    <section aria-labelledby="outcomes-title" className="border-b border-border py-24 md:py-32">
      <Container>
        <RevealGroup className="grid gap-10 md:grid-cols-[0.72fr_1.28fr] md:gap-20">
          <div data-reveal-item className="md:sticky md:top-28 md:self-start">
            <p className="text-sm font-medium text-text-muted">Three commercial outcomes</p>
            <h2 id="outcomes-title" className="mt-3 max-w-[16ch] text-3xl font-medium tracking-tight text-text-primary md:text-4xl">
              From contract particulars to a project record you can follow.
            </h2>
          </div>
          <ol className="border-t border-border">
            {OUTCOMES.map((outcome) => (
              <li
                key={outcome.number}
                data-reveal-item
                className="group relative grid gap-3 border-b border-border py-8 sm:grid-cols-[3rem_0.75fr_1.25fr] sm:gap-6 md:py-10"
              >
                <span className="font-mono text-xs text-text-muted transition-colors group-hover:text-text-primary">{outcome.number}</span>
                <h3 className="text-lg font-medium tracking-tight text-text-primary">{outcome.title}</h3>
                <p className="max-w-[48ch] text-sm leading-6 text-text-secondary">{outcome.detail}</p>
                <span
                  aria-hidden
                  className="absolute inset-x-0 bottom-0 h-px origin-left scale-x-0 bg-text-primary transition-transform duration-500 ease-out group-hover:scale-x-100"
                />
              </li>
            ))}
          </ol>
        </RevealGroup>
      </Container>
    </section>
  );
}
