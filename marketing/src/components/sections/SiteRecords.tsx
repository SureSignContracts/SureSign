'use client';

import { useEffect, useRef } from 'react';
import { Container } from '@/components/shared/Container';
import { MockupFrame } from '@/components/shared/MockupFrame';
import { SiteReportEvidence } from '@/components/shared/placeholders';
import { RevealGroup } from '@/components/shared/RevealGroup';
import { getGsap } from '@/lib/gsap';
import { useReducedMotion } from '@/lib/useReducedMotion';

export function SiteRecords() {
  const mockupRef = useRef<HTMLDivElement>(null);
  const reduced = useReducedMotion();

  useEffect(() => {
    if (reduced || !mockupRef.current) return;
    const { gsap } = getGsap();
    const ctx = gsap.context(() => {
      const stats = gsap.utils.toArray<HTMLElement>('[data-stat-tile]');
      const photos = gsap.utils.toArray<HTMLElement>('[data-photo-tile]');
      gsap.set(stats, { autoAlpha: 0, y: 10 });
      gsap.set(photos, { autoAlpha: 0, scale: 0.85 });

      // Record → evidence: the day's figures settle in first, then the
      // photographs that back them up, mirroring how a Site Report is
      // actually filled in.
      const tl = gsap.timeline({
        scrollTrigger: { trigger: mockupRef.current, start: 'top 75%', once: true },
      });
      tl.to(stats, { autoAlpha: 1, y: 0, duration: 0.45, stagger: 0.08, ease: 'power2.out' })
        .to(photos, { autoAlpha: 1, scale: 1, duration: 0.35, stagger: 0.05, ease: 'power2.out' }, '-=0.15');
    }, mockupRef);

    return () => ctx.revert();
  }, [reduced]);

  return (
    <section className="tone-surface border-b border-border py-28 md:py-36">
      <Container>
        <RevealGroup className="grid items-center gap-14 md:grid-cols-[0.9fr_1.1fr] md:gap-20">
          <div data-reveal-item>
            <div className="text-sm font-medium uppercase tracking-wide text-text-muted">Site Reports</div>
            <h2 className="mt-3 text-3xl font-medium tracking-tight text-text-primary md:text-4xl">
              Capture site progress and supporting evidence while the work is happening.
            </h2>
            <p className="mt-5 max-w-[46ch] text-text-secondary">
              Record workforce numbers, works carried out, materials, visitors, and
              site issues or delays against the day they happened, with photographs
              and other evidence attached directly to the record.
            </p>
            <p className="mt-4 max-w-[46ch] text-sm leading-6 text-text-muted">
              Site Report evidence stays available for later reporting, so the same
              photographs and daily records can be brought into a project&apos;s
              weekly Friday Pack without uploading anything twice.
            </p>
          </div>
          <div data-reveal-item ref={mockupRef}>
            <MockupFrame caption="A Site Report, with evidence attached the same day.">
              <SiteReportEvidence />
            </MockupFrame>
          </div>
        </RevealGroup>
      </Container>
    </section>
  );
}
