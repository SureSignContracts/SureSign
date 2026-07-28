import { Container } from '@/components/shared/Container';

const VALUES = [
  'Reduce repeated contract setup across separate tools.',
  'Keep critical dates visible alongside the records they affect.',
  'Spend less time searching across folders, inboxes and spreadsheets.',
  'Give project teams a consistent administration process.',
  'Connect notices and commercial events to their supporting documents.',
  'Prepare clearer project histories for reviews and disputes.',
];

export function OperationalValue() {
  return (
    <section aria-labelledby="operational-value-title" className="tone-surface border-b border-border py-20 md:py-28">
      <Container>
        <div className="grid gap-12 md:grid-cols-[0.8fr_1.2fr] md:gap-20">
          <div>
            <p className="text-sm font-medium text-text-muted">Operational value</p>
            <h2 id="operational-value-title" className="mt-3 max-w-[18ch] text-3xl font-medium tracking-tight text-text-primary">
              Less duplicated administration. A clearer commercial position.
            </h2>
            <p className="mt-5 max-w-[42ch] text-text-secondary">
              These are practical workflow outcomes, not guarantees of contractual or legal results.
            </p>
          </div>
          <ul className="grid gap-x-10 border-t border-border sm:grid-cols-2">
            {VALUES.map((value) => (
              <li key={value} className="border-b border-border py-5 pr-4 text-sm leading-6 text-text-secondary">
                {value}
              </li>
            ))}
          </ul>
        </div>
      </Container>
    </section>
  );
}
