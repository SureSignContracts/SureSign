'use client';

import { useEffect, useRef } from 'react';
import { getGsap } from '@/lib/gsap';
import { useReducedMotion } from '@/lib/useReducedMotion';

const CHECKS = ['Parties', 'Dates', 'Payment Rules', 'Risks', 'Obligations'];

// Hardcoded light colours throughout — this represents a real product
// screenshot, which doesn't invert when the marketing site's theme toggle
// flips to dark.
export function AiEngineSequence() {
  const ref = useRef<HTMLDivElement>(null);
  const reduced = useReducedMotion();

  useEffect(() => {
    if (reduced || !ref.current) return;
    const { gsap } = getGsap();
    const ctx = gsap.context(() => {
      const checks = gsap.utils.toArray<HTMLElement>('[data-check]');
      gsap.set(checks, { opacity: 0.2 });
      const tl = gsap.timeline({
        scrollTrigger: { trigger: ref.current, start: 'top 65%', once: true },
      });
      checks.forEach((check) => {
        tl.to(check, { opacity: 1, duration: 0.25 }, '+=0.15');
      });
    }, ref);
    return () => ctx.revert();
  }, [reduced]);

  return (
    <div ref={ref} className="relative">
      <div aria-hidden className="absolute inset-0 translate-x-2 translate-y-2 rounded-2xl border border-[#e4e4e4] bg-[#f4f4f4]" />
      <div className="relative overflow-hidden rounded-2xl border border-[#e4e4e4] bg-white shadow-[var(--shadow-deep)]">
        <div className="flex items-center gap-1.5 border-b border-[#e4e4e4] bg-[#f4f4f4] px-4 py-3">
          <span className="h-2.5 w-2.5 rounded-full bg-[#d4d4d4]" />
          <span className="h-2.5 w-2.5 rounded-full bg-[#d4d4d4]" />
          <span className="h-2.5 w-2.5 rounded-full bg-[#d4d4d4]" />
          <span className="ml-2 font-mono text-[11px] text-[#8a8a8a]">contract-analysis</span>
        </div>
        <div className="p-7 font-mono text-sm">
          <div className="text-[#8a8a8a]">$ contract-analysis run --file contract.pdf</div>
          <div className="mt-4 text-[#525252]">Uploading contract...</div>
          <div className="mt-1 text-[#525252]">Analysing...</div>
          <div className="mt-4 text-[#0a0a0a]">Extracting</div>
          <ul className="mt-2 space-y-2">
            {CHECKS.map((check) => (
              <li key={check} data-check className="flex items-center gap-2.5 text-[#0a0a0a]">
                <span>✓</span>
                <span>{check}</span>
              </li>
            ))}
          </ul>
          <div className="mt-5 border-t border-[#e4e4e4] pt-5 text-[#525252]">Review &amp; Confirm</div>
          <div className="mt-1 text-[#0a0a0a]">Platform Ready</div>
        </div>
      </div>
    </div>
  );
}
