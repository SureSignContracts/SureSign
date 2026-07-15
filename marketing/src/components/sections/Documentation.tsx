import { Container } from '@/components/shared/Container';

export function Documentation() {
  return (
    <section className="border-b border-border py-16 md:py-24">
      <Container>
        <div className="flex flex-col items-start justify-between gap-8 rounded-2xl border border-border bg-bg-surface p-10 md:flex-row md:items-center">
          <div>
            <h2 className="text-2xl font-medium tracking-tight text-text-primary">Need help?</h2>
            <p className="mt-3 max-w-[42ch] text-text-secondary">
              A complete, 114-page User Guide. Searchable. Step-by-step. Always
              available, whether you&apos;re setting up your first project or issuing your
              hundredth notice.
            </p>
          </div>
          <a
            href="https://docs.suresigncontracts.app"
            target="_blank"
            rel="noopener"
            className="shrink-0 rounded-full border border-border px-6 py-3 text-sm font-medium text-text-primary transition-colors hover:border-border-light"
          >
            Read the Docs
          </a>
        </div>
      </Container>
    </section>
  );
}
