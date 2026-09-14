'use client';

import { useEffect, useRef } from 'react';
import Link from 'next/link';
import { Container } from '@/components/shared/Container';
import { MockupFrame } from '@/components/shared/MockupFrame';
import { FridayPackPreview } from '@/components/shared/placeholders';
import { RevealGroup } from '@/components/shared/RevealGroup';
import { getGsap } from '@/lib/gsap';
import { useReducedMotion } from '@/lib/useReducedMotion';

export function FridayPacks() {
  const mockupRef = useRef<HTMLDivElement>(null);
  const reduced = useReducedMotion();

  useEffect(() => {
    if (reduced || !mockupRef.current) return;
    const { gsap } = getGsap();
    const ctx = gsap.context(() => {
      const sections = gsap.utils.toArray<HTMLElement>('[data-pack-section]');
      const draft = mockupRef.current!.querySelector<HTMLElement>('[data-pack-status="draft"]');
      const ready = mockupRef.current!.querySelector<HTMLElement>('[data-pack-status="ready"]');
      gsap.set(sections, { autoAlpha: 0, y: 8 });

      // The week's sections compile in one at a time, then the pack's own
      // status confirms it has moved from Draft to Ready for Review — a
      // real workflow step, shown once, never a looping "auto-generating"
      // effect.
      const tl = gsap.timeline({
        scrollTrigger: { trigger: mockupRef.current, start: 'top 70%', once: true },
      });
      tl.to(sections, { autoAlpha: 1, y: 0, duration: 0.4, stagger: 0.07, ease: 'power2.out' });

      if (draft && ready) {
        gsap.set(draft, { autoAlpha: 1 });
        gsap.set(ready, { autoAlpha: 0 });
        tl.to(draft, { autoAlpha: 0, duration: 0.35, ease: 'power1.inOut' }, '+=0.5')
          .to(ready, { autoAlpha: 1, duration: 0.35, ease: 'power1.inOut' }, '<');
      }
    }, mockupRef);

    return () => ctx.revert();
  }, [reduced]);

  return (
    <section className="tone-surface border-b border-border py-28 md:py-36">
      <Container>
        <RevealGroup>
          <div data-reveal-item className="mx-auto max-w-[52ch] text-center">
            <div className="text-sm font-medium uppercase tracking-wide text-text-muted">Weekly Reporting</div>
            <h2 className="mt-3 text-3xl font-medium tracking-tight text-text-primary md:text-4xl">
              Bring the week&apos;s project records together in one structured Friday Pack.
            </h2>
            <p className="mx-auto mt-5 max-w-[46ch] text-text-secondary">
              Compile workforce, progress photographs, Health &amp; Safety information,
              materials, project issues and next week&apos;s look ahead into a
              professional weekly report, built from the same project records already
              maintained in SureSign.
            </p>
            <p className="mx-auto mt-4 max-w-[44ch] text-sm leading-6 text-text-muted">
              Review, approve and issue the pack as a branded PDF, the same way each
              week. The project team stays responsible for what goes into it.
            </p>
          </div>

          <div data-reveal-item ref={mockupRef} className="mx-auto mt-16 max-w-2xl">
            <MockupFrame caption="A Friday Pack, compiled from the week's own project records.">
              <FridayPackPreview />
            </MockupFrame>
          </div>

          <p data-reveal-item className="mt-10 text-center text-sm text-text-muted">
            Available on Professional and Enterprise plans.{' '}
            <Link href="/pricing/compare" className="font-medium text-text-primary underline decoration-border-light underline-offset-4 hover:decoration-text-primary">
              Compare plans
            </Link>
          </p>
        </RevealGroup>
      </Container>
    </section>
  );
}
