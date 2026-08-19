import { Container } from '@/components/shared/Container';
import { MockupFrame } from '@/components/shared/MockupFrame';
import { DrawingRegisterList, DrawingHotspotViewer } from '@/components/shared/placeholders';

export function Drawings() {
  return (
    <section className="border-b border-border py-28 md:py-36">
      <Container>
        <div className="mx-auto max-w-[52ch] text-center">
          <div className="text-sm font-medium uppercase tracking-wide text-text-muted">Drawings</div>
          <h2 className="mt-3 text-2xl font-medium tracking-tight text-text-primary md:text-3xl">
            One register for every sheet, with records linked to the exact spot they apply to.
          </h2>
          <p className="mx-auto mt-4 max-w-[42ch] text-text-secondary">
            Upload a drawing, track its revision and status, then drop a hotspot on the
            sheet to open the RFI, Snag, QA Report, or Variation raised at that location —
            no separate reference number to keep in sync.
          </p>
        </div>

        <div className="mx-auto mt-16 grid max-w-4xl gap-8 md:grid-cols-2 md:items-start">
          <MockupFrame caption="The drawing register, filterable by discipline and status.">
            <DrawingRegisterList />
          </MockupFrame>
          <MockupFrame caption="Records linked straight to a location on the sheet.">
            <DrawingHotspotViewer />
          </MockupFrame>
        </div>
      </Container>
    </section>
  );
}
