'use client';

import { useEffect, useRef, useState } from 'react';

interface CountUpProps {
  /** Final value to count to. */
  value: number;
  /** Animation length in ms. */
  duration?: number;
  /** Delay before the count starts, in ms (useful for staggering with cards). */
  delay?: number;
  className?: string;
  style?: React.CSSProperties;
}

const prefersReducedMotion = () =>
  typeof window !== 'undefined' &&
  window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;

// Ease-out cubic — fast start, gentle settle.
const easeOut = (t: number) => 1 - Math.pow(1 - t, 3);

/**
 * Animates a number from 0 up to `value` on mount. Re-runs if `value` changes
 * (e.g. when data loads in). Respects reduced-motion preferences.
 */
export default function CountUp({
  value,
  duration = 1000,
  delay = 0,
  className,
  style,
}: CountUpProps) {
  const [display, setDisplay] = useState(prefersReducedMotion() ? value : 0);
  const frame = useRef<number | null>(null);
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null);

  useEffect(() => {
    if (prefersReducedMotion()) {
      setDisplay(value);
      return;
    }

    let startTs: number | null = null;

    const step = (ts: number) => {
      if (startTs === null) startTs = ts;
      const progress = Math.min((ts - startTs) / duration, 1);
      setDisplay(Math.round(easeOut(progress) * value));
      if (progress < 1) frame.current = requestAnimationFrame(step);
    };

    timer.current = setTimeout(() => {
      frame.current = requestAnimationFrame(step);
    }, delay);

    return () => {
      if (frame.current) cancelAnimationFrame(frame.current);
      if (timer.current) clearTimeout(timer.current);
    };
  }, [value, duration, delay]);

  return (
    <span className={`tabular-nums ${className ?? ''}`} style={style}>
      {display.toLocaleString()}
    </span>
  );
}
