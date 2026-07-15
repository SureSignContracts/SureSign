'use client';

import { useEffect, useRef } from 'react';
import { getGsap } from '@/lib/gsap';
import { useReducedMotion } from '@/lib/useReducedMotion';

export function HeroReveal({ children }: { children: React.ReactNode }) {
  const ref = useRef<HTMLDivElement>(null);
  const reduced = useReducedMotion();

  useEffect(() => {
    if (reduced || !ref.current) return;
    const { gsap } = getGsap();
    const targets = ref.current.querySelectorAll('[data-reveal]');
    gsap.set(targets, { opacity: 0, y: 16 });
    gsap.to(targets, {
      opacity: 1,
      y: 0,
      duration: 0.7,
      ease: 'power2.out',
      stagger: 0.08,
      delay: 0.1,
    });
  }, [reduced]);

  return <div ref={ref}>{children}</div>;
}
