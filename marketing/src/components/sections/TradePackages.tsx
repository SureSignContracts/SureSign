import { Container } from '@/components/shared/Container';
import { MockupFrame } from '@/components/shared/MockupFrame';
import { TradePackageTree } from '@/components/shared/placeholders';

export function TradePackages() {
  return (
    <section className="border-b border-border py-28 md:py-36">
      <Container>
        <div className="mx-auto max-w-[52ch] text-center">
          <div className="text-sm font-medium uppercase tracking-wide text-text-muted">Trade Packages</div>
          <h2 className="mt-3 text-2xl font-medium tracking-tight text-text-primary md:text-3xl">
            Groundworks, brickwork, M&amp;E — generated in bulk, not built by hand.
          </h2>
          <p className="mx-auto mt-4 max-w-[42ch] text-text-secondary">
            Each package gets a code, a reference, and nine standard sub-folders the
            moment it&apos;s created — independent of the main contract, but still part of
            the same project record.
          </p>
        </div>

        <div className="mx-auto mt-16 max-w-3xl">
          <MockupFrame
            elevated
            annotations={[{ label: '9 folders, every time', position: 'bottom-left' }]}
            caption="Trade packages for a live project, each with 9 standard sub-folders."
          >
            <TradePackageTree />
          </MockupFrame>
        </div>
      </Container>
    </section>
  );
}
