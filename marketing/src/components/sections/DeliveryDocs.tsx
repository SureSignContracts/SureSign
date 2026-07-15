import { Container } from '@/components/shared/Container';
import { MockupFrame } from '@/components/shared/MockupFrame';
import { DocumentsExplorer } from '@/components/shared/placeholders';

const SUPPORTING = [
  { title: 'Meetings', detail: 'Minutes and actions logged against the project record.' },
  { title: 'Site Reports', detail: 'Progress and conditions captured as they happen on site.' },
  { title: 'QA', detail: 'Quality reports and snagging tracked through to closeout.' },
];

export function DeliveryDocs() {
  return (
    <section className="tone-surface border-b border-border py-24 md:py-32">
      <Container>
        <div className="max-w-[52ch]">
          <h2 className="text-2xl font-medium tracking-tight text-text-primary md:text-3xl">
            The paperwork a live site generates, filed automatically.
          </h2>
        </div>

        <div className="mt-12 grid gap-4 md:grid-cols-[1.3fr_1fr]">
          <div className="rounded-2xl border border-border bg-bg-base p-8 shadow-[var(--shadow-card)] transition-shadow duration-300 hover:shadow-[var(--shadow-pop)] md:p-10">
            <div className="text-xl font-medium tracking-tight text-text-primary">Documents</div>
            <p className="mt-3 max-w-[38ch] text-text-secondary">
              Every file — meetings, site reports, QA, general — lands in the same
              standard folder structure and document register as everything else,
              atomically numbered so nothing gets overwritten or duplicated.
            </p>
            <MockupFrame className="mt-6">
              <DocumentsExplorer />
            </MockupFrame>
          </div>

          <div className="grid gap-4">
            {SUPPORTING.map((item) => (
              <div key={item.title} className="rounded-2xl border border-border bg-bg-base p-6 transition-colors duration-200 hover:border-border-light">
                <div className="text-base font-medium text-text-primary">{item.title}</div>
                <p className="mt-1.5 max-w-[36ch] text-sm text-text-secondary">{item.detail}</p>
              </div>
            ))}
          </div>
        </div>
      </Container>
    </section>
  );
}
