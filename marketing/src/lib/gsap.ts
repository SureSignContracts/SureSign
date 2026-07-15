'use client';

import { gsap } from 'gsap';
import { ScrollTrigger } from 'gsap/ScrollTrigger';

/**
 * Single registration point for GSAP + the free ScrollTrigger plugin.
 * No paid Club GSAP plugins (DrawSVG/MorphSVG) — draw-on-scroll effects
 * use manual stroke-dashoffset animation instead, per approved plan.
 */
let registered = false;

export function getGsap() {
  if (!registered && typeof window !== 'undefined') {
    gsap.registerPlugin(ScrollTrigger);
    registered = true;
  }
  return { gsap, ScrollTrigger };
}
