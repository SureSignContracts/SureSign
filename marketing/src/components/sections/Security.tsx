import { Container } from '@/components/shared/Container';

const POINTS = [
  { title: 'Organisation-based security', detail: 'Every project, contract, and document is scoped to your organisation.' },
  { title: 'Role-based access', detail: 'Access is controlled by role, not by trusting everyone with everything.' },
  { title: 'Complete audit history', detail: 'Every upload, generation, and status change is recorded against the project.' },
  { title: 'Secure document storage', detail: 'Files are stored and versioned centrally, with an optional local mirror.' },
];

export function Security() {
  return (
    <section id="security" className="border-b border-border py-28 md:py-36">
      <Container>
        <div className="max-w-[56ch]">
          <h2 className="text-2xl font-medium tracking-tight text-text-primary md:text-3xl">Security</h2>
          <p className="mt-4 text-text-secondary">
            Built for construction workflows, not retrofitted from a generic
            document tool.
          </p>
        </div>

        <div className="mt-12 grid grid-cols-1 gap-x-10 gap-y-8 border-t border-border pt-10 sm:grid-cols-2">
          {POINTS.map((point) => (
            <div key={point.title}>
              <div className="text-base font-medium text-text-primary">{point.title}</div>
              <p className="mt-2 max-w-[38ch] text-sm text-text-secondary">{point.detail}</p>
            </div>
          ))}
        </div>
      </Container>
    </section>
  );
}
