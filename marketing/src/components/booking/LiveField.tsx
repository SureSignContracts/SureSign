'use client';

import { useEffect, useRef } from 'react';
import { getGsap } from '@/lib/gsap';
import { useReducedMotion } from '@/lib/useReducedMotion';

/** Fades a value in place whenever it changes — used for the live booking summary. */
export function LiveField({ value, children }: { value: string; children: React.ReactNode }) {
  const ref = useRef<HTMLDivElement>(null);
  const reduced = useReducedMotion();

  useEffect(() => {
    if (reduced || !ref.current) return;
    const { gsap } = getGsap();
    gsap.fromTo(ref.current, { opacity: 0, y: 4 }, { opacity: 1, y: 0, duration: 0.35, ease: 'power2.out' });
  }, [value, reduced]);

  return <div ref={ref}>{children}</div>;
}
