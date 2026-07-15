'use client';

import { useEffect, useRef } from 'react';
import { getGsap } from '@/lib/gsap';
import { useReducedMotion } from '@/lib/useReducedMotion';

const STAGES = [
  { title: 'Payment Application', detail: 'Submitted for a valuation period, pre-filled from the last certified application.', date: '01 Mar 2026' },
  { title: 'Due Date', detail: 'Calculated from the confirmed contract rules — not entered by hand.', date: '08 Mar 2026' },
  { title: 'Payment Notice', detail: 'Issued against the application, standalone and auditable.', date: '15 Mar 2026' },
  { title: 'Pay Less Notice', detail: 'Raised against the application where the certified sum is disputed.', date: '20 Mar 2026' },
  { title: 'Final Date for Payment', detail: 'The statutory deadline the whole chain is protecting.', date: '27 Mar 2026' },
  { title: 'Paid', detail: 'Retention, withdrawal, and cancellation are all tracked against the same record.', date: '27 Mar 2026' },
];

export function StatutoryChain() {
  const ref = useRef<HTMLDivElement>(null);
  const reduced = useReducedMotion();

  useEffect(() => {
    if (reduced || !ref.current) return;
    const { gsap } = getGsap();
    const ctx = gsap.context(() => {
      const items = gsap.utils.toArray<HTMLElement>('[data-stage]');
      const lines = gsap.utils.toArray<HTMLElement>('[data-stage-line]');
      gsap.set(items, { opacity: 0.25 });
      gsap.set(lines, { scaleY: 0, transformOrigin: 'top' });

      const tl = gsap.timeline({
        scrollTrigger: { trigger: ref.current, start: 'top 70%', end: 'bottom 60%', scrub: 0.4 },
      });

      items.forEach((item, i) => {
        tl.to(item, { opacity: 1, duration: 0.3 }, '+=0.05');
        if (lines[i]) tl.to(lines[i], { scaleY: 1, duration: 0.3 }, '<');
      });
    }, ref);

    return () => ctx.revert();
  }, [reduced]);

  return (
    <div ref={ref}>
      <ol className="space-y-0">
        {STAGES.map((stage, i) => (
          <li key={stage.title} data-stage className="flex gap-6">
            <div className="flex flex-col items-center">
              <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-border-light font-mono text-xs text-text-secondary">
                {String(i + 1).padStart(2, '0')}
              </span>
              {i < STAGES.length - 1 && (
                <span data-stage-line className="w-px flex-1 bg-border-light" style={{ minHeight: '2.75rem' }} />
              )}
            </div>
            <div className={`flex flex-1 items-start justify-between gap-4 pb-10 pt-1.5 ${i < STAGES.length - 1 ? 'border-b border-border/70 pb-9' : ''}`}>
              <div>
                <div className="text-lg font-medium tracking-tight text-text-primary">{stage.title}</div>
                <p className="mt-1.5 max-w-[42ch] text-sm text-text-secondary">{stage.detail}</p>
              </div>
              <span className="shrink-0 whitespace-nowrap pt-1 font-mono text-xs text-text-muted">{stage.date}</span>
            </div>
          </li>
        ))}
      </ol>
    </div>
  );
}
