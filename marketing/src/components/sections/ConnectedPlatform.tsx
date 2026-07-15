import { Container } from '@/components/shared/Container';
import { ConnectedPlatformDiagram } from '@/components/sections/ConnectedPlatformDiagram';

export function ConnectedPlatform() {
  return (
    <section id="connected-platform" className="bg-atmosphere relative border-b border-border py-44 md:py-64">
      <Container>
        <div className="mx-auto max-w-[46ch] text-center">
          <div className="text-sm font-medium uppercase tracking-wide text-text-muted">Connected Platform</div>
          <h2 className="mt-4 text-4xl font-medium tracking-tighter text-text-primary md:text-6xl">
            This isn&apos;t eight modules. It&apos;s one workflow.
          </h2>
          <p className="mt-6 text-text-secondary">
            Every module reads from and writes to the same confirmed contract data —
            the same audit trail, the same document register, the same project record.
          </p>
        </div>

        <div className="mt-28 md:mt-36">
          <ConnectedPlatformDiagram />
        </div>

        <p className="mx-auto mt-10 max-w-[36ch] text-center text-xs text-text-muted">
          Hover or focus a module to trace its connection back to the contract.
        </p>
      </Container>
    </section>
  );
}
