import { Container } from '@/components/shared/Container';
import { ConnectedPlatformDiagram } from '@/components/sections/ConnectedPlatformDiagram';
import Link from 'next/link';

export function ConnectedPlatform() {
  return (
    <section id="connected-platform" className="bg-atmosphere relative border-b border-border py-28 md:py-40">
      <Container>
        <div className="mx-auto max-w-[46ch] text-center">
          <div className="text-sm font-medium uppercase tracking-wide text-text-muted">Connected Platform</div>
          <h2 className="mt-4 text-4xl font-medium tracking-tighter text-text-primary md:text-6xl">
            This isn&apos;t eight modules. It&apos;s one workflow.
          </h2>
          <p className="mt-6 text-text-secondary">
            Every module reads from and writes to the same confirmed contract data,
            the same audit trail, the same document register, the same project record.
          </p>
        </div>

        <div className="mt-20 md:mt-24">
          <ConnectedPlatformDiagram />
        </div>

        <p className="mx-auto mt-10 max-w-[36ch] text-center text-xs text-text-muted">
          Hover or focus a module to trace its connection back to the contract.
        </p>

        <div className="mt-14 text-center">
          <Link href="/product" className="text-sm font-medium text-text-primary underline decoration-border-light underline-offset-4 hover:decoration-text-primary">
            Explore the detailed product workflows
          </Link>
        </div>
      </Container>
    </section>
  );
}
