import { Container } from '@/components/shared/Container';

const FEATURED = ['Contracts', 'Commercial'];
const FOLDERS = [
  'Subcontracts', 'Payment Applications', 'Variations',
  'Notices', 'RFIs', 'Meetings', 'QA Reports', 'Site Reports', 'Adjudication', 'General',
];

export function ProjectWorkspace() {
  return (
    <section className="tone-surface border-b border-border py-20 md:py-28">
      <Container>
        <div className="max-w-[52ch]">
          <h2 className="text-2xl font-medium tracking-tight text-text-primary md:text-3xl">
            Once a contract is confirmed, the project workspace builds itself.
          </h2>
          <p className="mt-4 max-w-[46ch] text-text-secondary">
            Every project gets the same standard set of module folders on creation,
            mirrored to a local Windows folder if your team needs it. No one has to
            remember the structure — it&apos;s already there.
          </p>
        </div>

        <div className="mt-12 grid grid-cols-2 gap-2.5 sm:grid-cols-4">
          {FEATURED.map((folder) => (
            <div
              key={folder}
              className="col-span-2 rounded-xl border border-border bg-bg-base px-5 py-5 text-sm font-medium text-text-primary shadow-[var(--shadow-card)] transition-colors duration-200 hover:border-border-light sm:col-span-2"
            >
              {folder}
            </div>
          ))}
          {FOLDERS.map((folder) => (
            <div
              key={folder}
              className="rounded-xl border border-border bg-bg-base px-4 py-3.5 text-sm text-text-secondary transition-colors duration-200 hover:border-border-light hover:text-text-primary"
            >
              {folder}
            </div>
          ))}
        </div>
      </Container>
    </section>
  );
}
