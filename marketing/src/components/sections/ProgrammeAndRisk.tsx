import { Container } from '@/components/shared/Container';
import { MockupFrame } from '@/components/shared/MockupFrame';
import { ProgrammeTimeline } from '@/components/shared/placeholders';

export function ProgrammeAndRisk() {
  return (
    <section className="bg-draft border-b border-border py-20 md:py-28">
      <Container>
        <div className="grid gap-10 md:grid-cols-2 md:divide-x md:divide-border md:gap-0">
          <div className="md:pr-14">
            <div className="text-sm font-medium uppercase tracking-wide text-text-muted">Programme</div>
            <h3 className="mt-3 text-xl font-medium tracking-tight text-text-primary md:text-2xl">
              Milestones seeded from the confirmed contract, not re-typed.
            </h3>
            <p className="mt-4 max-w-[38ch] text-text-secondary">
              Seeded directly from confirmed AI analysis, then tracked alongside every
              other calendar event on one project calendar.
            </p>
            <MockupFrame className="mt-6 max-w-sm">
              <ProgrammeTimeline />
            </MockupFrame>
          </div>
          <div className="md:pl-14">
            <div className="text-sm font-medium uppercase tracking-wide text-text-muted">Risk &amp; Delay</div>
            <h3 className="mt-3 text-xl font-medium tracking-tight text-text-primary md:text-2xl">
              Delay events, EOT requests, and loss &amp; expense on the same record.
            </h3>
            <p className="mt-4 max-w-[38ch] text-text-secondary">
              Risks and delay events attach to the same contract or trade package they
              came from — a claim traces straight back to the programme impact that
              caused it.
            </p>
          </div>
        </div>
      </Container>
    </section>
  );
}
