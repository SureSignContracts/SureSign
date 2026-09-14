'use client';

import { useEffect, useRef } from 'react';
import Link from 'next/link';
import { Camera, ShieldCheck, CalendarCheck } from 'lucide-react';
import { Container } from '@/components/shared/Container';
import { RevealGroup } from '@/components/shared/RevealGroup';
import { getGsap } from '@/lib/gsap';
import { useReducedMotion } from '@/lib/useReducedMotion';

const CAPABILITIES = [
  {
    icon: Camera,
    title: 'Site Reports',
    detail:
      'Capture workforce, works carried out, materials and site issues, with photographs and evidence attached the same day.',
  },
  {
    icon: ShieldCheck,
    title: 'Health & Safety',
    detail:
      'Toolbox Talks, Site Inductions, Incidents, H&S Inspections, Plant & Equipment and Statutory Inspections, kept with the project.',
  },
  {
    icon: CalendarCheck,
    title: 'Friday Packs',
    detail:
      'Bring the week’s records into one structured, reviewed weekly report, available on Professional and Enterprise plans.',
  },
];

export function ProjectRecords() {
  const gridRef = useRef<HTMLDivElement>(null);
  const reduced = useReducedMotion();

  useEffect(() => {
    if (reduced || !gridRef.current) return;
    const { gsap } = getGsap();
    const ctx = gsap.context(() => {
      const icons = gsap.utils.toArray<HTMLElement>('[data-capability-icon]');
      gsap.set(icons, { autoAlpha: 0, scale: 0.6 });

      // Icons land left to right, a beat after their own card fades in via
      // RevealGroup — Site Reports, then Health & Safety, then Friday
      // Packs, the same order the flow of records actually happens in.
      const tl = gsap.timeline({
        scrollTrigger: { trigger: gridRef.current, start: 'top 78%', once: true },
        delay: 0.2,
      });
      tl.to(icons, { autoAlpha: 1, scale: 1, duration: 0.4, stagger: 0.16, ease: 'back.out(1.8)' });
    }, gridRef);

    return () => ctx.revert();
  }, [reduced]);

  return (
    <section aria-labelledby="project-records-title" className="border-b border-border py-24 md:py-32">
      <Container>
        <RevealGroup>
          <div data-reveal-item className="mx-auto max-w-[52ch] text-center">
            <p className="text-sm font-medium text-text-muted">Site records &amp; reporting</p>
            <h2 id="project-records-title" className="mt-3 text-3xl font-medium tracking-tight text-text-primary md:text-4xl">
              The site record, connected to the same project as the contract.
            </h2>
            <p className="mx-auto mt-5 max-w-[46ch] text-text-secondary">
              Daily site activity and Health &amp; Safety records feed straight into
              structured weekly reporting, without re-entering anything by hand.
            </p>
          </div>

          <div data-reveal-item ref={gridRef} className="mx-auto mt-14 grid max-w-4xl gap-8 sm:grid-cols-3">
            {CAPABILITIES.map(({ icon: Icon, title, detail }) => (
              <div key={title} className="rounded-2xl border border-border bg-bg-surface p-6">
                <span data-capability-icon className="inline-flex">
                  <Icon size={20} strokeWidth={1.6} className="text-text-primary" aria-hidden="true" />
                </span>
                <h3 className="mt-4 text-base font-medium text-text-primary">{title}</h3>
                <p className="mt-2 text-sm leading-6 text-text-secondary">{detail}</p>
              </div>
            ))}
          </div>

          <div data-reveal-item className="mt-10 text-center">
            <Link href="/product" className="text-sm font-medium text-text-primary underline decoration-border-light underline-offset-4 hover:decoration-text-primary">
              Explore site records &amp; reporting in detail
            </Link>
          </div>
        </RevealGroup>
      </Container>
    </section>
  );
}
