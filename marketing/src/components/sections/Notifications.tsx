import { Container } from '@/components/shared/Container';
import { MockupFrame } from '@/components/shared/MockupFrame';
import { NotificationsFeed } from '@/components/shared/placeholders';

export function Notifications() {
  return (
    <section className="border-b border-border py-20 md:py-28">
      <Container>
        <div className="grid items-center gap-16 md:grid-cols-[1.1fr_0.9fr] md:gap-20">
          <div>
            <h2 className="text-2xl font-medium tracking-tight text-text-primary md:text-3xl">
              You find out about an approaching deadline before it becomes a problem.
            </h2>
            <p className="mt-4 max-w-[46ch] text-text-secondary">
              File uploads, document generation, trade package creation, and
              approaching payment deadlines all raise a notification for the people who
              need to see them — in-app, and by email where it matters.
            </p>
          </div>

          {/* A stacked, layered composition — the real screen up front, two
              receded echoes behind it standing in for "more arriving." */}
          <div className="relative mx-auto w-full max-w-sm py-8">
            <div
              aria-hidden
              className="absolute inset-x-6 top-6 rounded-2xl border border-border bg-bg-elevated opacity-60 [transform:rotate(-3deg)]"
              style={{ height: '80%' }}
            />
            <div
              aria-hidden
              className="absolute inset-x-3 top-3 rounded-2xl border border-border bg-bg-surface opacity-80 [transform:rotate(2deg)]"
              style={{ height: '85%' }}
            />
            <div className="relative">
              <MockupFrame>
                <NotificationsFeed />
              </MockupFrame>
            </div>
          </div>
        </div>
      </Container>
    </section>
  );
}
