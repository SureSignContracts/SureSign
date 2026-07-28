import Link from 'next/link';
import { Container } from '@/components/shared/Container';
import { RevealGroup } from '@/components/shared/RevealGroup';

const GROUPS = [
  {
    title: 'Access',
    points: [
      { title: 'Organisation-scoped client workspaces', detail: 'Client project access is tied to the user’s organisation; platform administration roles are separately privileged.' },
      { title: 'Role-aware access', detail: 'Client, Admin and Super Admin responsibilities are distinguished by authenticated routes and permissions.' },
    ],
  },
  {
    title: 'Records',
    points: [
      { title: 'Recorded project activity', detail: 'Project actions, uploads, generated documents and status changes create activity or audit records where implemented.' },
      { title: 'Private project document storage', detail: 'Project documents use private application storage; public storage routing is reserved for public assets such as branding.' },
    ],
  },
  {
    title: 'Continuity',
    points: [
      { title: 'Human confirmation gate', detail: 'Extracted contract information is reviewed and confirmed before downstream workflows use it.' },
      { title: 'Documented recovery operations', detail: 'The repository includes explicit database and document backup and restore procedures for production operations.' },
    ],
  },
];

export function Security() {
  return (
    <section id="security" className="border-b border-border py-28 md:py-36">
      <Container>
        <RevealGroup>
          <div data-reveal-item className="max-w-[56ch]">
            <p className="text-sm font-medium text-text-muted">Security and procurement</p>
            <h2 className="mt-3 text-3xl font-medium tracking-tight text-text-primary">Controls we can evidence today.</h2>
            <p className="mt-4 text-text-secondary">
              These statements describe implemented safeguards visible in the product
              and repository. They do not imply a certification or legal guarantee.
            </p>
          </div>

          <div className="mt-12 grid gap-4 lg:grid-cols-[1.15fr_0.85fr_0.85fr]">
            {GROUPS.map((group, groupIndex) => (
              <article
                key={group.title}
                data-reveal-item
                className={`rounded-2xl border border-border p-6 md:p-8 ${
                  groupIndex === 0 ? 'bg-bg-surface lg:row-span-2' : 'bg-bg-base'
                }`}
              >
                <h3 className="text-sm font-medium text-text-muted">{group.title}</h3>
                <div className="mt-6 space-y-7">
                  {group.points.map((point) => (
                    <div key={point.title}>
                      <h4 className="text-base font-medium text-text-primary">{point.title}</h4>
                      <p className="mt-2 max-w-[38ch] text-sm leading-6 text-text-secondary">{point.detail}</p>
                    </div>
                  ))}
                </div>
              </article>
            ))}
          </div>

          <div data-reveal-item className="mt-8 flex justify-end border-t border-border pt-7">
            <Link href="/security" className="shrink-0 text-sm font-medium text-text-primary underline decoration-border-light underline-offset-4 hover:decoration-text-primary">
              Review security details
            </Link>
          </div>
        </RevealGroup>
      </Container>
    </section>
  );
}
