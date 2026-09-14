'use client';

import { useEffect, useRef } from 'react';
import { Container } from '@/components/shared/Container';
import { MockupFrame } from '@/components/shared/MockupFrame';
import { HsRecordsPanel } from '@/components/shared/placeholders';
import { RevealGroup } from '@/components/shared/RevealGroup';
import { getGsap } from '@/lib/gsap';
import { useReducedMotion } from '@/lib/useReducedMotion';

const MODULES = [
  'Toolbox Talks', 'Site Inductions', 'Incidents',
  'H&S Inspections', 'Plant & Equipment', 'Statutory Inspections',
];

export function HealthAndSafety() {
  const mockupRef = useRef<HTMLDivElement>(null);
  const reduced = useReducedMotion();

  useEffect(() => {
    if (reduced || !mockupRef.current) return;
    const { gsap } = getGsap();
    const ctx = gsap.context(() => {
      const rows = gsap.utils.toArray<HTMLElement>('[data-hs-row]');
      const statuses = gsap.utils.toArray<HTMLElement>('[data-hs-status]');
      gsap.set(rows, { autoAlpha: 0, x: -8 });
      gsap.set(statuses, { autoAlpha: 0, scale: 0.9 });

      // Each record settles onto the panel, its own status confirming
      // a beat later — one row at a time, never all six at once.
      const tl = gsap.timeline({
        scrollTrigger: { trigger: mockupRef.current, start: 'top 75%', once: true },
      });
      rows.forEach((row, i) => {
        tl.to(row, { autoAlpha: 1, x: 0, duration: 0.4, ease: 'power2.out' }, i * 0.16)
          .to(statuses[i], { autoAlpha: 1, scale: 1, duration: 0.3, ease: 'back.out(1.6)' }, i * 0.16 + 0.18);
      });
    }, mockupRef);

    return () => ctx.revert();
  }, [reduced]);

  return (
    <section className="border-b border-border py-28 md:py-36">
      <Container>
        <RevealGroup className="grid items-center gap-14 md:grid-cols-[1.1fr_0.9fr] md:gap-20">
          <div data-reveal-item ref={mockupRef} className="order-2 md:order-1">
            <MockupFrame caption="Each Health & Safety record type, kept separately.">
              <HsRecordsPanel />
            </MockupFrame>
          </div>
          <div data-reveal-item className="order-1 md:order-2">
            <div className="text-sm font-medium uppercase tracking-wide text-text-muted">Health & Safety</div>
            <h2 className="mt-3 text-3xl font-medium tracking-tight text-text-primary md:text-4xl">
              Keep Health & Safety records in the same project environment as everything else.
            </h2>
            <p className="mt-5 max-w-[46ch] text-text-secondary">
              Toolbox Talks, Site Inductions, Incidents, H&S Inspections, Plant
              & Equipment, and Statutory Inspections are each their own record
              type, connected to the project they belong to rather than kept apart
              from contract administration and reporting.
            </p>
            <p className="mt-4 max-w-[46ch] text-sm leading-6 text-text-muted">
              SureSign organises and retains these records; it does not certify
              compliance or replace your team&apos;s own statutory duties.
            </p>
            <ul className="mt-6 grid grid-cols-2 gap-x-6 gap-y-2 text-sm text-text-secondary">
              {MODULES.map((m) => (
                <li key={m} className="flex items-center gap-2">
                  <span className="h-1 w-1 shrink-0 rounded-full bg-text-muted" />
                  {m}
                </li>
              ))}
            </ul>
          </div>
        </RevealGroup>
      </Container>
    </section>
  );
}
