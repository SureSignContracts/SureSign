'use client';

import { useEffect, useRef } from 'react';
import { getGsap } from '@/lib/gsap';
import { useReducedMotion } from '@/lib/useReducedMotion';

const STEPS = [
  { title: 'Upload Contract', detail: 'Drop in the executed contract as a PDF, DOCX, or TXT file.' },
  { title: 'Automated Contract Analysis', detail: 'Parties, key dates, payment rules, and programme milestones are extracted automatically.' },
  { title: 'Review & Confirm', detail: 'A human reviews the extraction and confirms it before anything downstream trusts it.' },
  { title: 'Generate Trade Packages', detail: 'Standard folders, package codes, and subcontract documents are created in bulk.' },
  { title: 'Commercial Management', detail: 'Payment applications draw on the confirmed contract data from day one.' },
  { title: 'Issue Statutory Notices', detail: 'Due dates, payment notices, and pay less notices are calculated, not guessed.' },
  { title: 'Track Programme', detail: 'Milestones seeded from the confirmed analysis, kept on one project calendar.' },
  { title: 'Manage Risks', detail: 'Risks and delay events tracked against the same contract or trade package.' },
  { title: 'Final Account', detail: 'Everything that happened on the project rolls up into one final account.' },
  { title: 'Project Complete', detail: 'One audit trail, from the first upload to the last payment.' },
];

export function HowItWorksTimeline() {
  const ref = useRef<HTMLDivElement>(null);
  const reduced = useReducedMotion();

  useEffect(() => {
    if (reduced || !ref.current) return;
    const { gsap, ScrollTrigger } = getGsap();
    const ctx = gsap.context(() => {
      const steps = gsap.utils.toArray<HTMLElement>('[data-step]');
      steps.forEach((step) => {
        const dot = step.querySelector('[data-dot]');
        gsap.fromTo(
          step,
          { opacity: 0.35 },
          {
            opacity: 1,
            duration: 0.3,
            scrollTrigger: { trigger: step, start: 'top 65%', end: 'top 40%', scrub: 0.3 },
          }
        );
        if (dot) {
          gsap.fromTo(
            dot,
            { scale: 0 },
            {
              scale: 1,
              duration: 0.3,
              scrollTrigger: { trigger: step, start: 'top 65%', end: 'top 40%', scrub: 0.3 },
            }
          );
        }
      });
      ScrollTrigger.refresh();
    }, ref);

    return () => ctx.revert();
  }, [reduced]);

  return (
    <div ref={ref} className="md:pl-8">
      <ol>
        {STEPS.map((step, i) => (
          <li key={step.title} data-step className="flex gap-5">
            {/* Dot + connecting line share one flex column, so they can never drift out of alignment with each other. */}
            <div className="hidden flex-col items-center md:flex">
              <span className="flex h-[15px] w-[15px] shrink-0 items-center justify-center rounded-full border-2 border-text-primary bg-bg-base">
                <span data-dot className="h-[7px] w-[7px] scale-0 rounded-full bg-text-primary" />
              </span>
              {i < STEPS.length - 1 && <span className="w-px flex-1 bg-border" style={{ minHeight: '2.5rem' }} />}
            </div>
            <div className="pb-10">
              <div className="font-mono text-xs text-text-muted">{String(i + 1).padStart(2, '0')}</div>
              <div className="mt-1 text-lg font-medium text-text-primary">{step.title}</div>
              <p className="mt-1 max-w-[52ch] text-sm text-text-secondary">{step.detail}</p>
            </div>
          </li>
        ))}
      </ol>
    </div>
  );
}
