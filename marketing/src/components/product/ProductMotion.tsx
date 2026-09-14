'use client';

import { useEffect, useRef, type ReactNode } from 'react';
import { getGsap } from '@/lib/gsap';

/** Page-only motion; existing animations inside workflow previews keep their ownership. */
export function ProductMotion({ children }: { children: ReactNode }) {
  const root = useRef<HTMLDivElement>(null);

  useEffect(() => {
    const { gsap } = getGsap();
    const media = gsap.matchMedia();

    media.add('(prefers-reduced-motion: no-preference)', () => {
      const intro = gsap.timeline({ defaults: { ease: 'power3.out' } });
      intro.from('[data-product-intro]', {
        y: 28, opacity: 0, duration: 0.85, stagger: 0.12,
        clearProps: 'transform,opacity',
      }).from('[data-product-step]', {
        y: 24, opacity: 0, duration: 0.65, stagger: 0.13,
        clearProps: 'transform,opacity',
      }, '-=0.45').from('[data-product-line]', {
        scaleX: 0, transformOrigin: 'left', duration: 0.9,
      }, '<');

      gsap.utils.toArray<HTMLElement>('[data-product-chapter]').forEach((chapter) => {
        // Translate the outer section only: nested preview timelines animate their own nodes.
        gsap.from(chapter, {
          y: 36, duration: 0.85, ease: 'power3.out', clearProps: 'transform',
          scrollTrigger: { trigger: chapter, start: 'top 90%', once: true },
        });
        const heading = chapter.querySelector('h2');
        if (heading) {
          gsap.from(heading, {
            opacity: 0.25, x: -16, duration: 0.8, ease: 'power2.out',
            clearProps: 'transform,opacity',
            scrollTrigger: { trigger: heading, start: 'top 92%', once: true },
          });
        }
      });
    }, root);

    return () => media.revert();
  }, []);

  return <div ref={root} className="overflow-clip">{children}</div>;
}
