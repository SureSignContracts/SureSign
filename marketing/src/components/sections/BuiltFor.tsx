import { Container } from '@/components/shared/Container';

const ROLES = ['Commercial Managers', 'Quantity Surveyors', 'Project Managers', 'Construction Directors'];

export function BuiltFor() {
  return (
    <section className="tone-surface border-b border-border py-20 md:py-28">
      <Container>
        <div className="text-sm font-medium uppercase tracking-wide text-text-muted">Built For</div>
        <h2 className="mt-3 max-w-[20ch] text-2xl font-medium tracking-tight text-text-primary md:text-3xl">
          Contractors administering JCT and NEC contracts.
        </h2>

        <div className="mt-10 flex flex-wrap items-baseline gap-x-3 gap-y-2 border-t border-border pt-8">
          {ROLES.map((role, i) => (
            <span key={role} className="flex items-baseline gap-3">
              <span className="text-xl font-medium tracking-tight text-text-primary md:text-2xl">{role}</span>
              {i < ROLES.length - 1 && <span className="text-text-muted">·</span>}
            </span>
          ))}
        </div>
      </Container>
    </section>
  );
}
