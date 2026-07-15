'use client';

import { useEffect, useRef } from 'react';
import { getGsap } from '@/lib/gsap';
import { useReducedMotion } from '@/lib/useReducedMotion';

const CHAOS = ['Contract', 'Excel', 'Email', 'Word', 'Shared Drive', 'Missed Notice', 'Commercial Risk'];

export function ProblemChainAnimation() {
  const ref = useRef<HTMLDivElement>(null);
  const reduced = useReducedMotion();

  useEffect(() => {
    if (reduced || !ref.current) return;
    const { gsap, ScrollTrigger } = getGsap();
    const ctx = gsap.context(() => {
      const steps = gsap.utils.toArray<HTMLElement>('[data-chaos-step]');
      const resolved = ref.current!.querySelector('[data-resolved]');
      gsap.set(steps, { opacity: 0.25 });
      gsap.set(resolved, { opacity: 0, y: 12 });

      const tl = gsap.timeline({
        scrollTrigger: {
          trigger: ref.current,
          start: 'top 70%',
          end: 'bottom 60%',
          scrub: 0.4,
        },
      });

      steps.forEach((step) => {
        tl.to(step, { opacity: 1, duration: 0.3 }, '+=0.05');
      });
      tl.to(resolved, { opacity: 1, y: 0, duration: 0.4 }, '+=0.1');
    }, ref);

    return () => ctx.revert();
  }, [reduced]);

  return (
    <div ref={ref}>
      <div className="flex flex-wrap items-center gap-x-3 gap-y-4 text-lg font-medium text-text-primary md:text-xl">
        {CHAOS.map((step, i) => (
          <span key={step} className="flex items-center gap-3" data-chaos-step>
            <span className={i >= CHAOS.length - 2 ? 'text-text-primary' : ''}>{step}</span>
            {i < CHAOS.length - 1 && <span className="text-text-muted">→</span>}
          </span>
        ))}
      </div>

      <div
        data-resolved
        className="mt-10 flex items-center gap-3 border-t border-border pt-8 text-2xl font-medium tracking-tight text-text-primary md:text-3xl"
      >
        <span>SureSign</span>
        <span className="text-text-muted">→</span>
        <span>Everything Connected</span>
      </div>
    </div>
  );
}
