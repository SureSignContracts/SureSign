'use client';

import { useEffect, useRef } from 'react';
import Image from 'next/image';
import { getGsap } from '@/lib/gsap';
import { useReducedMotion } from '@/lib/useReducedMotion';
import blueprintBg from '../../../public/Blueprintbg.webp';

/**
 * SureSign's brand backdrop — the real architectural blueprint photograph,
 * not a stock substitute. Loaded via next/image with priority so it never
 * delays LCP, and drifts a few pixels on scroll for a barely-there parallax
 * cue.
 *
 * The image band is sized to the photo's own aspect ratio (1417:736), not
 * stretched across the whole (much taller) hero section — object-cover
 * inside a portrait-tall container was forcing a heavy vertical crop of a
 * wide landscape photo, which is what made it read as "zoomed in" (a
 * magnified sliver of the image, not the photo itself). Full width, true
 * proportions, no crop.
 */
export function HeroBlueprint() {
  const ref = useRef<HTMLDivElement>(null);
  const reduced = useReducedMotion();

  useEffect(() => {
    if (reduced || !ref.current) return;
    const { gsap } = getGsap();
    const ctx = gsap.context(() => {
      gsap.to(ref.current, {
        y: 40,
        ease: 'none',
        scrollTrigger: { trigger: ref.current, start: 'top top', end: 'bottom top', scrub: 1 },
      });
    }, ref);
    return () => ctx.revert();
  }, [reduced]);

  return (
    <div ref={ref} aria-hidden className="pointer-events-none absolute inset-x-0 top-0 -z-10" style={{ willChange: 'transform' }}>
      <div
        className="relative aspect-[1417/736] w-full"
        style={{
          maskImage:
            'linear-gradient(to bottom, rgba(0,0,0,0) 0%, rgba(0,0,0,0.02) 7%, rgba(0,0,0,0.09) 14%, rgba(0,0,0,0.2) 20%, rgba(0,0,0,0.36) 27%, rgba(0,0,0,0.56) 34%, rgba(0,0,0,0.81) 41%, rgba(0,0,0,1) 45%, rgba(0,0,0,1) 75%, rgba(0,0,0,0) 100%)',
          WebkitMaskImage:
            'linear-gradient(to bottom, rgba(0,0,0,0) 0%, rgba(0,0,0,0.02) 7%, rgba(0,0,0,0.09) 14%, rgba(0,0,0,0.2) 20%, rgba(0,0,0,0.36) 27%, rgba(0,0,0,0.56) 34%, rgba(0,0,0,0.81) 41%, rgba(0,0,0,1) 45%, rgba(0,0,0,1) 75%, rgba(0,0,0,0) 100%)',
        }}
      >
        <Image
          src={blueprintBg}
          alt=""
          fill
          priority
          sizes="100vw"
          className="object-cover"
          style={{ opacity: 'var(--hero-photo-opacity)' }}
        />
        {/* Extra clearing behind the headline column specifically, so type never fights the drawing. */}
        <div className="absolute inset-x-[15%] top-[10%] h-[70%] bg-[radial-gradient(ellipse_closest-side,var(--bg-base)_0%,transparent_100%)] opacity-90" />
      </div>
    </div>
  );
}
