import { Container } from '@/components/shared/Container';

export interface LegalSection {
  title: string;
  content: string;
}

export function LegalDocument({
  eyebrow,
  title,
  updated,
  introduction,
  sections,
}: {
  eyebrow: string;
  title: string;
  updated: string;
  introduction: string;
  sections: LegalSection[];
}) {
  return (
    <section className="py-16 md:py-24">
      <Container>
        <div className="mx-auto max-w-3xl">
          <header className="border-b border-border pb-8">
            <p className="text-sm font-medium text-text-muted">{eyebrow}</p>
            <h1 className="mt-4 text-4xl font-medium tracking-tighter text-text-primary md:text-6xl">
              {title}
            </h1>
            <p className="mt-4 text-sm text-text-muted">Last updated: {updated}</p>
            <p className="mt-8 max-w-2xl text-base leading-7 text-text-secondary">
              {introduction}
            </p>
          </header>

          <div className="divide-y divide-border">
            {sections.map((section, index) => (
              <section
                key={section.title}
                aria-labelledby={`legal-section-${index}`}
                className="grid gap-4 py-8 md:grid-cols-[12rem_1fr] md:gap-10"
              >
                <h2
                  id={`legal-section-${index}`}
                  className="text-base font-medium text-text-primary"
                >
                  {section.title}
                </h2>
                <p className="whitespace-pre-line text-sm leading-7 text-text-secondary">
                  {section.content}
                </p>
              </section>
            ))}
          </div>
        </div>
      </Container>
    </section>
  );
}
